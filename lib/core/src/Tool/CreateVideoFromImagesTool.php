<?php

/**
 * Build a video from a list of stills.
 *
 * Proposal 164 §164.19. The WordPress tool with this slug is the fourth stub in
 * this family — `// TODO` then a success envelope — so this one does the work.
 *
 * ## Each still becomes a looped input
 *
 * `-loop 1 -t <seconds> -i <file>` per image, each branch fitted into a common
 * frame and given a constant frame rate, then joined with the `concat` filter.
 * The alternative — writing a `duration` list for the concat *demuxer* — needs
 * the last file repeated to work around how the demuxer treats a trailing
 * duration, and it assumes every still has the same dimensions. Explicit
 * branches have neither problem.
 *
 * ## `contain`, not `cover`
 *
 * A portrait photo in a landscape frame is letterboxed rather than cropped.
 * Cropping a photograph loses the part someone chose to show; bars are honest
 * about the shape mismatch. `optimize_for_platform` is the tool for filling a
 * frame.
 *
 * ## Transitions
 *
 * `fade` and `slide` are an `xfade` chain over the stills. Every branch is
 * already forced into the same frame, rate and SAR, so the only thing left to
 * place is each cut — by the same cumulative offset a transitioning merge uses:
 *
 *     offset_i = (d₁ + … + d_i) − i × T
 *
 * Three 2 s stills with a 0.5 s transition are therefore 5 s, not 6. The
 * overlap is a caller's choice (`transition_duration`, 0.05–5 s); it defaults
 * to 0.5 s because the WordPress contract has no such field and a half-second
 * reads better against a 2–3 s still than the merge default of 1 s.
 *
 * ## `zoom` is motion, not a boundary
 *
 * `zoom` is the one value in the enum that does not join two stills. It is Ken
 * Burns motion — `zoompan` drifting each still from 1.0 to 1.12 — so the
 * boundaries stay hard cuts and the timeline keeps its full length. Naming that
 * difference matters: a caller who wanted a zoom *transition* between two
 * images asked for something this tool does not do, and the description says so
 * rather than quietly substituting a cross-fade.
 *
 * A future `motion` field could combine the drift with a cross-fade; one enum
 * cannot express both, and inventing a name for a second effect inside
 * `transition` would be worse than stating the limit.
 *
 * **There is no background audio argument.** The worker's compose route reads
 * an ordered list of files, and mixing "N images then a track" into one list
 * makes the boundary ambiguous. A silent slideshow that says it is silent is
 * better than an `audio` argument that is accepted and dropped.
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

class CreateVideoFromImagesTool extends AbstractVideoTool
{
    /**
     * The WordPress enum, mapped to the frame each name means.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const FRAMES = array(
        '720p' => array(1280, 720),
        '1080p' => array(1920, 1080),
        '4k' => array(3840, 2160),
    );

    private const TRANSITIONS = array('fade', 'slide', 'zoom', 'none');

    /**
     * The values that join two stills, and therefore remove their overlap from
     * the timeline. `zoom` and `none` are not among them: one is motion inside a
     * still, the other is a hard cut.
     */
    private const BOUNDARY_TRANSITIONS = array('fade', 'slide');

    public function getSlug(): string
    {
        return 'create_video_from_images';
    }

    public function getName(): string
    {
        return 'Create Video from Images';
    }

    public function getDescription(): string
    {
        return 'Turn a list of images into a slideshow video, each shown for a fixed number of seconds, fitted into one frame without cropping. "fade" and "slide" cross-fade or push between images; "zoom" adds Ken Burns motion inside each image and leaves the joins as hard cuts. The result is silent.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'image_urls' => array(
                    'type' => 'array',
                    'description' => 'Paths or URLs of the images, in the order to show them. Give this or image_file_ids.',
                    'items' => array('type' => 'string'),
                    'minItems' => 1,
                ),
                'image_file_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids of the images, in the order to show them.',
                    'items' => array('type' => 'integer'),
                    'minItems' => 1,
                ),
                'duration_per_image' => array(
                    'type' => 'number',
                    'description' => 'Seconds each image is shown.',
                    'minimum' => 0.1,
                    'maximum' => 60,
                    'default' => 3,
                ),
                'resolution' => array(
                    'type' => 'string',
                    'description' => 'Output frame. Images are fitted into it and letterboxed rather than cropped.',
                    'enum' => array('720p', '1080p', '4k'),
                    'default' => '1080p',
                ),
                'transition' => array(
                    'type' => 'string',
                    'description' => 'How the slideshow moves. "fade" and "slide" cross-fade or push between images; "zoom" adds Ken Burns motion inside each image and leaves the joins as hard cuts.',
                    'enum' => self::TRANSITIONS,
                    'default' => 'none',
                ),
                'transition_duration' => array(
                    'type' => 'number',
                    'description' => 'Seconds each cross-fade or slide lasts. Only read by "fade" and "slide", and it has to be shorter than duration_per_image.',
                    'minimum' => 0.05,
                    'maximum' => 5,
                    'default' => 0.5,
                ),
                'output_format' => array(
                    'type' => 'string',
                    'description' => 'Output container.',
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
        $inputs = $this->inputPaths($arguments, 'image_file_ids', 'image_urls');

        if (\is_string($inputs)) {
            return $this->errors->validationFailed($inputs);
        }

        if (array() === $inputs) {
            return $this->errors->validationFailed(
                'At least one image is required.',
                array('image_urls' => array('Give one or more images in display order.')),
            );
        }

        $transition = \strtolower(\trim($this->stringParam($arguments, 'transition', 'none')));

        if (! \in_array($transition, self::TRANSITIONS, true)) {
            return $this->errors->validationFailed(
                'Unknown transition: ' . $transition . '. Supported: ' . \implode(', ', self::TRANSITIONS) . '.',
            );
        }

        // Only a boundary transition has an overlap, so only it has a duration to
        // bound. A duration sent with `zoom` or `none` is inert — refused would be
        // disproportionate, since the video renders correctly either way.
        $boundary = \in_array($transition, self::BOUNDARY_TRANSITIONS, true);

        if ($boundary) {
            $crossFade = $this->floatParam($arguments, 'transition_duration', 0.5);

            if ($crossFade < 0.05 || $crossFade > 5.0) {
                return $this->errors->validationFailed(
                    'transition_duration must be between 0.05 and 5 seconds.',
                    array('transition_duration' => array('Got ' . $crossFade . '.')),
                );
            }
        }
        $resolution = \strtolower(\trim($this->stringParam($arguments, 'resolution', '1080p')));

        if (! isset(self::FRAMES[$resolution])) {
            return $this->errors->validationFailed(
                'Unknown resolution: ' . $resolution . '. Supported: ' . \implode(', ', \array_keys(self::FRAMES)) . '.',
            );
        }

        $seconds = $this->floatParam($arguments, 'duration_per_image', 3.0);

        if ($seconds < 0.1 || $seconds > 60.0) {
            return $this->errors->validationFailed(
                'duration_per_image must be between 0.1 and 60 seconds.',
                array('duration_per_image' => array('Got ' . $seconds . '.')),
            );
        }

        if (null === $this->composition || ! $this->composition->supports('images')) {
            return $this->engineRefusalMessage('build a video from images');
        }

        [$width, $height] = self::FRAMES[$resolution];

        $options = array(
            'width' => $width,
            'height' => $height,
            'duration' => $seconds,
            'format' => $this->stringParam($arguments, 'output_format', 'mp4'),
            'transition' => $transition,
        );

        // A still has no boundary to cross-fade on its own, `zoom` is motion
        // inside one, and a plain slideshow is all hard cuts — so the duration
        // only travels when there is an overlap for it to describe.
        if ($boundary) {
            $options['transition_duration'] = $this->floatParam($arguments, 'transition_duration', 0.5);
        }

        try {
            $result = $this->composition->compose('images', $inputs, $options);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('slideshow_failed', $e->getMessage());
        }

        $envelope = $this->store(
            $result,
            'slideshow-video',
            $arguments,
            $context,
            \sprintf(
                'Built a %s from %d image(s) at %.1f s each (%d bytes).',
                $resolution,
                \count($inputs),
                $seconds,
                (int) $result['bytes'],
            ),
        );

        if (\is_array($envelope['data'] ?? null)) {
            $envelope['data'] += array(
                'source_count' => (int) ($result['source_count'] ?? \count($inputs)),
                'duration' => (float) ($result['duration'] ?? \count($inputs) * $seconds),
                'resolution' => $resolution,
                'transition' => (string) ($result['transition'] ?? $transition),
            );

            // Absent for a plain slideshow, which has no overlap to describe.
            if (isset($result['transition_duration'])) {
                $envelope['data'] += array('transition_duration' => (float) $result['transition_duration']);
            }
        }

        return $envelope;
    }
}
