<?php

/**
 * Persist provider-generated media into the host's file registry.
 *
 * A generation tool has two jobs and only ever did the first: call the provider,
 * and return what it said. The bytes a model produces are not a file until some
 * layer gives them an id, an owner, a visibility and a place to live — until
 * then they exist only in the response payload, and a caller who wants the image
 * tomorrow has to keep a provider URL that expires or re-generate and pay again.
 *
 * WordPress never had that gap because `wp_insert_attachment()` is the last line
 * of its generation tools: the image becomes a media-library row. This runtime
 * now has the equivalent row (§162), so the missing half is a single place that
 * turns payload bytes into that row rather than one copy of the logic per tool.
 *
 * Four rules make it safe to put in front of a paid API call:
 *
 *  - **Bytes only.** It stores base64 payload data. Fetching the provider's
 *    temporary URL would mean a second network hop to a link the caller does not
 *    control; the tools ask for bytes instead when they intend to keep them.
 *  - **Never throw.** A generation that succeeded must not be reported as failed
 *    because a disk was full. Every refusal and every storage error is collected
 *    per item and returned alongside the successes.
 *  - **The store decides the type.** The MIME hint is only a hint; the registry
 *    sniffs the bytes and refuses what it cannot serve (§162.7). This class does
 *    not repeat that policy.
 *  - **A relationship is reported, not thrown.** When a caller says what the
 *    file was made from, the link is recorded after the bytes land (§164.46).
 *    If the host advertises the capability and the write fails, the payload says
 *    so — an unlinked poster is one the inventory asks for again, and "stored
 *    but unlinked" is a different fact from "stored". A host that does not
 *    advertise the capability is silent here on purpose: it cannot link anything,
 *    and a warning nobody can action on every generated poster is noise. The
 *    inventory tools are where that absence is named.
 *
 * Two provider dialects are understood, because normalising them here is what
 * keeps the tools thin: OpenAI's images API returns `b64_json`, and Google's
 * Imagen (via `:predict`) returns `bytesBase64Encoded`.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

final class GeneratedMediaPersister
{
    /** Payload keys that carry base64 bytes, newest dialect first. */
    private const BYTE_KEYS = array('b64_json', 'bytesBase64Encoded');

    public function __construct(private readonly FileVisibilityStoreInterface $files)
    {
    }

    /**
     * The three parameters every generation tool shares.
     *
     * They live here rather than in each tool so five tools cannot drift on what
     * `save_to_media` promises. A tool merges them into its own properties.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function parameters(): array
    {
        return array(
            'save_to_media' => array(
                'type' => 'boolean',
                'description' => 'Store the generated image(s) in the media library and return their ids, slugs and stable URLs. Defaults to true when the host has a file registry.',
                'default' => true,
            ),
            'file_name' => array(
                'type' => 'string',
                'description' => 'Optional base file name for the saved image.',
            ),
            'visibility' => array(
                'type' => 'string',
                'description' => 'Visibility of the stored image. "private" is an extension over the WordPress tools: the bytes are given no public URL.',
                'enum' => array(
                    FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                    FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                ),
                'default' => FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            ),
        );
    }

    /**
     * The saved image's base name, mirroring the WordPress tools.
     *
     * WordPress names the attachment `{prefix}-{job_id}` when the caller supplies
     * a job, and `{file_name|prefix}-{Ymd-His}` otherwise. The extension is left
     * to the store, because the bytes decide the type.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     * @param string               $prefix    Per-tool fallback stem.
     */
    public static function displayName(array $arguments, array $context, string $prefix): string
    {
        $job = \trim((string) ($context['parent_job_id'] ?? ''));

        if ('' !== $job) {
            return $prefix . '-' . self::stem($job, $prefix);
        }

        $requested = \trim((string) ($arguments['file_name'] ?? ''));

        return self::stem($requested, $prefix) . '-' . \gmdate('Ymd-His');
    }

    /**
     * Persist the items and merge the result into a tool payload.
     *
     * @param array<string, mixed>            $payload    The tool's success payload.
     * @param array<int, array<string, mixed>> $items     Provider entries.
     * @param array<string, mixed>            $context    Execution context.
     * @param string                          $baseName   Display name; the store makes it unique.
     * @param string                          $visibility One of the VISIBILITY_* constants.
     * @param string                          $mimeHint   Declared type, verified against the bytes.
     *
     * @return array<string, mixed> The payload, with `media` and `media_failed` when relevant.
     */
    public function attach(
        array $payload,
        array $items,
        array $context,
        string $baseName,
        string $visibility,
        string $mimeHint = 'image/png',
    ): array {
        if (array() === $items) {
            return $payload;
        }

        $persisted = $this->persist($items, $context, $visibility, $baseName, $mimeHint);

        $payload['media'] = $persisted['stored'];

        if (array() !== $persisted['failed']) {
            // The generation succeeded; the storage did not. Both facts are
            // reported so a caller does not lose paid work silently.
            $payload['media_failed'] = $persisted['failed'];
        }

        return $payload;
    }

    /**
     * Store each generated item, collecting per-item failures instead of throwing.
     *
     * @param array<int, array<string, mixed>> $items      Provider entries.
     * @param array<string, mixed>             $context    Execution context (`user_id`, `tenant_id`).
     * @param string                           $visibility One of the VISIBILITY_* constants.
     * @param string                           $baseName   Display name; the store makes it unique.
     * @param string                           $mimeHint   Declared type, verified against the bytes.
     *
     * @return array{stored: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public function persist(
        array $items,
        array $context,
        string $visibility,
        string $baseName,
        string $mimeHint,
    ): array {
        $owner  = (int) ($context['user_id'] ?? $context['owner_id'] ?? 0);
        $tenant = $this->tenantFrom($context);
        $stored = array();
        $failed = array();

        foreach (array_values($items) as $index => $item) {
            $data = $this->bytesOf($item);

            if ('' === $data) {
                $failed[$index] = 'The provider returned no base64 bytes to store.';
                continue;
            }

            $bytes = \base64_decode($data, true);

            if (false === $bytes || '' === $bytes) {
                $failed[$index] = 'The provider returned malformed base64.';
                continue;
            }

            $path = $this->writeTemp($bytes);

            if (null === $path) {
                $failed[$index] = 'Could not write a temporary file for the generated bytes.';
                continue;
            }

            try {
                $file = $this->files->storeWithVisibility(
                    $path,
                    $baseName,
                    $mimeHint,
                    $owner,
                    $visibility,
                    $tenant,
                );

                $stored[$index] = $this->entry($file);
            } catch (\Throwable $error) {
                // A refusal and a disk error are different problems, but both are
                // reported the same way: the generation survives and the store's
                // reason is passed through verbatim.
                $failed[$index] = $error->getMessage();
            } finally {
                if (\is_file($path)) {
                    \unlink($path);
                }
            }
        }

        return array('stored' => $stored, 'failed' => $failed);
    }

    /**
     * Store one already-local file and return its payload entry.
     *
     * A transform hands over a path because the image processor has already
     * written the bytes; routing them through base64 would encode and decode
     * the same file twice for nothing.
     *
     * @param array<string, mixed> $context    Execution context.
     * @param string               $visibility One of the VISIBILITY_* constants.
     * @param string               $baseName   Display name; the store makes it unique.
     * @param string               $mimeHint   Declared type, verified against the bytes.
     *
     * @return array{media: array<string, mixed>|null, error: string|null}
     */
    public function storeFile(
        string $localPath,
        array $context,
        string $visibility,
        string $baseName,
        string $mimeHint,
    ): array {
        if (! \is_file($localPath)) {
            return array( 'media' => null, 'error' => "There is no file to store at {$localPath}." );
        }

        $owner  = (int) ($context['user_id'] ?? $context['owner_id'] ?? 0);
        $tenant = $this->tenantFrom($context);

        try {
            return array(
                'media' => $this->entry(
                    $this->files->storeWithVisibility($localPath, $baseName, $mimeHint, $owner, $visibility, $tenant),
                ),
                'error' => null,
            );
        } catch (\Throwable $error) {
            return array( 'media' => null, 'error' => $error->getMessage() );
        }
    }

    /**
     * Persist a single local file and merge the result into a tool payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param string               $baseName
     * @param string               $visibility
     * @param string               $mimeHint
     * @param MediaDerivative|null $relation   What this file was made from, when the
     *                                         caller knows. Null records nothing, which is
     *                                         the normal case for a URL input.
     *
     * @return array<string, mixed>
     */
    public function attachFile(
        array $payload,
        string $localPath,
        array $context,
        string $baseName,
        string $visibility,
        string $mimeHint = 'image/png',
        ?MediaDerivative $relation = null,
    ): array {
        $outcome = $this->storeFile($localPath, $context, $visibility, $baseName, $mimeHint);

        if (null !== $outcome['media']) {
            $payload['media'] = array( $outcome['media'] );

            if (null !== $relation) {
                $failure = $this->link((int) ( $outcome['media']['attachment_id'] ?? 0 ), $relation);

                if (null !== $failure) {
                    $payload['media_relationship_failed'] = array( $failure );
                }
            }
        }

        if (null !== $outcome['error']) {
            $payload['media_failed'] = array( $outcome['error'] );
        }

        return $payload;
    }

    /**
     * Record the derivative relationship, or say why it could not be.
     *
     * The file is already stored and already reported, so this never throws: the
     * relationship is secondary to the bytes.
     *
     * A host without the capability records nothing and is *not* told off. It
     * cannot link anything, so a permanent, unfixable warning on every generated
     * poster is noise; the tools that need the relationship refuse by name
     * instead, which is a place an operator can act on. A host that *has* the
     * capability and fails to write is different: that is a real loss, and the
     * payload reports it.
     *
     * @return string|null The reason it could not be recorded, or null on success
     *                     or when the host does not advertise the capability.
     */
    private function link(int $fileId, MediaDerivative $relation): ?string
    {
        if (!$this->files instanceof FileDerivativeStoreInterface) {
            return null;
        }

        try {
            $this->files->recordDerivative($fileId, $relation->sourceFileId, $relation->role);
        } catch (\Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * The payload shape every stored file is reported as.
     *
     * @return array<string, mixed>
     */
    private function entry(StoredFile $file): array
    {
        return array(
            'attachment_id' => $file->id,
            'source_url'    => $file->publicUrl,
            'slug'          => $file->slug,
            'filename'      => $file->filename,
            'mime_type'     => $file->mimeType,
            'size_bytes'    => $file->sizeBytes,
            'visibility'    => $file->visibility,
        );
    }

    /**
     * Read the base64 payload from either dialect.
     *
     * @param array<string, mixed> $item Provider entry.
     */
    private function bytesOf(array $item): string
    {
        foreach (self::BYTE_KEYS as $key) {
            if (isset($item[$key]) && \is_string($item[$key]) && '' !== $item[$key]) {
                return $item[$key];
            }
        }

        return '';
    }

    /**
     * Write the bytes somewhere the store can read them from.
     *
     * The store's contract takes a local path, so decoding straight into storage
     * would need a second contract. A temp file is the cheap bridge — and it is
     * removed in `finally`, so a refusal cannot leave the bytes behind.
     */
    private function writeTemp(string $bytes): ?string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-media-');

        if (false === $path) {
            return null;
        }

        if (\strlen($bytes) !== \file_put_contents($path, $bytes)) {
            \unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private static function stem(string $value, string $fallback): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return '' !== $stem ? $stem : $fallback;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function tenantFrom(array $context): ?string
    {
        $tenant = \trim((string) ($context['tenant_id'] ?? ''));

        return '' !== $tenant ? $tenant : null;
    }
}
