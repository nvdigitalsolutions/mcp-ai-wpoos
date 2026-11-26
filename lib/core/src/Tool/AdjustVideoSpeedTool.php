<?php

/**
 * Speed a video up or slow it down, keeping picture and sound together.
 *
 * Proposal 164 §164.4.5 follow-on. The worker's `speed` operation applies
 * `setpts` to the video and an `atempo` chain to the audio, so a 2× video is
 * also 2× audio and the two do not drift.
 *
 * ## Two deliberate differences from the WordPress tool
 *
 * **`speed_factor` is required and `1.0` is refused.** The WordPress tool
 * defaults it to `1.0`, which re-encodes the file without changing anything —
 * a no-op that costs a transcode and returns a file the caller did not ask
 * for. `compress_video` and `convert_video_format` are the tools for "make it
 * smaller" and "make it another container".
 *
 * **`maintain_pitch` is honoured, not assumed.** With `true` (the default) the
 * audio goes through `atempo`, which preserves pitch. With `false` it is
 * resampled instead, which shifts the pitch with the speed — the "chipmunk"
 * effect, and the only reason to ask for it.
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

class AdjustVideoSpeedTool extends AbstractVideoTool
{
    /**
     * The tool's own range. The adapter and the route carry the same bounds, so
     * an out-of-range factor is named by whichever layer sees it first.
     */
    private const MIN_SPEED = 0.25;

    private const MAX_SPEED = 4.0;

    public function getSlug(): string
    {
        return 'adjust_video_speed';
    }

    public function getName(): string
    {
        return 'Adjust Video Speed';
    }

    public function getDescription(): string
    {
        return 'Play a video faster or slower, with the audio resampled to match so the two stay in sync. Pitch is preserved unless maintain_pitch is false.';
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
                'speed_factor' => array(
                    'type' => 'number',
                    'description' => 'Playback multiplier: 2.0 is twice as fast, 0.5 is half speed. Must differ from 1.0, which would re-encode without changing anything.',
                    'minimum' => self::MIN_SPEED,
                    'maximum' => self::MAX_SPEED,
                ),
                'maintain_pitch' => array(
                    'type' => 'boolean',
                    'description' => 'Keep the audio at its original pitch. False resamples instead, which shifts the pitch with the speed.',
                    'default' => true,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container. Default mp4; `+faststart` applies to mp4 and mov.',
                    'enum' => array('mp4', 'webm', 'mov', 'mkv'),
                    'default' => 'mp4',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('speed_factor'),
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

        if (! \array_key_exists('speed_factor', $arguments) || '' === $arguments['speed_factor']) {
            return $this->errors->validationFailed(
                'speed_factor is required: give the multiplier, for example 2.0 for double speed.',
                array('speed_factor' => array('Between ' . self::MIN_SPEED . ' and ' . self::MAX_SPEED . '.')),
            );
        }

        $factor = $this->floatParam($arguments, 'speed_factor');

        if ($factor < self::MIN_SPEED || $factor > self::MAX_SPEED) {
            return $this->errors->validationFailed(
                'A speed factor must be between ' . self::MIN_SPEED . ' and ' . self::MAX_SPEED . '.',
                array('speed_factor' => array('Got ' . $factor . '.')),
            );
        }

        if (\abs($factor - 1.0) < 1e-9) {
            return $this->errors->validationFailed(
                'A speed factor of 1 would re-encode the video without changing it. Use compress_video to make it smaller, or convert_video_format for another container.',
                array('speed_factor' => array('Must differ from 1.0.')),
            );
        }

        $refusal = $this->engineRefusal('speed', 'change speed');

        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $result = $this->video->process($source, 'speed', array(
                'speed' => $factor,
                'maintain_pitch' => $this->boolParam($arguments, 'maintain_pitch', true),
                'format' => $this->stringParam($arguments, 'format', 'mp4'),
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('speed_change_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'adjusted-video',
            $arguments,
            $context,
            \sprintf(
                'Sped to %.2fx (%d bytes).',
                $factor,
                (int) $result['bytes'],
            ),
        );
    }
}
