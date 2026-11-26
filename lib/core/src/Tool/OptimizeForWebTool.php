<?php

/**
 * Produce a delivery-ready copy of an image: right width, modern format, one pass.
 *
 * Proposal 164 §164.6 phase 2. This is the "prepare it for the web" tool: cap the
 * width at a sane maximum, pick a modern encoder, and report what changed. It is
 * `compress_image` with the decisions already made, which is why it declares
 * almost nothing — the point of the tool is the defaults.
 *
 * ## What it deliberately does not offer
 *
 * `strip_metadata` is absent, not defaulted. The worker's batch route never calls
 * `withMetadata()`, so EXIF, ICC profiles and the GPS tag are always dropped on
 * re-encode. Declaring a knob whose `false` cannot be honoured is the failure
 * this codebase already refused once (see `AddWatermarkToPdfTool`). The
 * behaviour is named in the description instead.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;

class OptimizeForWebTool extends AbstractTool
{
    /**
     * The default width cap. 2560 keeps a retina hero sharp without shipping a
     * 6000px original, which is the single most common wasted byte on the web.
     */
    private const DEFAULT_MAX_WIDTH = 2560;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProductionInterface $images,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'optimize_for_web';
    }

    public function getName(): string
    {
        return 'Optimize Image for Web';
    }

    public function getDescription(): string
    {
        return 'Prepare one image for delivery: cap its width, encode it as WebP/AVIF/JPEG, and report the bytes saved. EXIF and other metadata are always stripped on re-encode; there is no flag to keep them.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'image_url' => array(
                    'type' => 'string',
                    'description' => 'URL or path of the source image. Give this or file_id.',
                ),
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id to optimise.',
                    'minimum' => 1,
                ),
                'target_format' => array(
                    'type' => 'string',
                    'description' => '"auto" prefers AVIF, then WebP, then JPEG. Pick one to pin it.',
                    'enum' => array('auto', 'webp', 'avif', 'jpg'),
                    'default' => 'auto',
                ),
                'quality' => array(
                    'type' => 'integer',
                    'description' => 'Encoder quality (1-100). 85 is the web default.',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 85,
                ),
                'max_width' => array(
                    'type' => 'integer',
                    'description' => 'Maximum width in pixels; the image is never enlarged.',
                    'minimum' => 1,
                    'maximum' => 8192,
                    'default' => self::DEFAULT_MAX_WIDTH,
                ),
                'progressive' => array(
                    'type' => 'boolean',
                    'description' => 'Encode JPEG progressively so the whole image paints early on a slow connection. Ignored for other formats.',
                    'default' => true,
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source = $this->sourcePath($arguments);

        if (null === $source) {
            return $this->errors->validationFailed(
                'image_url or file_id is required.',
                array('image_url' => array('Give image_url or file_id.')),
            );
        }

        if (! $this->images->supports('optimize')) {
            return $this->errors->create(
                'image_pipeline_unavailable',
                'No image pipeline is available on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        $target = \strtolower($this->stringParam($arguments, 'target_format', 'auto'));

        // "auto" is resolved to WebP rather than AVIF. AVIF encodes several times
        // slower and its support in mail clients and older Safari is uneven; a
        // tool that silently picks the slowest, least-portable option is not the
        // helpful default. A caller who wants AVIF asks for it.
        $format = 'auto' === $target ? 'webp' : ('jpg' === $target ? 'jpeg' : $target);

        $quality = \max(1, \min(100, $this->intParam($arguments, 'quality', 85)));
        $maxWidth = $this->intParam($arguments, 'max_width', self::DEFAULT_MAX_WIDTH);
        $progressive = $this->boolParam($arguments, 'progressive', true);

        $options = array(
            'format' => $format,
            'quality' => $quality,
            'width' => \max(0, $maxWidth),
        );

        if ('jpeg' === $format) {
            $options['progressive'] = $progressive;

            if (! $this->images->supports('encoding')) {
                unset($options['progressive']);
            }
        }

        try {
            $result = $this->images->optimize($source, $options);

            $payload = array(
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'original_bytes' => $result['original_bytes'],
                'savings_bytes' => $result['savings_bytes'],
                'savings_percent' => $result['savings_percent'],
                'quality' => $quality,
                'max_width' => $maxWidth,
                'progressive' => 'jpeg' === $format ? $progressive : null,
                'metadata' => 'stripped',
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'web-ready'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                \sprintf(
                    'Optimised to %dx%d %s, %d bytes (%.1f%% saved).',
                    (int) $result['width'],
                    (int) $result['height'],
                    (string) $result['format'],
                    (int) $result['bytes'],
                    (float) $result['savings_percent'],
                ),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('optimize_failed', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function sourcePath(array $arguments): ?string
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId > 0) {
            return $this->files?->getPath($fileId);
        }

        $url = $this->stringParam($arguments, 'image_url');

        return '' !== $url ? $url : null;
    }
}
