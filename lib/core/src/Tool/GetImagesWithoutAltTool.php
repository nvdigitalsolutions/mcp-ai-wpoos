<?php

/**
 * List stored images that have no alt text.
 *
 * Proposal 164 §164.6 phase 2. Alt text is not decoration: an image without it is
 * invisible to a screen reader and to every text-only consumer of the page. The
 * audit exists so `generate_image_alt_text` can be aimed instead of run blind
 * over a whole library.
 *
 * ## Where the answer lives
 *
 * The registry stores alt text in the file's `metadata` bag under `alt_text`
 * rather than in its own column, so this reads the bag and treats a missing key,
 * an empty string and a whitespace-only string as the same thing — all three
 * render as no alt attribute.
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

class GetImagesWithoutAltTool extends AbstractTool
{
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
        return 'get_images_without_alt';
    }

    public function getName(): string
    {
        return 'Get Images Without Alt Text';
    }

    public function getDescription(): string
    {
        return 'List stored images that have no alt text, so alt text can be generated for exactly those. Read-only.';
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

        $candidates = $this->files->findByMetadata(array('mime_type' => 'image/'), self::SCAN_LIMIT);

        $found = array();

        foreach ($candidates as $file) {
            if (! $file->isImage()) {
                continue;
            }

            $alt = $file->metadata['alt_text'] ?? null;

            if (\is_string($alt) && '' !== \trim($alt)) {
                continue;
            }

            $found[] = array(
                'file_id' => $file->id,
                'filename' => $file->filename,
                'mime_type' => $file->mimeType,
                'size_bytes' => $file->sizeBytes,
                'public_url' => $file->publicUrl,
            );

            if (\count($found) >= $limit) {
                break;
            }
        }

        return $this->success(
            array() === $found
                ? 'Every image in the scanned window has alt text.'
                : \count($found) . ' image(s) without alt text.',
            array(
                'images' => $found,
                'total' => \count($found),
                'scanned' => \count($candidates),
                'limit' => $limit,
                'next_step' => array() === $found ? null : 'generate_image_alt_text',
            ),
        );
    }
}
