<?php

namespace Modules\Documents\Infrastructure\Security;

/**
 * Minimal ClamAV wire-protocol boundary. Implementations talk to clamd over
 * the configured transport; the scanner consumes the contract so unit tests
 * can exercise the framing logic without binding to a network socket.
 *
 * <p>Only the {@code INSTREAM} command is exposed. PING, VERSION, RELOAD, and
 * other administrative verbs are deliberately omitted so the scanner cannot be
 * weaponised through the production adapter.
 */
interface ClamAvSocketTransport
{
    /**
     * Open a fresh connection, write {@code zINSTREAM\0}, stream every byte
     * of {@code $payload} as a sequence of four-byte big-endian length
     * prefixes followed by the chunk, terminate with a zero-length chunk, and
     * read the single-line response.
     *
     * @return string raw clamd response line without trailing newline.
     *
     * @throws ClamAvTransportException on transport-level errors.
     */
    public function instream(string $payload, int $chunkBytes): string;
}
