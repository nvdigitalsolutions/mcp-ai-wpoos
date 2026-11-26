<?php

/**
 * Re-encode a video with explicit encoder control.
 *
 * Proposal 164 §164.4.5 follow-on. Unlike `compress_video` (one fixed H.264
 * profile) and `convert_video_format` (change the container, keep the codec),
 * this tool names the codec, the bitrate and the frame rate. The route gained a
 * `transcode` operation to carry them.
 *
 * ## Every value is an allowlist
 *
 * The codec names reach `-c:v` / `-c:a` and the bitrates reach `-b:v` /
 * `-b:a`. Both are validated against fixed sets here *and* in the route, so an
 * unknown encoder is a named refusal rather than an ffmpeg argument. The
 * bitrate shape (`2000k`, `5m`) cannot begin with a dash, which is the shape
 * that could be read as another option.
 *
 * ## Deliberate deviations from WordPress
 *
 * **`preset` is not offered.** The WordPress tool takes a platform preset that
 * duplicates `optimize_for_platform`; carrying both would give two tools the
 * same job and two chances to disagree about a frame size. Transcode is for
 * encoder control, `optimize_for_platform` is for a frame.
 *
 * **`resolution` sets the height, not both sides.** The WordPress enum is a
 * list of `WxH` pairs such as `1920x1080`. Applying both numbers to a 9:16
 * source stretches it; taking the height and deriving the width (`scale=-2:H`)
 * keeps the aspect ratio and keeps the width even, which x264 and x265
 * require.
 *
 * **`avi` and `flv` are accepted** because the route's container allowlist now
 * carries them, so the WordPress enum is covered rather than trimmed.
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

class TranscodeVideoTool extends AbstractVideoTool
{
    /**
     * Container → encoders that belong in it.
     *
     * Only the two traps that catch everyone are encoded here: VP8/VP9 in an
     * MP4 (which ffmpeg refuses late and cryptically) and H.264/H.265 in a
     * WebM (which it accepts and produces a file no browser reads).
     *
     * @var array<string, list<string>>
     */
    private const CONTAINER_CODECS = array(
        'webm' => array('libvpx', 'libvpx-vp9'),
        'mkv' => array('libx264', 'libx265', 'libvpx', 'libvpx-vp9'),
        'mp4' => array('libx264', 'libx265'),
        'mov' => array('libx264', 'libx265'),
        'avi' => array('libx264', 'libx265'),
        'flv' => array('libx264'),
    );

    /**
     * The WordPress enum, mapped to the height it means.
     *
     * @var array<string, int>
     */
    private const RESOLUTIONS = array(
        '3840x2160' => 2160,
        '2560x1440' => 1440,
        '1920x1080' => 1080,
        '1280x720' => 720,
        '854x480' => 480,
        '640x360' => 360,
    );

    public function getSlug(): string
    {
        return 'transcode_video';
    }

    public function getName(): string
    {
        return 'Transcode Video';
    }

    public function getDescription(): string
    {
        return 'Re-encode a video with a chosen codec, bitrate and frame rate, optionally at a smaller height. Use optimize_for_platform for a platform frame and compress_video for a one-step size reduction with no settings.';
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
                'output_format' => array(
                    'type' => 'string',
                    'description' => 'Output container. It constrains which video codecs are accepted.',
                    'enum' => array('mp4', 'webm', 'mov', 'mkv', 'avi', 'flv'),
                    'default' => 'mp4',
                ),
                'resolution' => array(
                    'type' => 'string',
                    'description' => 'Target height. The width is derived so the aspect ratio is kept.',
                    'enum' => array_keys(self::RESOLUTIONS),
                ),
                'video_codec' => array(
                    'type' => 'string',
                    'description' => 'Video encoder. H.264 and H.265 suit mp4/mov/mkv; VP8 and VP9 suit webm/mkv.',
                    'enum' => array('libx264', 'libx265', 'libvpx', 'libvpx-vp9'),
                    'default' => 'libx264',
                ),
                'audio_codec' => array(
                    'type' => 'string',
                    'description' => 'Audio encoder.',
                    'enum' => array('aac', 'libmp3lame', 'libvorbis', 'libopus'),
                    'default' => 'aac',
                ),
                'video_bitrate' => array(
                    'type' => 'string',
                    'description' => 'Video bitrate such as "2000k" or "5m". Higher is better looking and larger.',
                    'default' => '2000k',
                ),
                'audio_bitrate' => array(
                    'type' => 'string',
                    'description' => 'Audio bitrate such as "128k" or "192k".',
                    'default' => '128k',
                ),
                'fps' => array(
                    'type' => 'integer',
                    'description' => 'Output frame rate.',
                    'minimum' => 1,
                    'maximum' => 120,
                ),
                'remove_audio' => array(
                    'type' => 'boolean',
                    'description' => 'Drop the audio track from the output.',
                    'default' => false,
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

        $format = \strtolower(\trim($this->stringParam($arguments, 'output_format', 'mp4')));

        if (! isset(self::CONTAINER_CODECS[$format])) {
            return $this->errors->validationFailed(
                'Unknown output_format: ' . $format . '. Supported: ' . \implode(', ', \array_keys(self::CONTAINER_CODECS)) . '.',
            );
        }

        $codec = \strtolower(\trim($this->stringParam($arguments, 'video_codec', 'libx264')));
        $allowed = self::CONTAINER_CODECS[$format];

        if (! \in_array($codec, $allowed, true)) {
            return $this->errors->validationFailed(
                \sprintf(
                    'The %s container does not take %s. It takes: %s.',
                    $format,
                    $codec,
                    \implode(', ', $allowed),
                ),
                array('video_codec' => array('For ' . $format . ': ' . \implode(', ', $allowed) . '.')),
            );
        }

        $height = null;
        $resolution = \strtolower(\trim($this->stringParam($arguments, 'resolution')));

        if ('' !== $resolution) {
            if (! isset(self::RESOLUTIONS[$resolution])) {
                return $this->errors->validationFailed(
                    'Unknown resolution: ' . $resolution . '. Supported: ' . \implode(', ', \array_keys(self::RESOLUTIONS)) . '.',
                );
            }

            $height = self::RESOLUTIONS[$resolution];
        }

        $refusal = $this->engineRefusal('transcode', 'transcode');

        if (null !== $refusal) {
            return $refusal;
        }

        $options = array(
            'format' => $format,
            'video_codec' => $codec,
            'audio_codec' => \strtolower(\trim($this->stringParam($arguments, 'audio_codec', 'aac'))),
            'video_bitrate' => $this->stringParam($arguments, 'video_bitrate', '2000k'),
            'audio_bitrate' => $this->stringParam($arguments, 'audio_bitrate', '128k'),
            'remove_audio' => $this->boolParam($arguments, 'remove_audio', false),
        );

        if (null !== $height) {
            $options['height'] = $height;
        }

        $fps = $this->intParam($arguments, 'fps');

        if ($fps > 0) {
            $options['fps'] = $fps;
        }

        try {
            $result = $this->video->process($source, 'transcode', $options);
        } catch (\Throwable $e) {
            return $this->errors->create('transcode_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'transcoded-video',
            $arguments,
            $context,
            \sprintf(
                'Transcoded to %s with %s%s.',
                $format,
                $codec,
                null === $height ? '' : ' at ' . $height . 'p',
            ),
        );
    }
}
