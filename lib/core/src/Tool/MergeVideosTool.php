<?php

/**
 * Join several videos, in order, without re-encoding.
 *
 * Proposal 164 §164.19. The WordPress tool with this slug is a stub — `// TODO`
 * then a success envelope — so this one does the work.
 *
 * ## Why it refuses more than it joins
 *
 * A merge copies the streams instead of re-encoding them: no quality loss, and
 * it finishes in well under a second for clips that fit in memory. The price is
 * that **every clip has to match** — frame size, frame rate, video codec, and
 * whether there is an audio stream at all.
 *
 * That precondition is not a convenience. ffmpeg's concat demuxer does not
 * fail on a mismatch: joining a 25 fps clip to a 30 fps one under `-c copy`
 * produced a **4.8 s** file instead of 5.0 s and still reported `25/1`. Silently
 * wrong output is worse than a refusal, so the route probes every input and
 * names the first property that differs.
 *
 * ## Transitions are deliberately absent
 *
 * `transition: fade`/`dissolve` would need `xfade` and `acrossfade`, which
 * re-encode and need cumulative offsets computed from each clip's duration —
 * a different operation, not a flag on this one. The WordPress tool declares
 * `fade` as its **default** and then does nothing at all, so the default here
 * is `none`; asking for a fade is refused with the reason.
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

class MergeVideosTool extends AbstractVideoTool
{
    /**
     * What the contract offers, against what is implemented.
     */
    private const TRANSITIONS = array('none', 'fade', 'dissolve');

    public function getSlug(): string
    {
        return 'merge_videos';
    }

    public function getName(): string
    {
        return 'Merge Videos';
    }

    public function getDescription(): string
    {
        return 'Join two or more videos end to end, in the order given, by copying their streams. Every clip must share the same frame size, frame rate, video codec and audio layout; a clip that differs is named rather than joined, because a copy-merge of mismatched clips produces a file with the wrong duration. No transitions: ask for transition "none".';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'video_urls' => array(
                    'type' => 'array',
                    'description' => 'Paths or URLs of the videos to join, in order. Give this or file_ids.',
                    'items' => array('type' => 'string'),
                    'minItems' => 2,
                ),
                'file_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids of the videos to join, in order.',
                    'items' => array('type' => 'integer'),
                    'minItems' => 2,
                ),
                'transition' => array(
                    'type' => 'string',
                    'description' => 'Only "none" is implemented. Fades need xfade, which re-encodes; merge copies.',
                    'enum' => self::TRANSITIONS,
                    'default' => 'none',
                ),
                'output_format' => array(
                    'type' => 'string',
                    'description' => 'Container for the result. A stream can only be copied into one that already speaks it, so h264 suits mp4/mov/mkv and VP8/VP9 suit webm/mkv.',
                    'enum' => array('mp4', 'mov', 'mkv', 'webm'),
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
        $inputs = $this->inputPaths($arguments, 'file_ids', 'video_urls');

        if (\is_string($inputs)) {
            return $this->errors->validationFailed($inputs);
        }

        if (\count($inputs) < 2) {
            return $this->errors->validationFailed(
                'A merge needs at least two videos, in the order to join them.',
                array('video_urls' => array('Give two or more, or use file_ids.')),
            );
        }

        $transition = \strtolower(\trim($this->stringParam($arguments, 'transition', 'none')));

        if (! \in_array($transition, self::TRANSITIONS, true)) {
            return $this->errors->validationFailed(
                'Unknown transition: ' . $transition . '. Supported: ' . \implode(', ', self::TRANSITIONS) . '.',
            );
        }

        if ('none' !== $transition) {
            return $this->errors->validationFailed(
                \sprintf(
                    'A "%s" transition is not implemented. A merge copies the streams; a cross-fade has to re-encode both clips (ffmpeg xfade/acrossfade with cumulative offsets). Merge with transition "none".',
                    $transition,
                ),
                array('transition' => array('Implemented: none.')),
            );
        }

        if (null === $this->composition || ! $this->composition->supports('merge')) {
            return $this->engineRefusalMessage('join videos');
        }

        try {
            $result = $this->composition->compose('merge', $inputs, array(
                'format' => $this->stringParam($arguments, 'output_format', 'mp4'),
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('merge_failed', $e->getMessage());
        }

        $envelope = $this->store(
            $result,
            'merged-video',
            $arguments,
            $context,
            \sprintf(
                'Joined %d clips into %s (%d bytes).',
                \count($inputs),
                $result['format'],
                (int) $result['bytes'],
            ),
        );

        if (\is_array($envelope['data'] ?? null)) {
            $envelope['data'] += array(
                'source_count' => (int) ($result['source_count'] ?? \count($inputs)),
                'reencoded' => (bool) ($result['reencoded'] ?? false),
            );
        }

        return $envelope;
    }
}
