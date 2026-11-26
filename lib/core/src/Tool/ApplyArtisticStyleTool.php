<?php

/**
 * Restyle an image after a named artist or medium, with a model.
 *
 * Proposal 164 §164.6 phase 2. The nine presets are the WordPress tool's list and
 * they are the worker's own `STYLE_PROMPTS` keys, so the preset resolves to the
 * same instruction in both runtimes rather than to two different prompts with
 * the same name.
 *
 * ## `strength` is a prompt, not a weight
 *
 * No provider in the route accepts an intensity weight; diffusion strength is a
 * local-pipeline concept. `strength` therefore selects how emphatically the
 * instruction is phrased, and the payload echoes the resolved intensity so a
 * caller can see what was sent. Pretending to interpolate would be the worse
 * contract.
 *
 * ## `style_image` is not offered
 *
 * The WordPress tool declares a reference-image argument and its own description
 * admits it is "accepted for compatibility; not applied yet". An image-to-image
 * reference is a different route, not a flag, so it is absent here instead of
 * accepted and dropped.
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

class ApplyArtisticStyleTool extends AbstractTool
{
    /**
     * The presets the engine understands. Kept in step with the worker's
     * `STYLE_PROMPTS`, so an unknown slug is caught here with a useful list
     * rather than by a 400 from the route.
     */
    private const STYLES = array(
        'van_gogh',
        'picasso',
        'monet',
        'kandinsky',
        'ukiyo-e',
        'pop_art',
        'watercolor',
        'oil_painting',
        'sketch',
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
        return 'apply_artistic_style';
    }

    public function getName(): string
    {
        return 'Apply Artistic Style';
    }

    public function getDescription(): string
    {
        return 'Restyle a photograph as a painting, sketch or print in one of nine named artistic styles, using an AI image model. Strength selects how emphatically the style is applied. Requires a configured provider key.';
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
                    'description' => 'Stored file id to restyle.',
                    'minimum' => 1,
                ),
                'style' => array(
                    'type' => 'string',
                    'description' => 'Style preset.',
                    'enum' => self::STYLES,
                    'default' => 'van_gogh',
                ),
                'strength' => array(
                    'type' => 'number',
                    'description' => 'How emphatically the style is applied, 0-1. Resolves to a phrasing hint, not a diffusion weight.',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.8,
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

        // Arguments are validated before the provider is asked: an unknown
        // preset is a caller mistake the host can name for free, and reporting
        // it as "no provider configured" would send the operator to the wrong
        // problem.
        $style = \strtolower($this->stringParam($arguments, 'style', 'van_gogh'));

        if (! \in_array($style, self::STYLES, true)) {
            return $this->errors->validationFailed(
                'Unknown style preset: ' . $style . '.',
                array('style' => array('Allowed: ' . \implode(', ', self::STYLES) . '.')),
            );
        }

        if (! $this->images->supports('edit')) {
            return $this->errors->create(
                'no_image_provider',
                'No AI image provider is configured for this runtime. Set GEMINI_API_KEY, OPENAI_API_KEY or REPLICATE_API_KEY on the media worker.',
            );
        }

        $strength = \max(0.0, \min(1.0, $this->floatParam($arguments, 'strength', 0.8)));

        try {
            $result = $this->images->edit($source, 'style_transfer', array(
                'provider' => 'auto',
                'style' => $style,
                'prompt' => $this->intensity($strength),
            ));

            $payload = array(
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
                'style' => $style,
                'strength' => $strength,
                'intensity' => $this->label($strength),
                'provider' => $result['provider'],
                'model' => $result['model'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    (string) $result['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, $style),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    (string) $result['mime_type'],
                );
            }

            return $this->success(
                'Style "' . $style . '" applied at ' . $this->label($strength) . ' intensity.',
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('style_transfer_failed', $e->getMessage());
        }
    }

    /**
     * The instruction the provider receives alongside the preset.
     *
     * Deliberately additive: the route already sends the preset's own prompt, so
     * this only says how far to push it. A caller at the default gets a plain
     * "strongly", which is what a style transfer means when the source is a
     * photograph.
     */
    private function intensity(float $strength): string
    {
        return match (true) {
            $strength >= 0.75 => 'Apply the style strongly, letting it replace the photographic texture.',
            $strength >= 0.4 => 'Apply the style clearly while keeping the subject recognisable.',
            default => 'Apply the style lightly, as a tint over the photograph.',
        };
    }

    /**
     * A stable word for the resolved intensity, echoed in the payload.
     */
    private function label(float $strength): string
    {
        return match (true) {
            $strength >= 0.75 => 'strong',
            $strength >= 0.4 => 'moderate',
            default => 'light',
        };
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
