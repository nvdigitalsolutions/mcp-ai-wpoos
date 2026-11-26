<?php

/**
 * Resumable upload — a file arrives in pieces, and a dropped connection costs one
 * piece rather than the whole transfer.
 *
 * ## Why this exists
 *
 * A single-request upload is bounded by three numbers that have nothing to do
 * with policy: PHP's `upload_max_filesize`, PHP's `post_max_size`, and whatever
 * proxy sits in front. In this runtime's shipped image they are **2 MB** and
 * **8 MB** — measured, not assumed — so before this contract a 3 MB mp3 could
 * not be uploaded at all, and the 10 MiB figure the console prints was a promise
 * PHP refused to keep. Raising the request limits would let one enormous body in
 * at a time; chunking keeps every request small and makes *total* size a separate
 * decision from *request* size.
 *
 * ## The protocol is tus 1.0.0 core
 *
 * Create, status, append, abort — `POST` / `HEAD` / `PATCH` / `DELETE` with
 * `Upload-Length`, `Upload-Offset` and `application/offset+octet-stream`. Not a
 * bespoke dialect: a client written against the tus spec (Uppy, `tus-js-client`,
 * `tusd`) works, and the rules about offsets — a mismatch is **409**, never a
 * silent append at the wrong place — are the spec's rather than ours. The HTTP
 * layer lives with the host; this contract is the state machine it calls.
 *
 * ## What this contract deliberately does not do
 *
 * No concatenation extension, no deferred length, no parallel chunks. Each of
 * them is a separate tus extension with its own consistency rules, and a resumable
 * upload that reorders bytes silently is worse than one that refuses to.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ChunkedUploadInterface
{
    /**
     * The largest total an upload may declare.
     */
    public function maxTotalBytes(): int;

    /**
     * The largest single chunk the host will accept.
     */
    public function maxChunkBytes(): int;

    /**
     * Open a session.
     *
     * @param string               $filename    Client-supplied name, kept for the stored display name.
     * @param string               $declaredMime Client's claim. Never trusted; the assembled bytes are sniffed.
     * @param int                  $totalBytes  Declared length. Fixed for the life of the session.
     * @param array<string, mixed> $context     Execution context (`user_id`, `tenant_id`).
     *
     * @return array{id: string, offset: int, total: int}
     *
     * @throws \Nvoos\Core\Domain\Error\ValidationException When the length or name is not acceptable.
     */
    public function begin(string $filename, string $declaredMime, int $totalBytes, array $context = array()): array;

    /**
     * Where the session stands, or null when it does not exist *for this caller*.
     *
     * Unknown and not-yours are the same answer on purpose: whether a session id
     * exists is not something a caller should be able to learn.
     *
     * @param array<string, mixed> $context
     *
     * @return array{offset: int, total: int, complete: bool, attachment_id: int|null, url: string|null}|null
     */
    public function status(string $uploadId, array $context = array()): ?array;

    /**
     * Append the bytes at the current offset.
     *
     * @param string               $uploadId Session id.
     * @param int                  $offset   The offset the client believes it is writing at.
     * @param string               $bytes    The chunk.
     * @param array<string, mixed> $context  Execution context.
     *
     * @return array{offset: int, total: int, complete: bool, attachment_id: int|null, url: string|null}
     *
     * @throws \Nvoos\Core\Domain\Error\NotFoundException   When the session is unknown to this caller.
     * @throws \Nvoos\Core\Domain\Error\ConflictException   When the offset is not the current one.
     * @throws \Nvoos\Core\Domain\Error\ValidationException When the chunk, the total, or the assembled file is refused.
     */
    public function append(string $uploadId, int $offset, string $bytes, array $context = array()): array;

    /**
     * Discard a session and its chunks.
     *
     * @param array<string, mixed> $context
     *
     * @return bool True when something was discarded.
     */
    public function abort(string $uploadId, array $context = array()): bool;
}
