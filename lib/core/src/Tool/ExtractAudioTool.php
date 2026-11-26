<?php

/**
 * Pull the audio track out of a video.
 *
 * Proposal 164 §164.26. No WordPress slug in the video-production toolkit asks
 * for this — §164.17 recorded that — but the route has carried the
 * `extract_audio` operation since it was written, so the capability was one tool
 * away. §164.19 called it "available as an additive tool" and left it at that.
 *
 * ## Lossless when the container allows it
 *
 * A copy is always preferable to a re-encode, and the route decides that from
 * the *source* codec rather than from the output name: an AAC track into `m4a`
 * or `aac` is copied, an MP3 track into `mp3` is copied, and anything else is
 * encoded into the format's codec at the requested bitrate. The tool reports
 * which happened, because "did this cost me a generation?" is the one question a
 * caller of an extraction tool always has.
 *
 * That is also why `audio_bitrate` is not required: it describes an encoder, and
 * a lossless copy has none. Sending one is not refused — the route drops it —
 * but a caller who wants a specific bitrate should pick a format that has to
 * encode.
 *
 * ## Three containers, and no fourth
 *
 * `mp3`, `m4a` and `aac` are the audio containers the route's format allowlist
 * carries. Asking for `wav` is refused with the list rather than answered by a
 * video container that would fail at the muxer.
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

class ExtractAudioTool extends AbstractVideoTool
{
    /**
     * The audio containers `/api/video/process` can write.
     */
    private const FORMATS = array('mp3', 'm4a', 'aac');

    public function getSlug(): string
    {
        return 'extract_audio';
    }

    public function getName(): string
    {
        return 'Extract Audio';
    }

    public function getDescription(): string
    {
        return 'Pull the audio track out of a video as mp3, m4a or aac. The track is copied without re-encoding whenever the container already speaks its codec (AAC into m4a, MP3 into mp3), so the result is lossless; otherwise it is encoded at the requested bitrate. Needs ffmpeg on the media worker.';
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
                    'description' => 'Stored file id of the source.',
                    'minimum' => 1,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Audio container. A container that already speaks the source codec is copied rather than re-encoded.',
                    'enum' => self::FORMATS,
                    'default' => 'mp3',
                ),
                'audio_bitrate' => array(
                    'type' => 'string',
                    'description' => 'Bitrate such as "128k" or "192k". Only used when the track has to be encoded, not when it is copied.',
                    'default' => '128k',
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

        if (null === $source || '' === $source) {
            return $this->errors->validationFailed(
                'A video_url or file_id is required.',
                array('video_url' => array('Give a path or URL, or use file_id.')),
            );
        }

        $format = \strtolower(\trim($this->stringParam($arguments, 'format', 'mp3')));

        if (! \in_array($format, self::FORMATS, true)) {
            return $this->errors->validationFailed(
                'The audio format must be one of: ' . \implode(', ', self::FORMATS) . '.',
                array('format' => array('Got ' . $format . '.')),
            );
        }

        $refusal = $this->engineRefusal('extract_audio', 'extract audio');

        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $result = $this->video->process($source, 'extract_audio', array(
                'format' => $format,
                'audio_bitrate' => $this->stringParam($arguments, 'audio_bitrate', '128k'),
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('extract_audio_failed', $e->getMessage());
        }

        // `reencoded` is absent only when the engine did not say, and the honest
        // reading of "it did not say" is not "it was lossless".
        $reencoded = isset($result['reencoded']) ? (bool) $result['reencoded'] : true;

        $envelope = $this->store(
            $result,
            'extracted-audio',
            $arguments,
            $context,
            \sprintf(
                'Extracted the audio track as %s (%d bytes, %s).',
                $format,
                (int) $result['bytes'],
                $reencoded ? 're-encoded' : 'copied without re-encoding',
            ),
        );

        if (\is_array($envelope['data'] ?? null)) {
            $envelope['data'] += array(
                'reencoded' => $reencoded,
                'lossless' => ! $reencoded,
            );
        }

        return $envelope;
    }
}
