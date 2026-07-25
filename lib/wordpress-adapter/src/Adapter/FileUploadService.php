<?php
/**
 * WordPress Adapter — File Upload Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;

/**
 * WordPress adapter for file upload operations.
 *
 * @since 2.0.0
 */
final class FileUploadService implements FileUploadServiceInterface
{
    /**
     * Maximum file size in bytes.
     */
    private int $maxFileSize;

    /**
     * Allowed MIME types.
     *
     * @var array<int, string>
     */
    private array $allowedMimeTypes;

    /**
     * Constructor.
     *
     * @param int                $maxFileSize      Maximum file size in bytes (default 10 MiB).
     * @param array<int, string> $allowedMimeTypes Allowed MIME types (default = common document/image types).
     */
    public function __construct(
        int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE,
        array $allowedMimeTypes = []
    ) {
        $this->maxFileSize      = $maxFileSize;
        $this->allowedMimeTypes = !empty($allowedMimeTypes) ? $allowedMimeTypes : self::DEFAULT_ALLOWED_TYPES;
    }

    public function validate(array $file, array $context = []): array
    {
        $errors   = [];
        $fileInfo = [
            'name' => $file['name'] ?? 'unknown',
            'size' => $file['size'] ?? 0,
            'type' => $file['type'] ?? '',
        ];

        // Check for upload errors.
        if (($file['error'] ?? \UPLOAD_ERR_OK) !== \UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code ' . ($file['error'] ?? 'unknown') . '.';
        }

        // Check file size.
        if (($file['size'] ?? 0) > $this->maxFileSize) {
            $errors[] = \sprintf(
                'File size exceeds maximum allowed size of %s.',
                \size_format($this->maxFileSize)
            );
        }

        // Check MIME type.
        $ext = \strtolower(\pathinfo($file['name'] ?? '', \PATHINFO_EXTENSION));
        $mimeType = $file['type'] ?? '';

        if ($mimeType !== '' && !\in_array($mimeType, $this->allowedMimeTypes, true)) {
            $errors[] = \sprintf('File type "%s" is not allowed.', $mimeType);
        }

        return [
            'valid'     => empty($errors),
            'errors'    => $errors,
            'file_info' => $fileInfo,
        ];
    }

    public function upload(array $file, array $context = []): array
    {
        // Validate first.
        $validation = $this->validate($file, $context);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => \implode('; ', $validation['errors']),
            ];
        }

        // Use WordPress media handling when available.
        if (\function_exists('wp_handle_upload') && \function_exists('wp_generate_attachment_metadata')) {
            if (!\function_exists('wp_handle_upload')) {
                require_once \ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!\function_exists('wp_generate_attachment_metadata')) {
                require_once \ABSPATH . 'wp-admin/includes/image.php';
            }

            $upload = \wp_handle_upload($file, ['test_form' => false]);

            if (isset($upload['error'])) {
                return [
                    'success' => false,
                    'error'   => $upload['error'],
                ];
            }

            return [
                'success' => true,
                'url'     => $upload['url'] ?? '',
                'path'    => $upload['file'] ?? '',
                'type'    => $upload['type'] ?? '',
            ];
        }

        // Fallback: return file info without WordPress.
        return [
            'success' => true,
            'path'    => $file['tmp_name'] ?? '',
            'name'    => $file['name'] ?? '',
            'size'    => $file['size'] ?? 0,
        ];
    }

    public function prepareDocument(string $filePath): array
    {
        if (!\file_exists($filePath)) {
            return ['content' => '', 'metadata' => ['error' => 'File not found']];
        }

        $content = \file_get_contents($filePath);

        return [
            'content'  => $content !== false ? $content : '',
            'metadata' => [
                'path' => $filePath,
                'size' => \filesize($filePath) ?: 0,
                'mime' => \mime_content_type($filePath) ?: 'application/octet-stream',
            ],
        ];
    }

    public function isMimeTypeAllowed(string $mimeType): bool
    {
        return \in_array($mimeType, $this->allowedMimeTypes, true);
    }
}
