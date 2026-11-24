<?php

/**
 * Read a video's container, duration and streams.
 *
 * Proposal 164 §164.4.5. The read-only half of the video family, and the tool that
 * makes the other five aimable: an agent that checks the source width before
 * asking for 1080p downscales nothing it could not see.
 *
 * `extract_video_metadata` is the WordPress toolkit's second slug for the same
 * job and is deliberately **not** ported — two tools that answer the same report
 * teach a model that one of them is the "other" one, and there is no crate of
 * information the second would add.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;

class GetVideoMetadataTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly VideoProcessingInterface $video,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_video_metadata';
    }

    public function getName(): string
    {
        return 'Get Video Metadata';
    }

    public function getDescription(): string
    {
        return 'Read a video\'s container, duration, bitrate and its video and audio streams (codec, dimensions, frame rate, channels). Read-only; needs ffprobe on the media worker.';
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
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'read-only', 'requires-capability');
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

        if (! $this->video->supports('thumbnail') && ! $this->video->supports('trim')) {
            return $this->errors->create(
                'no_video_engine',
                'No video engine is available on this host: the media worker needs ffmpeg and ffprobe. Diagnostics reports the `video` domain as unavailable until then.',
            );
        }

        try {
            $info = $this->video->info($source);
        } catch (\Throwable $e) {
            return $this->errors->create('video_info_failed', $e->getMessage());
        }

        $video = $info['video'];

        return $this->success(
            null === $video
                ? 'Read the container; the file has no video stream.'
                : \sprintf('%dx%d %s, %.1fs.', $video['width'], $video['height'], $video['codec'], $info['duration']),
            $info,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function sourcePath(array $arguments): ?string
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId > 0) {
            return $this->files?->getPath($fileId);
        }

        $url = $this->stringParam($arguments, 'video_url');

        return '' !== $url ? $url : null;
    }
}
