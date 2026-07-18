<?php

namespace Modules\Documents\Infrastructure\Security;

/**
 * Production {@see ClamAvSocketTransport} backed by PHP's stream sockets. The
 * implementation streams the payload chunk-by-chunk over a fresh socket per
 * scan, never reuses a connection, and surfaces every transport failure as a
 * {@see ClamAvTransportException} so the scanner can map it to
 * {@code outcome=unavailable}.
 *
 * <p>Wire protocol reference (clamd INSTREAM):
 * <pre>
 *   client -> clamd : "zINSTREAM\0"
 *   loop:
 *     client -> clamd : uint32 big-endian chunk length
 *     client -> clamd : &lt;chunk length&gt; bytes of data
 *   terminate:
 *     client -> clamd : uint32 big-endian zero length (0,0,0,0)
 *   clamd -> client : single response line, e.g.
 *                       "stream: OK"
 *                       "stream: Win.Test.EICAR_HDB-1 FOUND"
 *                       "stream: INSTREAM size limit exceeded. ERROR"
 * </pre>
 */
final class StreamSocketClamAvTransport implements ClamAvSocketTransport
{
    public function __construct(
        private readonly string $transport,
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $unixSocket,
        private readonly float $connectTimeoutSeconds,
        private readonly float $readTimeoutSeconds,
    ) {}

    public function instream(string $payload, int $chunkBytes): string
    {
        $socket = $this->open();
        try {
            $this->writeAll($socket, "zINSTREAM\0");
            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
                $slice = substr($payload, $offset, $chunkBytes);
                $this->writeAll($socket, pack('N', strlen($slice)));
                $this->writeAll($socket, $slice);
                $offset += strlen($slice);
            }
            $this->writeAll($socket, pack('N', 0));
            $response = $this->readLine($socket);
        } finally {
            @fclose($socket);
        }
        if ($response === '') {
            throw new ClamAvTransportException('clamav_empty_response');
        }

        return $response;
    }

    /** @return resource */
    private function open()
    {
        $errno = 0;
        $errstr = '';
        if ($this->transport === 'unix') {
            $address = 'unix://'.($this->unixSocket ?? '');
            $socket = @stream_socket_client($address, $errno, $errstr, $this->connectTimeoutSeconds);
        } else {
            $address = 'tcp://'.$this->host.':'.$this->port;
            $socket = @stream_socket_client($address, $errno, $errstr, $this->connectTimeoutSeconds);
        }
        if ($socket === false) {
            throw new ClamAvTransportException("clamav_connect_failed: {$errstr}", 0);
        }
        stream_set_timeout($socket, (int) $this->readTimeoutSeconds);
        stream_set_blocking($socket, true);

        return $socket;
    }

    /** @param resource $socket */
    private function writeAll($socket, string $chunk): void
    {
        $remaining = strlen($chunk);
        $written = 0;
        while ($written < $remaining) {
            $bytes = @fwrite($socket, substr($chunk, $written));
            if ($bytes === false || $bytes === 0) {
                throw new ClamAvTransportException('clamav_write_failed');
            }
            $written += $bytes;
        }
    }

    /** @param resource $socket */
    private function readLine($socket): string
    {
        $buffer = '';
        $deadline = microtime(true) + $this->readTimeoutSeconds;
        while (true) {
            $char = @fread($socket, 1);
            if ($char === false || $char === '') {
                if (microtime(true) >= $deadline) {
                    throw new ClamAvTransportException('clamav_read_timeout');
                }
                throw new ClamAvTransportException('clamav_read_failed');
            }
            $buffer .= $char;
            if ($char === "\n" || $char === "\0") {
                return rtrim($buffer, "\r\n\0");
            }
            if (strlen($buffer) > 65536) {
                throw new ClamAvTransportException('clamav_response_too_long');
            }
        }
    }
}
