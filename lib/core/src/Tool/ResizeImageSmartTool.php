<?php

/**
 * Resize to exact dimensions with a content-aware crop.
 *
 * Proposal 164 §164.47. The WordPress tool with this slug advertises four modes
 * (`crop`, `fit`, `fill`, `smart`) and a `focus_area` that includes `face`, but
 * `execute()` collapses `fill` and `smart` onto the same plain
 * `resize( $w, $h, false )` call and never reads `focus_area` at all. The
 * docblock promises seam carving; the code does a bilinear fit.
 *
 * This port keeps the capability and drops the fiction. There is one behaviour —
 * cover-crop to exact dimensions — and the caller chooses *where* the crop
 * centres from a vocabulary the engine actually honours. Plain crop and fit stay
 * on `resize_image`, which has always been the right tool for them.
 *
 * ## What "smart" means here
 *
 * Sharp's `attention` strategy is the smartcrop algorithm: an edge-and-skin-tone
 * saliency map picks the busiest region instead of the geometric centre. It is
 * **not** face detection and it is **not** seam carving, and the description
 * says so, because a caller that believes either will mis-read a result it
 * cannot inspect.
 *
 * ## One dimension is enough
 *
 * The WordPress usage notes promise that the missing dimension is derived from
 * the aspect ratio, and that is worth keeping: an agent asked for "800px wide"
 * should not have to know the source height. Deriving needs the source size, so
 * it goes through `ImageProcessingInterface::getInfo()`. A host with no geometry
 * engine bound refuses by name rather than guessing a square.
 *
 * @package Nvoos\Core
 * @since   2.6.0
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

class ResizeImageSmartTool extends AbstractTool
{
    /**
     * The positions `ImageProductionInterface::resizeSmart()` documents.
     *
     * Only the contract's seven, not the adapter's extra corner aliases: a host
     * that implements the contract is not obliged to know `top_left`, and a
     * schema that offers it would be a promise this runtime cannot make portably.
     */
    private const POSITIONS = array('attention', 'entropy', 'centre', 'top', 'bottom', 'left', 'right');

    /**
     * `ImageProcessingInterface` is optional so the tool is constructible on a
     * host that only bound the production half; it is then only needed when the
     * caller gives one dimension and expects the other derived.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ImageProductionInterface $images,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?FileVisibilityStoreInterface $store = null,
        private readonly ?ImageProcessingInterface $geometry = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'resize_image_smart';
    }

    public function getName(): string
    {
        return 'Resize Image (Smart)';
    }

    public function getDescription(): string
    {
        return 'Resize to exact dimensions, cropping around the busiest region instead of the centre. "Smart" is a saliency heuristic (edge and skin-tone attention), not face detection and not seam carving. For a plain fit or a centre crop, use resize_image.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id to resize. Preferred over image_url: the bytes are already on disk.',
                    'minimum' => 1,
                ),
                'image_url' => array(
                    'type' => 'string',
                    'description' => 'Path or URL of the source image. Give this or file_id.',
                ),
                'width' => array(
                    'type' => 'integer',
                    'description' => 'Target width in pixels. Give this or height; the other is derived from the source aspect ratio.',
                    'minimum' => 1,
                ),
                'height' => array(
                    'type' => 'integer',
                    'description' => 'Target height in pixels. Give this or width; the other is derived from the source aspect ratio.',
                    'minimum' => 1,
                ),
                'position' => array(
                    'type' => 'string',
                    'description' => 'Where to centre the crop. "attention" (saliency), "entropy" (most detail), or a fixed edge. Default: attention.',
                    'enum' => self::POSITIONS,
                    'default' => 'attention',
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

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source = $this->sourcePath($arguments);
        $width = $this->intParam($arguments, 'width');
        $height = $this->intParam($arguments, 'height');

        if (null === $source || ($width <= 0 && $height <= 0)) {
            return $this->errors->validationFailed(
                'A source (file_id or image_url) and at least one dimension are required.',
                array(
                    'file_id' => array('Give file_id or image_url.'),
                    'width' => array('Give width, height, or both.'),
                ),
            );
        }

        $position = $this->stringParam($arguments, 'position', 'attention');

        if (! \in_array($position, self::POSITIONS, true)) {
            return $this->errors->validationFailed(
                \sprintf('Unknown position "%s".', $position),
                array('position' => array('Use one of: ' . \implode(', ', self::POSITIONS) . '.')),
            );
        }

        $derived = null;

        try {
            if ($width <= 0 || $height <= 0) {
                $derived = $width <= 0 ? 'width' : 'height';
                $sized = $this->derive($source, $width, $height);

                if (null === $sized) {
                    return $this->errors->create(
                        'no_dimension_source',
                        'The missing dimension could not be derived: no image geometry engine is bound and the source size is unknown. Pass both width and height.',
                    );
                }

                [$width, $height] = $sized;
            }

            if (! $this->images->supports('resize_smart')) {
                return $this->errors->create(
                    'image_pipeline_unavailable',
                    'The bound image engine cannot crop by attention. Configure a media worker that advertises image_smart_crop.',
                );
            }

            $result = $this->images->resizeSmart($source, $width, $height, $position);

            $payload = array(
                'width' => $result['width'],
                'height' => $result['height'],
                'format' => $result['format'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'original_bytes' => $result['original_bytes'],
                'position' => $result['position'],
            );

            if (null !== $derived) {
                $payload['derived'] = $derived;
            }

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'smart-crop'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                \sprintf('Resized to %dx%d around "%s".', $result['width'], $result['height'], $result['position']),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('resize_smart_failed', $e->getMessage());
        }
    }

    /**
     * Fill in the dimension the caller left out, preserving the aspect ratio.
     *
     * Returns null when there is no geometry engine to ask or the engine reports
     * a zero edge, because a divide-by-zero is not a derivation.
     *
     * @return array{0: int, 1: int}|null
     */
    private function derive(string $source, int $width, int $height): ?array
    {
        if (null === $this->geometry) {
            return null;
        }

        $info = $this->geometry->getInfo($source);
        $sourceWidth = (int) ($info['width'] ?? 0);
        $sourceHeight = (int) ($info['height'] ?? 0);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return null;
        }

        if ($width <= 0) {
            $width = \max(1, (int) \round($height / $sourceHeight * $sourceWidth));
        } else {
            $height = \max(1, (int) \round($width / $sourceWidth * $sourceHeight));
        }

        return array($width, $height);
    }

    /**
     * Resolve the source to a path the engine can open.
     *
     * `file_id` is the chain primitive — a stored file already has bytes on
     * disk, so a resize can follow a generation without a re-upload. A URL is
     * accepted for parity, but the engine takes a *path*, and the WordPress
     * base class resolves one too, so a remote URL fails the same way in both
     * runtimes rather than quietly meaning something else.
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
