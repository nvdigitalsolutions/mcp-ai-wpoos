<?php

/**
 * Add colour to a black-and-white photograph with a model.
 *
 * Proposal 164 §164.6 phase 2. The worker's `/api/image/edit` route owns the
 * provider choice and the default prompt; this tool owns the *vocabulary* the
 * WordPress tool exposed — `auto`, `vibrant`, `subtle` — and expresses it as a
 * prompt qualifier rather than a model parameter, because no provider in the
 * route accepts a saturation target for colourisation.
 *
 * ## A missing provider is a named refusal
 *
 * Colourisation is the one image operation here that cannot be done
 * deterministically. When no key resolves the tool answers
 * `no_image_provider` and says which variable to set, instead of returning a
 * greyscale copy and calling it done.
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

class ColorizeImageTool extends AbstractTool
{
    /**
     * Mode => the instruction appended to the provider prompt.
     *
     * `auto` sends nothing, so the worker's own default prompt applies — that is
     * what "let the model decide" has to mean when the model is not this tool's
     * to prompt.
     */
    private const MODES = array(
        'auto' => '',
        'vibrant' => 'Use vivid, saturated colours with strong separation between hues.',
        'subtle' => 'Use muted, natural tones as if hand-tinted; avoid oversaturation.',
    );

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
        return 'colorize_image';
    }

    public function getName(): string
    {
        return 'Colorize Image';
    }

    public function getDescription(): string
    {
        return 'Add realistic colour to a black-and-white or faded photograph using an AI image model. Requires a configured provider key; without one the tool refuses rather than returning a recoloured approximation.';
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
                    'description' => 'Stored file id to colorize.',
                    'minimum' => 1,
                ),
                'color_mode' => array(
                    'type' => 'string',
                    'description' => '"auto" lets the model decide; "vibrant" and "subtle" steer saturation.',
                    'enum' => array('auto', 'vibrant', 'subtle'),
                    'default' => 'auto',
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

        if (! $this->images->supports('edit')) {
            return $this->errors->create(
                'no_image_provider',
                'No AI image provider is configured for this runtime. Set GEMINI_API_KEY, OPENAI_API_KEY or REPLICATE_API_KEY on the media worker.',
            );
        }

        $mode = \strtolower($this->stringParam($arguments, 'color_mode', 'auto'));

        if (! isset(self::MODES[$mode])) {
            $mode = 'auto';
        }

        $options = array('provider' => 'auto');

        if ('' !== self::MODES[$mode]) {
            $options['prompt'] = self::MODES[$mode];
        }

        try {
            $result = $this->images->edit($source, 'colorize', $options);

            $payload = array(
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'mode' => $mode,
                'provider' => $result['provider'],
                'model' => $result['model'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'colorized-image'),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                'Image colorized with ' . $result['provider'] . '.',
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('colorize_failed', $e->getMessage());
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
