<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvTransportException;
use Modules\Documents\Infrastructure\Security\StreamSocketClamAvTransport;
use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;
use PHPUnit\Framework\TestCase;

/**
 * Wire-protocol tests for {@see StreamSocketClamAvTransport}. The transport
 * accepts a {@see QuarantineByteChunkReader} instead of a pre-materialised
 * string so the scanner can stream large quarantine objects without ever
 * holding the full body in PHP memory.
 *
 * <p>Each test spawns a forked child process that emulates clamd: it accepts
 * the transport's connection, drains the framed INSTREAM bytes, and replies
 * with a canned response. The parent runs the transport under test, then
 * waits for the child and reads its captured bytes from a shared file. This
 * exercises the wire-protocol exchange end to end without binding the
 * production class to a fake socket harness.
 */
final class StreamSocketClamAvTransportTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $capturePath = '';

    private ?int $childPid = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
            $this->server = null;
        }
        if ($this->childPid !== null) {
            pcntl_waitpid($this->childPid, $status);
            $this->childPid = null;
        }
        if ($this->capturePath !== '' && file_exists($this->capturePath)) {
            @unlink($this->capturePath);
        }
    }

    public function test_frames_each_chunk_with_big_endian_length_prefix(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $bytesFromServer = $transport->instream(new StaticChunkReader(['AB', 'CDE']));
        $written = $this->awaitServerCapturedBytes();
        $this->assertSame("zINSTREAM\0".pack('N', 2).'AB'.pack('N', 3).'CDE'.pack('N', 0), $written);
        $this->assertSame('stream: OK', $bytesFromServer);
    }

    public function test_returns_clean_when_response_is_stream_ok(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $response = $transport->instream(new StaticChunkReader(['bytes']));
        $this->assertSame('stream: OK', $response);
    }

    public function test_returns_infected_when_response_contains_found(): void
    {
        $port = $this->bindForkedServer("stream: Win.Test.EICAR_HDB-1 FOUND\n");
        $transport = $this->transportFor($port);
        $response = $transport->instream(new StaticChunkReader(['bytes']));
        $this->assertSame('stream: Win.Test.EICAR_HDB-1 FOUND', $response);
    }

    public function test_throws_when_socket_cannot_be_opened(): void
    {
        $transport = new StreamSocketClamAvTransport('tcp', '127.0.0.1', 1, null, 0.05, 0.05);
        $this->expectException(ClamAvTransportException::class);
        $this->expectExceptionMessage('clamav_connect_failed');
        $transport->instream(new StaticChunkReader(['x']));
    }

    public function test_throws_when_server_returns_empty_response(): void
    {
        $port = $this->bindForkedServer('');
        $transport = $this->transportFor($port);
        try {
            $transport->instream(new StaticChunkReader(['x']));
            $this->fail('expected empty-response exception');
        } catch (ClamAvTransportException $exception) {
            $this->assertSame('clamav_empty_response', $exception->getMessage());
        }
    }

    public function test_throws_when_server_response_exceeds_safety_limit(): void
    {
        $port = $this->bindForkedServer(str_repeat('A', 65537)."\n");
        $transport = $this->transportFor($port);
        try {
            $transport->instream(new StaticChunkReader(['x']));
            $this->fail('expected response-too-long exception');
        } catch (ClamAvTransportException $exception) {
            $this->assertSame('clamav_response_too_long', $exception->getMessage());
        }
    }

    public function test_throws_when_reader_throws_during_chunk_read(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $reader = new class implements QuarantineByteChunkReader
        {
            public bool $closed = false;

            public function readChunk(): ?string
            {
                throw new ClamAvTransportException('clamav_read_failed');
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        try {
            $transport->instream($reader);
            $this->fail('expected transport to surface reader failure');
        } catch (ClamAvTransportException $exception) {
            $this->assertSame('clamav_read_failed', $exception->getMessage());
        }
        $this->assertTrue($reader->closed);
    }

    public function test_closes_reader_after_successful_scan(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $reader = new StaticChunkReader(['x']);
        $transport->instream($reader);
        $this->assertTrue($reader->closed);
    }

    public function test_terminates_with_zero_length_chunk(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $transport->instream(new StaticChunkReader(['only']));
        $written = $this->awaitServerCapturedBytes();
        $this->assertStringEndsWith(pack('N', 0), $written);
    }

    public function test_reads_multiple_chunks_in_order(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $chunks = ['alpha', 'bravo', 'charlie', 'delta'];
        $transport->instream(new StaticChunkReader($chunks));
        $written = $this->awaitServerCapturedBytes();
        $expected = "zINSTREAM\0";
        foreach ($chunks as $chunk) {
            $expected .= pack('N', strlen($chunk)).$chunk;
        }
        $expected .= pack('N', 0);
        $this->assertSame($expected, $written);
    }

    public function test_skips_empty_chunks_from_reader(): void
    {
        $port = $this->bindForkedServer("stream: OK\n");
        $transport = $this->transportFor($port);
        $transport->instream(new StaticChunkReader(['', 'X', '', 'YZ']));
        $written = $this->awaitServerCapturedBytes();
        $this->assertSame("zINSTREAM\0".pack('N', 1).'X'.pack('N', 2).'YZ'.pack('N', 0), $written);
    }

    public function test_accepts_null_terminated_response_line(): void
    {
        $port = $this->bindForkedServer("stream: OK\0");
        $transport = $this->transportFor($port);
        $response = $transport->instream(new StaticChunkReader(['x']));
        $this->assertSame('stream: OK', $response);
    }

    /**
     * Spawn a forked child that emulates clamd. The child accepts one
     * connection, drains whatever the transport writes to it, replies with
     * {@code $response}, writes the captured bytes to {@code $capturePath},
     * and exits. The parent returns the bound port and reads {@code
     * $capturePath} after the transport completes.
     */
    private function bindForkedServer(string $response): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->fail("could not bind local test server: {$errstr}");
        }
        $name = stream_socket_get_name($server, false);
        if (! is_string($name)) {
            $this->fail('could not resolve bound port for test server');
        }
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $capturePath = tempnam(sys_get_temp_dir(), 'clamav-capture-');
        if ($capturePath === false) {
            $this->fail('could not allocate capture file');
        }
        @unlink($capturePath);
        $this->capturePath = $capturePath;

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('could not fork test server child');
        }
        if ($pid === 0) {
            $this->runChildServer($server, $response, $capturePath);
            exit(0);
        }
        $this->childPid = $pid;
        $this->server = $server;

        return $port;
    }

    private function runChildServer($server, string $response, string $capturePath): void
    {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(0);
        }
        $captured = '';
        stream_set_timeout($client, 1);
        while (! feof($client)) {
            $chunk = @fread($client, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $captured .= $chunk;
        }
        @file_put_contents($capturePath, $captured);
        @fwrite($client, $response);
        @fclose($client);
        @fclose($server);
        exit(0);
    }

    private function awaitServerCapturedBytes(): string
    {
        if ($this->childPid === null) {
            $this->fail('no forked server is bound');
        }
        pcntl_waitpid($this->childPid, $status);
        $this->childPid = null;
        if (! is_file($this->capturePath)) {
            return '';
        }

        return (string) file_get_contents($this->capturePath);
    }

    private function transportFor(int $port): StreamSocketClamAvTransport
    {
        return new StreamSocketClamAvTransport('tcp', '127.0.0.1', $port, null, 1.0, 5.0);
    }
}
