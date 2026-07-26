<?php
/**
 * WordPress adapter: ImageProcessingInterface implementation.
 *
 * Wraps WP_Image_Editor (GD or Imagick) behind the framework-agnostic
 * ImageProcessingInterface.
 *
 * @package Nvoos\WordPress
 * @since   2.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class ImageProcessing implements ImageProcessingInterface {

    private const QUALITY_MAP = array(
        'image/jpeg' => 90,
        'image/webp' => 86,
        'image/png'  => 9,  // 0-9 compression level
    );

    public function resize(string $sourcePath, int $width, int $height, array $options = array()): array {
        $editor = $this->loadEditor($sourcePath);
        $crop   = $options['crop'] ?? true;

        $result = $editor->resize($width, $height, $crop);
        if (\is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        return $this->saveAndReturn($editor, $sourcePath, $options['quality'] ?? null);
    }

    public function crop(string $sourcePath, int $x, int $y, int $width, int $height): array {
        $editor = $this->loadEditor($sourcePath);
        $result = $editor->crop($x, $y, $width, $height);
        if (\is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        return $this->saveAndReturn($editor, $sourcePath);
    }

    public function rotate(string $sourcePath, float $angle, string $background = '#ffffff'): array {
        $editor = $this->loadEditor($sourcePath);

        // Convert hex to RGB for WP_Image_Editor::rotate()
        $bg = $this->hexToRgb($background);
        $result = $editor->rotate($angle, $bg);
        if (\is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        return $this->saveAndReturn($editor, $sourcePath);
    }

    public function convert(string $sourcePath, string $targetFormat, int $quality = 90): array {
        $editor = $this->loadEditor($sourcePath);

        $mimeMap = array(
            'png'  => 'image/png',
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
        );

        $mime = $mimeMap[$targetFormat] ?? 'image/jpeg';

        if (\in_array($targetFormat, array('jpeg', 'jpg', 'webp'), true)) {
            $editor->set_quality($quality);
        }

        // Generate new filename with target extension.
        $pathInfo = \pathinfo($sourcePath);
        $newPath  = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '-converted.' . $targetFormat;

        $saved = $editor->save($newPath, $mime);
        if (\is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        return array(
            'path'      => $saved['path'],
            'width'     => $saved['width'],
            'height'    => $saved['height'],
            'mime_type' => $saved['mime-type'] ?? $mime,
            'bytes'     => \file_exists($saved['path']) ? \filesize($saved['path']) : 0,
        );
    }

    public function getInfo(string $sourcePath): array {
        if (!\file_exists($sourcePath)) {
            throw new \RuntimeException("File not found: {$sourcePath}");
        }

        $size     = \getimagesize($sourcePath);
        $bytes    = \filesize($sourcePath);
        $pathInfo = \pathinfo($sourcePath);

        return array(
            'width'     => $size[0] ?? 0,
            'height'    => $size[1] ?? 0,
            'mime_type' => $size['mime'] ?? 'application/octet-stream',
            'bytes'     => $bytes ?: 0,
            'format'    => $pathInfo['extension'] ?? 'unknown',
        );
    }

    public function isAvailable(): bool {
        return \wp_image_editor_supports() !== false;
    }

    public function getSupportedFormats(): array {
        $formats = array('jpeg', 'png', 'gif');

        if (\wp_image_editor_supports(array('mime_type' => 'image/webp'))) {
            $formats[] = 'webp';
        }

        return $formats;
    }

    // ─── Private helpers ──────────────────────────────────────────────

    private function loadEditor(string $path): \WP_Image_Editor {
        if (!\file_exists($path)) {
            throw new \RuntimeException("Image file not found: {$path}");
        }

        $editor = \wp_get_image_editor($path);
        if (\is_wp_error($editor)) {
            throw new \RuntimeException($editor->get_error_message());
        }

        return $editor;
    }

    private function saveAndReturn(\WP_Image_Editor $editor, string $sourcePath, ?int $quality = null): array {
        if (null !== $quality && \method_exists($editor, 'set_quality')) {
            $editor->set_quality(\max(1, \min(100, $quality)));
        }

        $pathInfo = \pathinfo($sourcePath);
        $ext      = $pathInfo['extension'] ?? 'jpg';
        $newPath  = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '-processed.' . $ext;

        $saved = $editor->save($newPath);
        if (\is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        return array(
            'path'      => $saved['path'],
            'width'     => $saved['width'],
            'height'    => $saved['height'],
            'mime_type' => $saved['mime-type'] ?? 'image/jpeg',
            'bytes'     => \file_exists($saved['path']) ? \filesize($saved['path']) : 0,
        );
    }

    private function hexToRgb(string $hex): array {
        $hex = \ltrim($hex, '#');
        if (3 === \strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return array(
            \hexdec(\substr($hex, 0, 2)),
            \hexdec(\substr($hex, 2, 2)),
            \hexdec(\substr($hex, 4, 2)),
        );
    }
}
