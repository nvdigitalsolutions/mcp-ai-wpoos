<?php

/**
 * Extract a set of frames from a video.
 *
 * Proposal 164 §164.25. The WordPress toolkit has both `generate_video_thumbnails`
 * (one cover image) and `extract_video_frames` (a contact sheet). The worker's
 * `extract_frames` operation has served the second since the route was written;
 * what was missing was a return shape for many files, which is why this tool has
 * its own contract rather than riding `VideoProcessingInterface`.
 *
 * ## Three ways to choose the moments
 *
 * `timestamps` names them, `interval` asks for a cadence, `frame_count` asks for
 * an even spread. They are alternatives, not additions — "every 5 s and also at
 * these moments" is a list, and the list is `timestamps` — so sending
 * `timestamps` together with `interval` is refused rather than resolved by a
 * rule the caller has to guess.
 *
 * ## `save_to_media` defaults to false
 *
 * A contact sheet is scratch material: frames for an agent to look at, or for a
 * person to pick a cover from. Storing all twenty by default would fill a media
 * library with near-duplicates on every call, so the default matches the
 * WordPress tool and each frame is attached only when asked for.
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
use Nvoos\Core\Application\Media\MediaDerivative;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoCompositionInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\VideoFrameExtractionInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;

class ExtractVideoFramesTool extends AbstractVideoTool
{
    /**
     * The WordPress schema's ceiling: twenty frames is a contact sheet, more is
     * a filmstrip nobody reads.
     */
    private const MAX_FRAMES = 20;

    public function __construct(
        ErrorFactoryInterface $e,
        VideoProcessingInterface $video,
        ?FileStoreInterface $files = null,
        ?FileVisibilityStoreInterface $store = null,
        ?VideoCompositionInterface $composition = null,
        private readonly ?VideoFrameExtractionInterface $frames = null,
    ) {
        parent::__construct($e, $video, $files, $store, $composition);
    }

    public function getSlug(): string
    {
        return 'extract_video_frames';
    }

    public function getName(): string
    {
        return 'Extract Video Frames';
    }

    public function getDescription(): string
    {
        return 'Extract a set of JPEG frames from a video — named timestamps, a fixed interval, or a count spread evenly across the clip. The first frame is often a black fade-in, so mid-clip frames usually read better as covers. Needs ffmpeg on the media worker.';
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
                'timestamps' => array(
                    'type' => 'array',
                    'description' => 'Seconds to seek to, in the order to extract them. Give this, interval, or frame_count — not more than one of the first two.',
                    'items' => array('type' => 'number', 'minimum' => 0),
                    'maxItems' => self::MAX_FRAMES,
                ),
                'interval' => array(
                    'type' => 'number',
                    'description' => 'Seconds between frames, starting at 0. Needs a readable duration.',
                    'minimum' => 0.1,
                    'maximum' => 3600,
                ),
                'frame_count' => array(
                    'type' => 'integer',
                    'description' => 'Frames spread evenly across the clip, used when neither timestamps nor interval is given.',
                    'minimum' => 1,
                    'maximum' => self::MAX_FRAMES,
                    'default' => 10,
                ),
                'width' => array(
                    'type' => 'integer',
                    'description' => 'Scale each frame to this width in pixels, keeping the aspect ratio.',
                    'minimum' => 16,
                    'maximum' => 8192,
                ),
                'quality' => array(
                    'type' => 'integer',
                    'description' => 'JPEG quality, 1 (best) to 31 (worst). 2 is the worker default.',
                    'minimum' => 1,
                    'maximum' => 31,
                    'default' => 2,
                ),
                'save_to_media' => array(
                    'type' => 'boolean',
                    'description' => 'Attach each frame to the media library. Off by default: a contact sheet is usually scratch material.',
                    'default' => false,
                ),
                'visibility' => array(
                    'type' => 'string',
                    'description' => 'Visibility for the stored frames, when save_to_media is on.',
                    'enum' => array('public', 'private'),
                    'default' => 'public',
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Extraction writes files, so it carries the same flags as the thumbnail
     * tool: the source is untouched, but the call is not read-only.
     *
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

        $timestamps = $this->timestamps($arguments);
        $interval = $this->floatParam($arguments, 'interval', 0.0);

        // Two modes are two answers to the same question. Resolving them by a
        // hidden precedence would give a contact sheet nobody asked for.
        if (array() !== $timestamps && $interval > 0.0) {
            return $this->errors->validationFailed(
                'Give timestamps or interval, not both: they are alternative ways to choose the moments.',
                array(
                    'timestamps' => array('A list of seconds.'),
                    'interval' => array('Seconds between frames.'),
                ),
            );
        }

        if ($interval > 0.0 && ($interval < 0.1 || $interval > 3600.0)) {
            return $this->errors->validationFailed(
                'interval must be between 0.1 and 3600 seconds.',
                array('interval' => array('Got ' . $interval . '.')),
            );
        }

        $count = $this->intParam($arguments, 'frame_count', 10);

        if ($count < 1 || $count > self::MAX_FRAMES) {
            return $this->errors->validationFailed(
                'frame_count must be between 1 and ' . self::MAX_FRAMES . '.',
                array('frame_count' => array('Got ' . $count . '.')),
            );
        }

        $quality = $this->intParam($arguments, 'quality', 2);

        if ($quality < 1 || $quality > 31) {
            return $this->errors->validationFailed(
                'quality must be between 1 and 31, where 1 is the best.',
                array('quality' => array('Got ' . $quality . '.')),
            );
        }

        $width = $this->intParam($arguments, 'width');

        if (0 !== $width && ($width < 16 || $width > 8192)) {
            return $this->errors->validationFailed(
                'width must be between 16 and 8192 pixels.',
                array('width' => array('Got ' . $width . '.')),
            );
        }

        if (null === $this->frames || ! $this->frames->supports()) {
            return $this->engineRefusalMessage('extract frames');
        }

        $options = array('quality' => $quality);
        $mode = 'even';

        if (array() !== $timestamps) {
            $options['timestamps'] = $timestamps;
            $mode = 'timestamps';
        } elseif ($interval > 0.0) {
            $options['interval'] = $interval;
            $mode = 'interval';
        } else {
            $options['count'] = $count;
        }

        if (0 !== $width) {
            $options['width'] = $width;
        }

        try {
            $result = $this->frames->extract($source, $options);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('frame_extraction_failed', $e->getMessage());
        }

        return $this->success(
            \sprintf(
                'Extracted %d frame(s) from a %.2f s video.',
                (int) $result['frame_count'],
                (float) $result['source_duration'],
            ),
            array(
                'frame_count' => (int) $result['frame_count'],
                'source_duration' => (float) $result['source_duration'],
                'mode' => $mode,
                'frames' => $this->framePayloads($result['frames'], $arguments, $context),
            ),
        );
    }

    /**
     * The `timestamps` argument as numbers, in the caller's order.
     *
     * Order is preserved for the same reason a merge preserves it: a caller who
     * named the moments expects frame 0 to be the first one named.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<float>
     */
    private function timestamps(array $arguments): array
    {
        $raw = $arguments['timestamps'] ?? array();
        $raw = \is_array($raw) ? $raw : array($raw);
        $values = array();

        foreach ($raw as $value) {
            if (! \is_numeric($value)) {
                continue;
            }

            $number = (float) $value;

            if ($number < 0.0) {
                continue;
            }

            $values[] = $number;
        }

        return $values;
    }

    /**
     * One entry per frame, attached to the media library when asked for.
     *
     * @param list<array{path: string, bytes: int}> $frames
     * @param array<string, mixed>                  $arguments
     * @param array<string, mixed>                  $context
     *
     * @return list<array<string, mixed>>
     */
    private function framePayloads(array $frames, array $arguments, array $context): array
    {
        $save = $this->boolParam($arguments, 'save_to_media', false);
        $persister = null !== $this->store ? new GeneratedMediaPersister($this->store) : null;
        $visibility = $this->stringParam(
            $arguments,
            'visibility',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
        );

        $payloads = array();

        foreach ($frames as $index => $frame) {
            $entry = array(
                'index' => (int) $index,
                'bytes' => (int) $frame['bytes'],
            );

            if ($save && null !== $persister) {
                // `+` rather than `array_merge`: the stored identifiers must win
                // over the local path the persister consumed.
                $entry = $persister->attachFile(
                    $entry,
                    $frame['path'],
                    $context,
                    GeneratedMediaPersister::displayName($arguments, $context, 'frame-' . ( (int) $index + 1 )),
                    $visibility,
                    self::mime('jpg'),
                    MediaDerivative::forSource(
                        $this->sourceFileId($arguments),
                        FileDerivativeStoreInterface::ROLE_FRAME,
                    ),
                ) + $entry;
            } else {
                $entry['path'] = $frame['path'];
            }

            $payloads[] = $entry;
        }

        return $payloads;
    }
}
