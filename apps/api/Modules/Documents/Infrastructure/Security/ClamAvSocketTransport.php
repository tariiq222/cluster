<?php

namespace Modules\Documents\Infrastructure\Security;

use Modules\Documents\Infrastructure\Storage\QuarantineByteChunkReader;

/**
 * Minimal ClamAV wire-protocol boundary. Implementations talk to clamd over
 * the configured transport; the scanner consumes the contract so unit tests
 * can exercise the framing logic without binding to a network socket.
 *
 * <p>Only the {@code INSTREAM} command is exposed. PING, VERSION, RELOAD, and
 * other administrative verbs are deliberately omitted so the scanner cannot be
 * weaponised through the production adapter.
 *
 * <p>The contract accepts a {@see QuarantineByteChunkReader} instead of a
 * pre-materialised string so the scanner never has to allocate the full
 * quarantine object in PHP memory before the first byte reaches clamd. The
 * transport pulls chunks from the reader on demand and frames each chunk with
 * a four-byte big-endian length prefix per the {@code INSTREAM} wire format.
 */
interface ClamAvSocketTransport
{
    /**
     * Open a fresh connection, write {@code zINSTREAM\0}, stream every chunk
     * read from {@code $reader} as a sequence of four-byte big-endian length
     * prefixes followed by the chunk bytes, terminate with a zero-length
     * chunk, and read the single-line response.
     *
     * @return string raw clamd response line without trailing newline.
     *
     * @throws ClamAvTransportException on transport-level errors, including
     *                                  empty response or chunk-read failures.
     */
    public function instream(QuarantineByteChunkReader $reader): string;
}
