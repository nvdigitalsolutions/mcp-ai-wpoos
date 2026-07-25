<?php
/**
 * File Orchestration — domain contract.
 *
 * Provider-agnostic file upload, status polling, and cleanup workflow.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Orchestrates file operations across AI providers.
 *
 * @since 2.0.0
 */
interface FileOrchestrationInterface
{
    /**
     * Upload a file to the provider's API.
     *
     * @param string $filePath  Local file path.
     * @param string $mimeType  MIME type.
     * @param array  $options   Additional options (display_name, purpose, etc.).
     *
     * @return array{success: bool, file_id?: string, uri?: string, error?: string}
     */
    public function uploadFile(string $filePath, string $mimeType, array $options = []): array;

    /**
     * Poll the processing status of an uploaded file.
     *
     * @param string $fileId Provider-specific file identifier.
     *
     * @return array{status: string, progress?: float, error?: string}
     */
    public function pollStatus(string $fileId): array;

    /**
     * Delete a file from the provider's storage.
     *
     * @param string $fileId Provider-specific file identifier.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteFile(string $fileId): array;

    /**
     * Maximum number of polling attempts before giving up.
     *
     * @param int $attempts Max attempts.
     */
    public function setMaxPollingAttempts(int $attempts): void;

    /**
     * Delay between polling attempts in seconds.
     *
     * @param int $seconds Delay in seconds.
     */
    public function setPollingDelay(int $seconds): void;
}
