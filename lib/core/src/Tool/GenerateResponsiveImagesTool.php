<?php

/**
 * Produce one width variant per breakpoint, skipping sizes the source cannot fill.
 *
 * Proposal 164 §164.6 phase 2. A `srcset` is only useful if every candidate is a
 * real file at its advertised width, so the rule here is strict: a variant is
 * produced only when the source is at least that wide, and the source width is
 * read **once** before the loop rather than inferred from six encode results.
 *
 * ## Why the source width is a separate call
 *
 * Asking the engine to encode 1920px from a 400px original returns a 400px file
 * and reports success — the route uses `withoutEnlargement`. Six such calls
 * would be six wasted encodes and a `srcset` full of duplicate entries. One
 * `getInfo()` costs a round trip and saves both.
 *
 * ## `same` keeps the source encoding
 *
 * `format: "same"` means the variant keeps the source format, which is what an
 * existing pipeline expects when it only wants sizes. The default is WebP
 * because that is the format the bytes are being spent for.
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
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;

class GenerateResponsiveImagesTool extends AbstractTool
{
    /**
     * The widths the WordPress tool ships. They cover a 320px phone through a
     * 1920px display without asking for a size nobody requests.
     */
    private const DEFAULT_SIZES = array(320, 640, 768, 1024, 1366, 1920);

    /**
     * A cap on one call, so a caller cannot ask for a hundred variants.
     */
    private const MAX_VARIANTS = 10;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProductionInterface $images,
        private readonly ImageProcessingInterface $source,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_responsive_images';
    }

    public function getName(): string
    {
        return 'Generate Responsive Images';
    }

    public function getDescription(): string
    {
        return 'Generate one image variant per breakpoint width for a srcset. Variants wider than the source are skipped rather than upscaled, and the response says which were skipped and why.';
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
                    'description' => 'Stored file id of the source image.',
                    'minimum' => 1,
                ),
                'sizes' => array(
                    'type' => 'array',
                    'description' => 'Widths to generate, in pixels. Duplicates and values above the source width are skipped.',
                    'items' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 8192),
                    'default' => self::DEFAULT_SIZES,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output format. "same" keeps the source encoding.',
                    'enum' => array('same', 'webp', 'avif', 'jpg'),
                    'default' => 'webp',
                ),
                'quality' => array(
                    'type' => 'integer',
                    'description' => 'Encoder quality for every variant (1-100).',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 85,
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

        $sizes = $this->normaliseSizes($arguments);

        if (array() === $sizes) {
            return $this->errors->validationFailed(
                'At least one width is required.',
                array('sizes' => array('Give one or more positive widths, or omit the argument for the defaults.')),
            );
        }

        $format = \strtolower($this->stringParam($arguments, 'format', 'webp'));
        $quality = \max(1, \min(100, $this->intParam($arguments, 'quality', 85)));

        $sourceWidth = $this->sourceWidth($source);

        $variants = array();
        $skipped = array();

        $save = $this->boolParam($arguments, 'save_to_media', true) && null !== $this->store;
        $visibility = $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC);

        foreach ($sizes as $size) {
            if ($sourceWidth > 0 && $size > $sourceWidth) {
                $skipped[] = array(
                    'width' => $size,
                    'reason' => 'source is only ' . $sourceWidth . 'px wide; a wider variant would be an upscale',
                );
                continue;
            }

            try {
                $result = $this->images->optimize($source, array(
                    'format' => 'same' === $format ? 'original' : ($format === 'jpg' ? 'jpeg' : $format),
                    'quality' => $quality,
                    'width' => $size,
                ));

                $variant = array(
                    'requested_width' => $size,
                    'width' => $result['width'],
                    'height' => $result['height'],
                    'format' => $result['format'],
                    'mime_type' => $result['mime_type'],
                    'bytes' => $result['bytes'],
                    'savings_percent' => $result['savings_percent'],
                );

                if ($save) {
                    $variant = (new GeneratedMediaPersister($this->store))->attachFile(
                        $variant,
                        (string) $result['path'],
                        $context,
                        GeneratedMediaPersister::displayName($arguments, $context, 'w' . $size),
                        $visibility,
                        (string) $result['mime_type'],
                    );
                }

                $variants[] = $variant;
            } catch (\Throwable $e) {
                $skipped[] = array(
                    'width' => $size,
                    'reason' => $e->getMessage(),
                );
            }
        }

        if (array() === $variants) {
            return $this->errors->create(
                'no_variants',
                'No variant could be produced. The source may be narrower than every requested width.',
            );
        }

        return $this->success(
            \sprintf('%d variant(s) produced, %d skipped.', \count($variants), \count($skipped)),
            array(
                'source_width' => $sourceWidth,
                'format' => $format,
                'quality' => $quality,
                'variants' => $variants,
                'skipped' => $skipped,
            ),
        );
    }

    /**
     * The requested widths, de-duplicated and bounded.
     *
     * @param array<string, mixed> $arguments
     * @return list<int>
     */
    private function normaliseSizes(array $arguments): array
    {
        $requested = $this->intListParam($arguments, 'sizes');

        if (array() === $requested) {
            $requested = self::DEFAULT_SIZES;
        }

        $sizes = array();

        foreach ($requested as $size) {
            if ($size > 0 && $size <= 8192) {
                $sizes[$size] = $size;
            }
        }

        \sort($sizes);

        return \array_slice(\array_values($sizes), 0, self::MAX_VARIANTS);
    }

    /**
     * The source's pixel width, or 0 when the engine cannot say.
     */
    private function sourceWidth(string $source): int
    {
        try {
            $info = $this->source->getInfo($source);

            return (int) ($info['width'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
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
