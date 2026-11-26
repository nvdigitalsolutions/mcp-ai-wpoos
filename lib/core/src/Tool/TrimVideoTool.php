<?php

/**
 * Keep one span of a video.
 *
 * Proposal 164 §164.4.5. `start` and `duration` are both optional but one is
 * required: "trim with no bounds" is a re-encode, which is `compress_video`'s
 * job and a different intent.
 *
 * The cut is frame-accurate (output-side seek), so a clip that begins on a
 * keyframe boundary starts exactly there. That is slower than an input-side seek
 * and is the right trade for a tool whose whole promise is where the clip starts.
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

class TrimVideoTool extends AbstractVideoTool
{
    public function getSlug(): string
    {
        return 'trim_video';
    }

    public function getName(): string
    {
        return 'Trim Video';
    }

    public function getDescription(): string
    {
        return 'Cut one span out of a video, frame-accurately, from a start time and/or a duration in seconds. Needs ffmpeg on the media worker.';
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
                    'description' => 'Seconds from the start of the source.',
                    'minimum' => 0,
                ),
                'duration' => array(
                    'type' => 'number',
                    'description' => 'Seconds to keep from `start`. Omit to keep everything after it.',
                    'minimum' => 0.01,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container. Default mp4, the only one every browser plays.',
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

        $hasStart = \array_key_exists('start', $arguments) && \is_numeric($arguments['start']);
        $hasDuration = \array_key_exists('duration', $arguments) && \is_numeric($arguments['duration']);

        if (! $hasStart && ! $hasDuration) {
            return $this->errors->validationFailed(
                'Give a start time, a duration, or both.',
                array('duration' => array('A trim with no bounds is a re-encode; use compress_video.')),
            );
        }

        $refusal = $this->engineRefusal('trim', 'trim');

        if (null !== $refusal) {
            return $refusal;
        }

        $options = array('format' => $this->stringParam($arguments, 'format', 'mp4'));

        if ($hasStart) {
            $options['start'] = (float) $arguments['start'];
        }

        if ($hasDuration) {
            $options['duration'] = (float) $arguments['duration'];
        }

        try {
            $result = $this->video->process($source, 'trim', $options);
        } catch (\Throwable $e) {
            return $this->errors->create('trim_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'trimmed',
            $arguments,
            $context,
            \sprintf('Trimmed to %.1fs, %d bytes.', (float) ($options['duration'] ?? 0), (int) $result['bytes']),
        );
    }
}
