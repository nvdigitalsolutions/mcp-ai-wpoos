<?php
/**
 * File Upload Service — domain contract.
 *
 * Handles file upload validation, processing, and document preparation
 * for AI provider consumption.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
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
     */
    public const DEFAULT_MAX_FILE_SIZE = 10485760;

    /**
     * Default allowed MIME types.
     */
    public const DEFAULT_ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/json',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Validate a file upload before processing.
     *
     * @param array{name: string, type: string, size: int, tmp_name: string, error: int} $file    File data.
     * @param array{assistant_id?: int, user_id?: int}                                    $context Upload context.
     *
     * @return array{valid: bool, errors: array<int, string>, file_info: array}
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
     * @return array{content: string, metadata: array}
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
