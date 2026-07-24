<?php
/**
 * File Validation Service — domain contract.
 *
 * Validates files for vector store / AI processing suitability.
 * Checks format, encoding, size, and provides actionable recommendations.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Validates files before upload to AI services.
 *
 * @since 2.0.0
 */
interface FileValidationServiceInterface
{
    /**
     * Validate a file for vector store upload.
     *
     * @param string $filePath Absolute path to file.
     * @param string $purpose   Upload purpose (assistants, fine-tuning, etc.).
     *
     * @return array{valid: bool, warnings: array<int, string>, recommendations: array<int, string>, file_info: array}
     */
    public function validateForVectorStore(string $filePath, string $purpose = 'assistants'): array;

    /**
     * Check whether a file extension is suitable for the given purpose.
     *
     * @param string $extension File extension (without dot).
     * @param string $purpose   Upload purpose.
     *
     * @return bool
     */
    public function isFormatSupported(string $extension, string $purpose = 'assistants'): bool;

    /**
     * Get the list of supported file extensions for a purpose.
     *
     * @param string $purpose Upload purpose.
     *
     * @return array<int, string>
     */
    public function getSupportedFormats(string $purpose = 'assistants'): array;
}
