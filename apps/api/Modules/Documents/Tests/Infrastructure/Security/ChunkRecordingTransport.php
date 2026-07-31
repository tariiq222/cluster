<?php

namespace Modules\Documents\Tests\Infrastructure\Security;

use Modules\Documents\Infrastructure\Security\ClamAvSocketTransport;
use Modules\Documents\Infrastructure\Security\ClamAvTransportException;
use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Test double for the streaming {@see ClamAvSocketTransport} contract. It
 * reads every chunk from the supplied reader, concatenates them into a single
 * string for assertion, and returns the configured response. It never holds
 * the chunks while the test inspects them, so callers can verify that the
 * scanner pulled chunks lazily.
 */
final class ChunkRecordingTransport implements ClamAvSocketTransport
{
    public string $response;

    /** @var list<string> */
    public array $observedChunks = [];

    public ?int $peakChunkBytes = null;

    public int $closeCount = 0;

    public bool $readerClosed = false;

    public bool $throwOnRead = false;

    public bool $throwOnClose = false;

    public string $responseAfterFirstChunk = '';

    public function __construct(string $response = 'stream: OK')
    {
        $this->response = $response;
    }

    public function instream(QuarantineByteChunkReader $reader): string
    {
        while (($chunk = $reader->readChunk()) !== null) {
            if ($this->throwOnRead) {
                throw new ClamAvTransportException('clamav_write_failed');
            }
            $this->observedChunks[] = $chunk;
            $this->peakChunkBytes = $this->peakChunkBytes === null
                ? strlen($chunk)
                : max($this->peakChunkBytes, strlen($chunk));
            if ($this->responseAfterFirstChunk !== '' && count($this->observedChunks) === 1) {
                $reader->close();
                $this->readerClosed = true;
                $this->closeCount++;

                return $this->responseAfterFirstChunk;
            }
        }
        if ($this->throwOnClose) {
            try {
                $reader->close();
            } catch (\Throwable $exception) {
                throw new ClamAvTransportException('clamav_close_failed', 0, $exception);
            }
            throw new ClamAvTransportException('clamav_close_failed');
        }
        $reader->close();
        $this->readerClosed = true;
        $this->closeCount++;

        return $this->response;
    }
}
