<?php

/**
 * Grab a single frame as an image.
 *
 * Proposal 164 §164.4.5. The worker's `thumbnail` operation is one frame at one
 * timestamp. The WordPress toolkit has both `generate_video_thumbnails` (cover
 * images) and `extract_video_frames` (a contact sheet of many frames); the
 * second is a different return shape — many files — and is not ported yet.
 *
 * `start` defaults to 0 because that is a real request; a caller that wants a
 * frame from the middle passes the second. `at_start_warning` is set when the
 * first frame was requested, because frame zero is very often a black fade-in
 * and a caller who did not mean it should be able to tell.
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

class GenerateVideoThumbnailsTool extends AbstractVideoTool
{
    public function getSlug(): string
    {
        return 'generate_video_thumbnails';
    }

    public function getName(): string
    {
        return 'Generate Video Thumbnail';
    }

    public function getDescription(): string
    {
        return 'Extract one frame from a video as a JPEG thumbnail, at a chosen second and optional width. The first frame is often a black fade-in, so a mid-clip timestamp is usually the better cover image.';
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
                'start' => array(
                    'type' => 'number',
                    'description' => 'Second to grab, from the start of the video. Default 0.',
                    'minimum' => 0,
                    'default' => 0,
                ),
                'width' => array(
                    'type' => 'integer',
                    'description' => 'Thumbnail width in pixels; height follows the aspect ratio.',
                    'minimum' => 1,
                    'maximum' => 8192,
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

        $refusal = $this->engineRefusal('thumbnail', 'extract a frame');

        if (null !== $refusal) {
            return $refusal;
        }

        $start = \array_key_exists('start', $arguments) && \is_numeric($arguments['start'])
            ? \max(0.0, (float) $arguments['start'])
            : 0.0;

        // The worker derives the encoder from the output extension, so a JPEG is
        // what makes this a thumbnail rather than a one-frame mp4.
        $options = array('format' => 'jpg', 'start' => $start);

        $width = $this->intParam($arguments, 'width');

        if ($width > 0) {
            $options['width'] = $width;
        }

        try {
            $result = $this->video->process($source, 'thumbnail', $options);
        } catch (\Throwable $e) {
            return $this->errors->create('thumbnail_failed', $e->getMessage());
        }

        $payload = $this->store(
            $result,
            'video-thumbnail',
            $arguments,
            $context,
            \sprintf('Frame at %.1fs extracted, %d bytes.', $start, (int) $result['bytes']),
        );

        // The worker writes the frame into the requested container, which means a
        // "thumbnail" can come back as an mp4 containing one frame. Say so rather
        // than letting a caller assume a JPEG.
        $payload['data']['at_start_warning'] = 0.0 === $start
            ? 'Frame zero is often a black fade-in; try a second or two in.'
            : null;

        return $payload;
    }
}
