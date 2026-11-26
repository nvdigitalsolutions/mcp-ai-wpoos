<?php

/**
 * Change a video's container.
 *
 * Proposal 164 §164.4.5. The worker's `convert` operation remuxes into the
 * requested container; it does not re-encode the streams. That is the honest
 * description and the useful one: a remux is seconds, not minutes, and it leaves
 * the picture untouched.
 *
 * A codec that the target container cannot hold is refused by ffmpeg, and that
 * message reaches the caller unchanged rather than being reinterpreted here.
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

class ConvertVideoFormatTool extends AbstractVideoTool
{
    /**
     * Containers the route can write. `gif` is absent on purpose: it is an
     * animation, not a video container, and `convert_video_to_gif` is a
     * different promise.
     */
    private const FORMATS = array('mp4', 'webm', 'mov', 'mkv');

    public function getSlug(): string
    {
        return 'convert_video_format';
    }

    public function getName(): string
    {
        return 'Convert Video Format';
    }

    public function getDescription(): string
    {
        return 'Remux a video into another container (mp4, webm, mov, mkv) without re-encoding the streams. Fast, and the picture is untouched.';
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
                'format' => array(
                    'type' => 'string',
                    'description' => 'Target container.',
                    'enum' => self::FORMATS,
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('format'),
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
        $format = \strtolower($this->stringParam($arguments, 'format'));

        if (null === $source) {
            return $this->errors->validationFailed(
                'video_url or file_id is required.',
                array('file_id' => array('Give the id of a stored video.')),
            );
        }

        if (! \in_array($format, self::FORMATS, true)) {
            return $this->errors->validationFailed(
                'Unsupported target container: ' . $format . '.',
                array('format' => array('Allowed: ' . \implode(', ', self::FORMATS) . '.')),
            );
        }

        $refusal = $this->engineRefusal('convert', 'change the container');

        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $result = $this->video->process($source, 'convert', array('format' => $format));
        } catch (\Throwable $e) {
            return $this->errors->create('convert_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'converted-video',
            $arguments,
            $context,
            \sprintf('Remuxed to %s, %d bytes.', $format, (int) $result['bytes']),
        );
    }
}
