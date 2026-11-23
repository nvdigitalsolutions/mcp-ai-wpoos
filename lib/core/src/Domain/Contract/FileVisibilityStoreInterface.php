<?php

/**
 * Optional file-store capability: write at a chosen visibility.
 *
 * Why a second interface instead of a parameter on `FileStoreInterface::store()`:
 * `nvoos/core` is a published package. Adding a parameter to an existing
 * interface method is not additive for implementers — every class implementing
 * `store($a, $b, $c, $d)` stops satisfying `store($a, $b, $c, $d, $e = null)`
 * and the application fatals on load. A separate contract lets a host opt in
 * without breaking any adapter that does not.
 *
 * The two visibilities are the ones object storage itself draws:
 *
 *  - `public`  — reachable at a stable URL without authorisation. Safe only for
 *                content the deployment has decided the world may read.
 *  - `private` — never given a public URL. Reading it requires an authorisation
 *                check on every request.
 *
 * Nothing about *where* the bytes live is promised here. An adapter may put both
 * visibilities on one bucket behind different prefixes, or on two disks; the
 * contract is about access, not layout.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\ValidationException;

interface FileVisibilityStoreInterface
{
    /** Reachable at a stable URL. */
    public const VISIBILITY_PUBLIC = 'public';

    /** Requires an authorisation check on every read. */
    public const VISIBILITY_PRIVATE = 'private';

    /**
     * Store a file from a local filesystem path at the requested visibility.
     *
     * The adapter moves or copies the bytes to permanent storage, records the
     * file, and returns its metadata. The returned id is the store's own durable
     * identifier — it must resolve back through `getMetadata()`.
     *
     * @param string      $localPath  Absolute path to the source file on disk.
     * @param string      $filename   Desired display filename (never used as a path).
     * @param string      $mimeType   Declared MIME type; the adapter verifies it against the bytes.
     * @param int         $userId     User who owns the file.
     * @param string      $visibility One of the VISIBILITY_* constants.
     * @param string|null $tenantId   Owning estate, when the host has estates.
     *
     * @throws ValidationException When the type, size or visibility is not acceptable.
     */
    public function storeWithVisibility(
        string $localPath,
        string $filename,
        string $mimeType,
        int $userId,
        string $visibility,
        ?string $tenantId = null,
    ): StoredFile;

    /**
     * Whether a value names a visibility this adapter accepts.
     */
    public function supportsVisibility(string $visibility): bool;
}
