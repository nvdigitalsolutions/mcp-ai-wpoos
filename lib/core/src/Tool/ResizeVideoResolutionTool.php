<?php

/**
 * Change a video's pixel dimensions.
 *
 * Proposal 164 §164.4.5. A named `resolution` is offered alongside raw
 * width/height because "1080p" is what a caller means and "1920" is what the
 * encoder needs. The preset sets **height** and lets the encoder keep the aspect
 * ratio (`scale=w:-1`), so a vertical clip stays vertical instead of being
 * squashed into a landscape box.
 *
 * Passing both `resolution` and a dimension is refused: two sources of truth for
 * the output size is the sort of ambiguity that produces a stretched video and a
 * bug report about the source.
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

class ResizeVideoResolutionTool extends AbstractVideoTool
{
    /**
     * Named heights. These are the display resolutions people ask for, not the
     * encoder's even numbers — 1080 means 1920x1080 at 16:9.
     */
    private const RESOLUTIONS = array(
        '480p' => 480,
        '720p' => 720,
        '1080p' => 1080,
        '1440p' => 1440,
        '2160p' => 2160,
    );

    public function getSlug(): string
    {
        return 'resize_video_resolution';
    }

    public function getName(): string
    {
        return 'Resize Video Resolution';
    }

    public function getDescription(): string
    {
        return 'Change a video\'s pixel dimensions, either to a named resolution (480p-2160p, height-based, aspect ratio preserved) or to an explicit width and/or height.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'video_url' => array(
                    'type' => 'string',
                    'description' => 'Path or URL of the source video. Give this or file_id.',
                ),
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the video.',
                    'minimum' => 1,
                ),
                'resolution' => array(
                    'type' => 'string',
                    'description' => 'Named height, aspect ratio preserved. Give this or width/height, not both.',
                    'enum' => array('480p', '720p', '1080p', '1440p', '2160p'),
                ),
                'width' => array(
                    'type' => 'integer',
                    'description' => 'Target width in pixels. With no height, the aspect ratio is preserved.',
                    'minimum' => 1,
                    'maximum' => 8192,
                ),
                'height' => array(
                    'type' => 'integer',
                    'description' => 'Target height in pixels. With no width, the aspect ratio is preserved.',
                    'minimum' => 1,
                    'maximum' => 8192,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container.',
                    'enum' => array('mp4', 'webm', 'mov', 'mkv'),
                    'default' => 'mp4',
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
        return array('external-api', 'requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $source = $this->sourcePath($arguments);

        if (null === $source) {
            return $this->errors->validationFailed(
                'video_url or file_id is required.',
                array('file_id' => array('Give the id of a stored video.')),
            );
        }

        $preset = \strtolower($this->stringParam($arguments, 'resolution'));
        $width = $this->intParam($arguments, 'width');
        $height = $this->intParam($arguments, 'height');

        if ('' !== $preset && ($width > 0 || $height > 0)) {
            return $this->errors->validationFailed(
                'Give a resolution or a dimension, not both.',
                array('resolution' => array('Two sources of truth for the output size.')),
            );
        }

        if ('' !== $preset) {
            if (! isset(self::RESOLUTIONS[$preset])) {
                return $this->errors->validationFailed(
                    'Unknown resolution: ' . $preset . '.',
                    array('resolution' => array('Allowed: ' . \implode(', ', \array_keys(self::RESOLUTIONS)) . '.')),
                );
            }

            $height = self::RESOLUTIONS[$preset];
        }

        if ($width <= 0 && $height <= 0) {
            return $this->errors->validationFailed(
                'Give a resolution, a width, or a height.',
                array('resolution' => array('One of the three is required.')),
            );
        }

        $refusal = $this->engineRefusal('resize', 'resize');

        if (null !== $refusal) {
            return $refusal;
        }

        $options = array('format' => $this->stringParam($arguments, 'format', 'mp4'));

        if ($width > 0) {
            $options['width'] = $width;
        }

        if ($height > 0) {
            $options['height'] = $height;
        }

        try {
            $result = $this->video->process($source, 'resize', $options);
        } catch (\Throwable $e) {
            return $this->errors->create('resize_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'resized-video',
            $arguments,
            $context,
            \sprintf('Resized to %d bytes.', (int) $result['bytes']),
        );
    }
}
