<?php

/**
 * Join several videos, in order — by copying, or with a cross-fade.
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
 * ## Two ways to join
 *
 * `transition: none` copies: no quality loss, and it finishes in well under a
 * second for clips that fit in memory. `fade`/`dissolve` cannot copy — every
 * frame `xfade` emits during the overlap is a blend of two frames — so they
 * re-encode instead, normalising each clip into the first clip's frame, rate
 * and sample format. The cuts are placed cumulatively:
 *
 *     offset_i = (d₁ + … + d_i) − i × T
 *
 * which is why 2 + 3 + 4 s clips with a 0.5 s fade are 8 s, not 9.
 *
 * The WordPress tool declares `fade` as its default. The default here stays
 * `none`: a default that silently re-encodes would change the output of every
 * existing pipeline, and opting in costs one argument.
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
     * The WordPress enum. All three are implemented now; the constant is the
     * allowlist that both the schema and the refusal message read.
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
        return 'Join two or more videos end to end, in the order given. By default the streams are copied, so every clip must share the same frame size, frame rate, video codec and audio layout; a clip that differs is named rather than joined, because a copy-merge of mismatched clips produces a file with the wrong duration. A "fade" or "dissolve" transition re-encodes and cross-fades instead, normalising every clip into the first clip\'s frame.';
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
                    'description' => 'How each clip meets the next. "none" copies the streams, so the clips must match; "fade" and "dissolve" cross-fade and re-encode.',
                    'enum' => self::TRANSITIONS,
                    'default' => 'none',
                ),
                'transition_duration' => array(
                    'type' => 'number',
                    'description' => 'Seconds each cross-fade lasts. Only read when transition is not "none", and it has to be shorter than the shortest clip.',
                    'minimum' => 0.05,
                    'maximum' => 5,
                    'default' => 1,
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
            $seconds = $this->floatParam($arguments, 'transition_duration', 1.0);

            if ($seconds < 0.05 || $seconds > 5.0) {
                return $this->errors->validationFailed(
                    'transition_duration must be between 0.05 and 5 seconds.',
                    array('transition_duration' => array('Got ' . $seconds . '.')),
                );
            }
        }

        if (null === $this->composition || ! $this->composition->supports('merge')) {
            return $this->engineRefusalMessage('join videos');
        }

        $options = array(
            'format' => $this->stringParam($arguments, 'output_format', 'mp4'),
            'transition' => $transition,
        );

        // Only a transition reads a duration, so a copy-merge keeps sending the
        // field set it always sent.
        if ('none' !== $transition) {
            $options['transition_duration'] = $this->floatParam($arguments, 'transition_duration', 1.0);
        }

        try {
            $result = $this->composition->compose('merge', $inputs, $options);
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
                'transition' => (string) ($result['transition'] ?? $transition),
            );

            // Absent for a copy, which has no overlap to describe.
            if (isset($result['transition_duration'])) {
                $envelope['data'] += array('transition_duration' => (float) $result['transition_duration']);
            }
        }

        return $envelope;
    }
}
