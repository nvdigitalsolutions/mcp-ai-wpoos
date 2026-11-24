<?php

/**
 * Enlarge an image by 2x, 4x or 8x with a resampling kernel.
 *
 * Proposal 164 §164.6 phase 2. The slug is the WordPress slug, but the honest
 * description is not the WordPress description: no super-resolution model is
 * involved. This resamples with Lanczos3 (or cubic/nearest), and the payload
 * says `method: lanczos3` so a caller can tell what it actually received.
 *
 * ## Why `model` and `denoise` are not offered
 *
 * The WordPress tool declares both and then ignores them — its own description
 * says so. A port that repeats that would teach an orchestrator that passing
 * `model: anime` changes the output when it does not. Omitting the knobs is the
 * clearer contract: `kernel` is the only resampling control, and denoising is
 * `enhance_image_quality`'s job, where it is real.
 *
 * The engine also refuses a result above its pixel cap rather than allocating
 * it, which surfaces here as `upscale_failed` with the engine's reason.
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

class UpscaleImageAiTool extends AbstractTool
{
    /**
     * The factors the engine allows. Each doubles the pixel count by 4, so the
     * cap is reached quickly on a large source.
     */
    private const FACTORS = array(2, 4, 8);

    /**
     * Kernels the engine honours, best-quality first.
     */
    private const KERNELS = array('lanczos3', 'cubic', 'nearest');

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
        return 'upscale_image_ai';
    }

    public function getName(): string
    {
        return 'Upscale Image';
    }

    public function getDescription(): string
    {
        return 'Enlarge an image by 2x, 4x or 8x using a resampling kernel (Lanczos3 by default). This is not a super-resolution model: detail is interpolated, not invented. The response names the method used.';
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
                    'description' => 'Stored file id to upscale.',
                    'minimum' => 1,
                ),
                'scale_factor' => array(
                    'type' => 'number',
                    'description' => 'Enlargement factor: 2, 4 or 8. The result must stay under the engine\'s pixel cap.',
                    'enum' => self::FACTORS,
                    'default' => 2,
                ),
                'kernel' => array(
                    'type' => 'string',
                    'description' => 'Resampling kernel. Lanczos3 is sharpest; nearest is fastest and blockiest.',
                    'enum' => self::KERNELS,
                    'default' => 'lanczos3',
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

        // Arguments are validated before the engine is asked. A caller that
        // typed 3 has made a mistake the host can name for free; spending a
        // capability probe (and possibly a network round trip) to discover it
        // would report the same mistake as "no pipeline available".
        $factor = $this->intParam($arguments, 'scale_factor', 2);

        if (! \in_array($factor, self::FACTORS, true)) {
            return $this->errors->validationFailed(
                'The scale factor must be 2, 4 or 8.',
                array('scale_factor' => array('Allowed: 2, 4, 8.')),
            );
        }

        $kernel = \strtolower($this->stringParam($arguments, 'kernel', 'lanczos3'));

        if (! \in_array($kernel, self::KERNELS, true)) {
            $kernel = 'lanczos3';
        }

        if (! $this->images->supports('upscale')) {
            return $this->errors->create(
                'image_pipeline_unavailable',
                'The bound image engine cannot upscale images. Configure a media worker that advertises image_upscaling.',
            );
        }

        try {
            $result = $this->images->upscale($source, $factor, $kernel);

            $payload = array(
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'original_bytes' => $result['original_bytes'],
                'factor' => $result['factor'],
                'kernel' => $result['kernel'],
                'method' => $result['method'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'upscaled-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                \sprintf('Upscaled %dx with %s to %dx%d.', $factor, $result['method'], (int) $result['width'], (int) $result['height']),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('upscale_failed', $e->getMessage());
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
