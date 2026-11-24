<?php

/**
 * List stored images that are not yet in a delivery format.
 *
 * Proposal 164 §164.6 phase 2. The audit half of the image family: it names the
 * work before anyone rewrites a library. Read-only by construction — it reads
 * registry rows and returns them, and has no path to the engine at all.
 *
 * ## What "unoptimised" means here
 *
 * A judgement with a stated rule, not a guess: an image is unoptimised when its
 * MIME type is not WebP or AVIF, **or** when it is larger than `min_bytes`. Both
 * reasons are reported per file, so a caller can tell "wrong format" from
 * "simply too big" and choose the right tool. An image that is already WebP and
 * small is not listed, even if it could be re-encoded smaller — a list that
 * includes everything is a list nobody finishes.
 *
 * ## The scan is bounded on purpose
 *
 * `findByMetadata()` walks the newest thousand rows. A media library larger than
 * that needs a paginated sweep, which is a different tool; inventing a silent
 * cap and calling the result "complete" would be the wrong answer. `scanned`
 * reports how many rows were examined so the caller can tell.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

class GetUnoptimisedImagesTool extends AbstractTool
{
    /**
     * Formats that are already delivery-optimised.
     */
    private const OPTIMISED = array('image/webp', 'image/avif');

    /**
     * How many candidate rows one call will examine.
     */
    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_unoptimised_images';
    }

    public function getName(): string
    {
        return 'Get Unoptimised Images';
    }

    public function getDescription(): string
    {
        return 'List stored images that are not WebP or AVIF, or that are larger than a given size. Read-only: it names the work so a batch run can be aimed at it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum number of images to return.',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ),
                'min_bytes' => array(
                    'type' => 'integer',
                    'description' => 'Also list images at or above this size, even when already WebP or AVIF. Default 0 lists by format only.',
                    'minimum' => 0,
                    'default' => 0,
                ),
            ),
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
        return array('read-only', 'idempotent', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so there is nothing to audit.',
            );
        }

        $limit = \max(1, \min(100, $this->intParam($arguments, 'limit', 25)));
        $minBytes = \max(0, $this->intParam($arguments, 'min_bytes'));

        $candidates = $this->files->findByMetadata(array('mime_type' => 'image/'), self::SCAN_LIMIT);

        $found = array();

        foreach ($candidates as $file) {
            if (! $file->isImage()) {
                continue;
            }

            $reasons = array();

            if (! \in_array($file->mimeType, self::OPTIMISED, true)) {
                $reasons[] = 'encoding is ' . $this->label($file->mimeType) . ', not WebP or AVIF';
            }

            if ($minBytes > 0 && $file->sizeBytes >= $minBytes) {
                $reasons[] = 'size is ' . $file->sizeBytes . ' bytes, at or above the ' . $minBytes . '-byte floor';
            }

            if (array() === $reasons) {
                continue;
            }

            $found[] = array(
                'file_id' => $file->id,
                'filename' => $file->filename,
                'mime_type' => $file->mimeType,
                'size_bytes' => $file->sizeBytes,
                'width' => isset($file->metadata['width']) ? (int) $file->metadata['width'] : null,
                'height' => isset($file->metadata['height']) ? (int) $file->metadata['height'] : null,
                'reasons' => $reasons,
            );

            if (\count($found) >= $limit) {
                break;
            }
        }

        return $this->success(
            array() === $found
                ? 'No unoptimised images found in the scanned window.'
                : \count($found) . ' unoptimised image(s) found.',
            array(
                'images' => $found,
                'total' => \count($found),
                'scanned' => \count($candidates),
                'limit' => $limit,
                'min_bytes' => $minBytes,
                'next_step' => array() === $found ? null : 'optimise_images_batch',
            ),
        );
    }

    /**
     * A short format name for a reason string.
     */
    private function label(string $mimeType): string
    {
        $slug = \strtolower(\preg_replace('#^image/#', '', $mimeType) ?? $mimeType);

        return match ($slug) {
            'jpeg', 'jpg' => 'JPEG',
            'png' => 'PNG',
            'gif' => 'GIF',
            'svg+xml' => 'SVG',
            default => '' === $slug ? 'unknown' : \strtoupper($slug),
        };
    }
}
