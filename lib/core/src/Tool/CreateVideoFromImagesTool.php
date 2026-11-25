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
 * ## Two deliberate omissions
 *
 * **`transition` accepts only `none`.** `fade`/`slide`/`zoom` are `xfade`/
 * `zoompan`: a chain that re-encodes and needs cumulative offsets from every
 * segment's duration. The WordPress tool declares `fade` as its default and
 * then does nothing at all, so `none` is the default here and the others are
 * refused with the reason.
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
        return 'Turn a list of images into a slideshow video, each shown for a fixed number of seconds, fitted into one frame without cropping. No transitions: ask for transition "none". The result is silent.';
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
                    'description' => 'Only "none" is implemented; the others need xfade/zoompan, which re-encode.',
                    'enum' => self::TRANSITIONS,
                    'default' => 'none',
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

        if ('none' !== $transition) {
            return $this->errors->validationFailed(
                \sprintf(
                    'A "%s" transition is not implemented. Each still is looped and joined; a %s needs xfade/zoompan with cumulative offsets, which re-encodes every segment. Use transition "none".',
                    $transition,
                    $transition,
                ),
                array('transition' => array('Implemented: none.')),
            );
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

        try {
            $result = $this->composition->compose('images', $inputs, array(
                'width' => $width,
                'height' => $height,
                'duration' => $seconds,
                'format' => $this->stringParam($arguments, 'output_format', 'mp4'),
            ));
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
            );
        }

        return $envelope;
    }
}
