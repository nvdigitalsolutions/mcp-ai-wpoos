<?php

/**
 * File Upload Service — domain contract.
 *
 * Handles file upload validation, processing, and document preparation
 * for AI provider consumption.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Processes and validates file uploads for AI services.
 *
 * @since 2.0.0
 */
interface FileUploadServiceInterface
{
    /**
     * Default maximum file size (10 MiB).
     *
     * A **request-size safety limit**, not the per-type policy: it applies to every
     * upload before the type is known, and an operator raises it through
     * `oos.upload_max_bytes`. The hard ceiling is still the registry's per-group
     * cap (`FileStore::DEFAULT_LIMITS` — audio 100 MiB, video 512 MiB) and, above
     * that, PHP's own `upload_max_filesize` and `post_max_size`.
     */
    public const DEFAULT_MAX_FILE_SIZE = 10485760;

    /**
     * Default allowed MIME types.
     *
     * Two rules hold this list together:
     *
     *  - **Every entry must be storable by the registry.** A type a caller may
     *    pick but `FileStore` refuses is a half-wired feature: validation passes,
     *    then the write fails with "File type is not accepted". `FileUploadAllowlistTest`
     *    asserts the allowlist is a subset of `FileStore::acceptedTypes()`.
     *  - **Audio and video are listed by every sniffed type, not by family.**
     *    libmagic answers `audio/x-m4a` for an m4a and `audio/x-hx-aac-adts` for
     *    an ADTS AAC — neither is `audio/mp4` or `audio/aac` — so a family wildcard
     *    here would let a file through validation that the registry then refuses.
     */
    public const DEFAULT_ALLOWED_TYPES = [
        // Images.
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        // Documents.
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/json',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        // Audio. An audio file is a first-class library asset: the transcription
        // tools resolve one by id, and `extract_audio` produces mp3/m4a/aac.
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/x-m4a',
        'audio/mp4',
        'audio/x-hx-aac-adts',
        'audio/aac',
        'audio/flac',
        'audio/webm',
        // Video. Stored and served like any other media; ffmpeg work happens on
        // the media worker from a stored id, so the upload only has to carry bytes.
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];

    /**
     * Validate a file upload before processing.
     *
     * @param array{name: string, type: string, size: int, tmp_name: string, error: int} $file    File data.
     * @param array{assistant_id?: int, user_id?: int}                                    $context Upload context.
     *
     * @return array{valid: bool, errors: array<int, string>, file_info: array<string, mixed>}
     */
    public function validate(array $file, array $context = []): array;

    /**
     * Process a file upload and return the stored file information.
     *
     * @param array{name: string, type: string, size: int, tmp_name: string, error: int} $file    File data.
     * @param array{assistant_id?: int, user_id?: int}                                    $context Upload context.
     *
     * @return array{success: bool, attachment_id?: int, url?: string, path?: string, error?: string}
     */
    public function upload(array $file, array $context = []): array;

    /**
     * Prepare a local file as a memory document for AI processing.
     *
     * @param string $filePath Absolute path to the file.
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    public function prepareDocument(string $filePath): array;

    /**
     * Check whether a MIME type is allowed.
     *
     * @param string $mimeType MIME type to check.
     *
     * @return bool
     */
    public function isMimeTypeAllowed(string $mimeType): bool;
}
