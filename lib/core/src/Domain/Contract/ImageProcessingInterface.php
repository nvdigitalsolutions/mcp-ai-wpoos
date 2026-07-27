<?php
/**
 * Image Processing — domain contract for server-side image manipulation.
 *
 * Abstracts GD/Imagick operations so tools never depend on WordPress
 * image editors or any framework-specific image library.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ImageProcessingInterface
{
    /**
     * Resize an image to target dimensions.
     *
     * @param string $sourcePath Absolute path to the source image.
     * @param int    $width      Target width in pixels.
     * @param int    $height     Target height in pixels.
     * @param array  $options    Optional: crop (bool), quality (int 1-100).
     *
     * @return array{path: string, width: int, height: int, mime_type: string, bytes: int}
     */
    public function resize(string $sourcePath, int $width, int $height, array $options = array()): array;

    /**
     * Crop an image to a specific region.
     *
     * @param string $sourcePath Absolute path to the source image.
     * @param int    $x          X offset from top-left.
     * @param int    $y          Y offset from top-left.
     * @param int    $width      Crop width.
     * @param int    $height     Crop height.
     *
     * @return array{path: string, width: int, height: int, mime_type: string, bytes: int}
     */
    public function crop(string $sourcePath, int $x, int $y, int $width, int $height): array;

    /**
     * Rotate an image by degrees.
     *
     * @param string $sourcePath Absolute path to the source image.
     * @param float  $angle      Rotation angle in degrees (clockwise).
     * @param string $background  Background color hex for uncovered areas. Default: '#ffffff'.
     *
     * @return array{path: string, width: int, height: int, mime_type: string, bytes: int}
     */
    public function rotate(string $sourcePath, float $angle, string $background = '#ffffff'): array;

    /**
     * Convert an image to a different format.
     *
     * @param string $sourcePath   Absolute path to the source image.
     * @param string $targetFormat Target format: 'png', 'jpeg', 'webp', 'gif'.
     * @param int    $quality      Quality for lossy formats (1-100). Default: 90.
     *
     * @return array{path: string, width: int, height: int, mime_type: string, bytes: int}
     */
    public function convert(string $sourcePath, string $targetFormat, int $quality = 90): array;

    /**
     * Get image metadata.
     *
     * @param string $sourcePath Absolute path to the source image.
     *
     * @return array{width: int, height: int, mime_type: string, bytes: int, format: string}
     */
    public function getInfo(string $sourcePath): array;

    /**
     * Whether any image processing library is available.
     */
    public function isAvailable(): bool;

    /**
     * Get list of supported output formats.
     *
     * @return string[] Format slugs: 'png', 'jpeg', 'webp', 'gif'.
     */
    public function getSupportedFormats(): array;
}
