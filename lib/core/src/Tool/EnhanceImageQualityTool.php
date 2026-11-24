<?php

/**
 * Sharpen, denoise, and lift contrast and saturation on one image.
 *
 * Proposal 164 §164.6 phase 2. The WordPress tool takes a list of named
 * enhancements plus a single `strength` and leaves the arithmetic to GD. This
 * port keeps the same vocabulary and maps it onto the worker's `/enhance`
 * multipliers, so the result is the same *kind* of edit in both runtimes rather
 * than two tools that happen to share a slug.
 *
 * ## `strength` scales, it does not switch on
 *
 * Every amount is expressed as a full-strength multiplier and `strength` is
 * handed to the engine as a scalar over all of them. `strength: 0` would ask for
 * nothing at all, which the route refuses, so the value is floored at 0.05 — a
 * trace of the edit is still an edit, and the caller's intent survives.
 *
 * ## `use_remote` is not offered
 *
 * The WordPress tool prefers a local Sharp subprocess and falls back to the
 * sidecar. A port has no local Sharp, so a `false` value could not be honoured;
 * the worker is the only pipeline and the description says so.
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

class EnhanceImageQualityTool extends AbstractTool
{
    /**
     * The enhancement vocabulary, shared with the WordPress tool.
     */
    private const ENHANCEMENTS = array('sharpness', 'color', 'contrast', 'denoise', 'auto');

    /**
     * Full-strength multipliers. `strength` scales all of them at once, so these
     * are what the caller gets at strength 1.0.
     */
    private const FULL_CONTRAST = 1.6;
    private const FULL_SATURATION = 1.5;

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
        return 'enhance_image_quality';
    }

    public function getName(): string
    {
        return 'Enhance Image Quality';
    }

    public function getDescription(): string
    {
        return 'Sharpen, denoise, and adjust contrast and colour on one image. Strength (0-1) scales every requested enhancement. Processing always runs on the media worker pipeline; there is no local fallback.';
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
                    'description' => 'Stored file id to enhance.',
                    'minimum' => 1,
                ),
                'enhancements' => array(
                    'type' => 'array',
                    'description' => 'Which enhancements to apply. "auto" means sharpness, contrast and colour at moderate strength.',
                    'items' => array(
                        'type' => 'string',
                        'enum' => self::ENHANCEMENTS,
                    ),
                    'default' => array('auto'),
                ),
                'strength' => array(
                    'type' => 'number',
                    'description' => 'Scales every requested enhancement, 0-1. 0.5 is a moderate lift.',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.5,
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

        if (! $this->images->supports('enhance')) {
            return $this->errors->create(
                'image_pipeline_unavailable',
                'The bound image engine cannot enhance images. Configure a media worker that advertises image_enhancement.',
            );
        }

        $requested = $this->stringListParam($arguments, 'enhancements');

        if (array() === $requested) {
            $requested = array('auto');
        }

        if (\in_array('auto', $requested, true)) {
            $requested = array('sharpness', 'contrast', 'color');
        }

        $strength = \max(0.05, \min(1.0, $this->floatParam($arguments, 'strength', 0.5)));

        $options = array('strength' => $strength);

        if (\in_array('sharpness', $requested, true)) {
            // A numeric sharpen amount is scaled by `strength` in the engine; the
            // boolean form is not, which is why an amount is sent, not `true`.
            $options['sharpen'] = 1.0;
        }

        if (\in_array('contrast', $requested, true)) {
            $options['contrast'] = self::FULL_CONTRAST;
        }

        if (\in_array('color', $requested, true)) {
            $options['saturation'] = self::FULL_SATURATION;
        }

        if (\in_array('denoise', $requested, true)) {
            $options['denoise'] = true;
        }

        try {
            $result = $this->images->enhance($source, $options);

            $payload = array(
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'original_bytes' => $result['original_bytes'],
                'enhancements' => $result['enhancements'],
                'requested' => \array_values($requested),
                'strength' => $strength,
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'enhanced-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                'Image enhanced (' . (array() === $result['enhancements'] ? 'no operations' : \implode(', ', $result['enhancements'])) . ').',
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('enhance_failed', $e->getMessage());
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
