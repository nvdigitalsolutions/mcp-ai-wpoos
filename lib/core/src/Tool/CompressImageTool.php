<?php

/**
 * Shrink one image, optionally to a target size in kilobytes.
 *
 * Proposal 164 §164.6 phase 2. The WordPress tool declares `method` and then
 * ignores it — `wp_get_image_editor()->save()` has no lossless mode, so a caller
 * who asked for `lossless` silently received a lossy file. This port honours the
 * distinction or refuses it by name:
 *
 *  - `standard` / `lossy` use the encoder's quality path.
 *  - `lossless` asks the worker for a lossless encode, which exists for PNG,
 *    WebP and AVIF and does not exist for JPEG. Asking for JPEG + lossless is a
 *    validation failure here rather than a quiet lie at the encoder.
 *
 * `max_size_kb` is a search, not a promise: quality steps down by five until the
 * file fits or the floor is reached, and a miss is reported as
 * `target_size_unmet` with the size that was achieved. Ten steps is the same
 * bound the WordPress tool uses, and it is the point at which further stepping
 * costs more than it saves.
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

class CompressImageTool extends AbstractTool
{
    /**
     * How far the quality may fall before a size target is called unmet.
     */
    private const QUALITY_FLOOR = 10;

    /**
     * How many encode passes a size target may cost.
     */
    private const MAX_PASSES = 10;

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
        return 'compress_image';
    }

    public function getName(): string
    {
        return 'Compress Image';
    }

    public function getDescription(): string
    {
        return 'Compress one image while preserving visual quality, with an optional target file size. `lossless` is honoured for PNG, WebP and AVIF and refused for JPEG, which has no lossless mode.';
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
                    'description' => 'Stored file id to compress. Preferred over image_url: the bytes are already on disk.',
                    'minimum' => 1,
                ),
                'quality' => array(
                    'type' => 'integer',
                    'description' => 'Compression quality (1-100). Higher preserves more detail.',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 85,
                ),
                'method' => array(
                    'type' => 'string',
                    'description' => '"standard" and "lossy" use the quality path; "lossless" needs an explicit png, webp or avif output format.',
                    'enum' => array('standard', 'lossy', 'lossless'),
                    'default' => 'standard',
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output format. "keep" preserves the source encoding. Required for lossless, except JPEG, which is refused with an explanatory error.',
                    'enum' => array('keep', 'png', 'webp', 'avif', 'jpeg'),
                    'default' => 'keep',
                ),
                'max_size_kb' => array(
                    'type' => 'integer',
                    'description' => 'Target maximum file size in kilobytes. Quality steps down until it fits; a miss is reported rather than hidden.',
                    'minimum' => 1,
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
        $method = \strtolower($this->stringParam($arguments, 'method', 'standard'));
        $format = \strtolower($this->stringParam($arguments, 'format', 'keep'));

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

        $lossless = 'lossless' === $method;

        if ($lossless) {
            if ('keep' === $format) {
                return $this->errors->validationFailed(
                    'Lossless compression needs an explicit output format.',
                    array('format' => array('Give png, webp or avif — "keep" cannot be vouched for.')),
                );
            }

            if ('jpeg' === $format) {
                return $this->errors->validationFailed(
                    'JPEG has no lossless mode.',
                    array('format' => array('Use png, webp or avif for a lossless encode.')),
                );
            }

            if (! $this->images->supports('encoding')) {
                return $this->errors->create(
                    'lossless_unavailable',
                    'The bound image engine does not support lossless encoding. Omit method, or point this runtime at a media worker that advertises image_batch_encoding.',
                );
            }
        }

        $quality = \max(1, \min(100, $this->intParam($arguments, 'quality', 85)));
        $targetKb = $this->intParam($arguments, 'max_size_kb');

        $options = array(
            'format' => 'keep' === $format ? 'original' : $format,
            'quality' => $quality,
            'lossless' => $lossless,
        );

        try {
            $result = $this->images->optimize($source, $options);
            $passes = 0;

            if ($targetKb > 0) {
                $limit = $targetKb * 1024;

                while ((int) $result['bytes'] > $limit && $quality > self::QUALITY_FLOOR && $passes < self::MAX_PASSES) {
                    $quality = \max(self::QUALITY_FLOOR, $quality - 5);
                    $options['quality'] = $quality;
                    $result = $this->images->optimize($source, $options);
                    ++$passes;
                }

                if ((int) $result['bytes'] > $limit) {
                    return $this->errors->create(
                        'target_size_unmet',
                        \sprintf(
                            'Could not reach %d KB at quality %d; the best pass produced %d KB. Lower the target, or accept this size.',
                            $targetKb,
                            $quality,
                            (int) \round(((int) $result['bytes']) / 1024),
                        ),
                    );
                }
            }

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
                'method' => $method,
                'passes' => $passes,
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'compressed-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                \sprintf('Image compressed to %d bytes (%.1f%% saved).', (int) $result['bytes'], (float) $result['savings_percent']),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('compress_failed', $e->getMessage());
        }
    }

    /**
     * Resolve the source to a local path.
     *
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
