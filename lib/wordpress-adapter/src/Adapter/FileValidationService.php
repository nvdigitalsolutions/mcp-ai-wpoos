<?php
/**
 * WordPress Adapter — File Validation Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\FileValidationServiceInterface;

/**
 * WordPress adapter for file validation.
 *
 * @since 2.0.0
 */
final class FileValidationService implements FileValidationServiceInterface
{
    /**
     * Supported formats per purpose.
     */
    private const SUPPORTED_FORMATS = [
        'assistants'     => ['c', 'cpp', 'cs', 'css', 'doc', 'docx', 'go', 'html', 'java', 'js', 'json', 'jsonl', 'md', 'pdf', 'php', 'pptx', 'py', 'rb', 'sh', 'tex', 'ts', 'txt'],
        'fine_tuning'    => ['jsonl'],
        'batch'          => ['jsonl'],
        'image_input'    => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
    ];

    public function validateForVectorStore(string $filePath, string $purpose = 'assistants'): array
    {
        if (!\class_exists('WP_MCP_AI_File_Preprocessing_Helper')) {
            return [
                'valid'           => false,
                'warnings'        => ['File validation service unavailable.'],
                'recommendations' => [],
                'file_info'       => [],
            ];
        }

        /** @var array $result */
        $result = \WP_MCP_AI_File_Preprocessing_Helper::validate_file_for_vector_store($filePath, $purpose);

        return \is_array($result) ? $result : [
            'valid'           => false,
            'warnings'        => ['Validation returned unexpected result.'],
            'recommendations' => [],
            'file_info'       => [],
        ];
    }

    public function isFormatSupported(string $extension, string $purpose = 'assistants'): bool
    {
        $formats = self::SUPPORTED_FORMATS[$purpose] ?? self::SUPPORTED_FORMATS['assistants'];

        return \in_array(\strtolower($extension), $formats, true);
    }

    public function getSupportedFormats(string $purpose = 'assistants'): array
    {
        return self::SUPPORTED_FORMATS[$purpose] ?? self::SUPPORTED_FORMATS['assistants'];
    }
}
