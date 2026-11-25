<?php

/**
 * Burn a watermark into a video.
 *
 * Proposal 164 §164.19. The WordPress tool with this slug is a stub — its
 * `execute()` is a `// TODO` followed by a success envelope — so this one does
 * the work. It is also the first composition tool: a watermark needs two
 * inputs, which is why `VideoCompositionInterface` exists rather than a
 * widened `process()`.
 *
 * ## The mark is sized from the video, not from itself
 *
 * `scale` is a fraction of the **video's** width, so the same settings give a
 * 4K clip and a 720p clip the same visual weight. A watermark that is 200 px on
 * one and 200 px on the other is either invisible or enormous. The route probes
 * the video's width to resolve that before the graph is built.
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

class AddWatermarkToVideoTool extends AbstractVideoTool
{
    private const POSITIONS = array('top-left', 'top-right', 'bottom-left', 'bottom-right', 'center');

    public function getSlug(): string
    {
        return 'add_watermark_to_video';
    }

    public function getName(): string
    {
        return 'Add Watermark to Video';
    }

    public function getDescription(): string
    {
        return 'Burn a watermark image into a video at a chosen corner or the centre, sized as a fraction of the video width so it looks the same at any resolution. The video is re-encoded; the audio is kept.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'video_url' => array(
                    'type' => 'string',
                    'description' => 'Path or URL of the video to mark. Give this or file_id.',
                ),
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the video.',
                    'minimum' => 1,
                ),
                'watermark_url' => array(
                    'type' => 'string',
                    'description' => 'Path or URL of the watermark image (PNG with alpha, or JPEG). Give this or watermark_file_id.',
                ),
                'watermark_file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the watermark image.',
                    'minimum' => 1,
                ),
                'position' => array(
                    'type' => 'string',
                    'description' => 'Where the mark sits, inset by a small gutter from the video edge.',
                    'enum' => self::POSITIONS,
                    'default' => 'bottom-right',
                ),
                'opacity' => array(
                    'type' => 'number',
                    'description' => 'Mark opacity, 0 (invisible) to 1 (solid).',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.7,
                ),
                'scale' => array(
                    'type' => 'number',
                    'description' => 'Mark width as a fraction of the video width.',
                    'minimum' => 0.01,
                    'maximum' => 1,
                    'default' => 0.2,
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container.',
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
        $video = $this->sourcePath($arguments);

        if (null === $video) {
            return $this->errors->validationFailed(
                'video_url or file_id is required.',
                array('file_id' => array('Give the id of the stored video.')),
            );
        }

        $mark = $this->markPath($arguments);

        if (null === $mark) {
            return $this->errors->validationFailed(
                'watermark_url or watermark_file_id is required: a watermark needs an image.',
                array('watermark_file_id' => array('Give the id of the stored watermark image.')),
            );
        }

        $position = \strtolower(\trim($this->stringParam($arguments, 'position', 'bottom-right')));

        if (! \in_array($position, self::POSITIONS, true)) {
            return $this->errors->validationFailed(
                'Unknown position: ' . $position . '. Supported: ' . \implode(', ', self::POSITIONS) . '.',
                array('position' => array('Supported: ' . \implode(', ', self::POSITIONS) . '.')),
            );
        }

        $opacity = (float) ($arguments['opacity'] ?? 0.7);
        $scale = (float) ($arguments['scale'] ?? 0.2);

        if (null === $this->composition || ! $this->composition->supports('watermark')) {
            return $this->engineRefusalMessage('add a watermark');
        }

        try {
            $result = $this->composition->compose(
                'watermark',
                array($video, $mark),
                array(
                    'position' => $position,
                    'opacity' => $opacity,
                    'scale' => $scale,
                    'format' => $this->stringParam($arguments, 'format', 'mp4'),
                ),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('watermark_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'watermarked-video',
            $arguments,
            $context,
            \sprintf(
                'Watermarked at %s, %.0f%% opacity, sized at %.0f%% of the width.',
                $position,
                $opacity * 100,
                $scale * 100,
            ),
        );
    }

    /**
     * The mark's path, resolved the same way as the video's.
     *
     * @param array<string, mixed> $arguments
     */
    private function markPath(array $arguments): ?string
    {
        $fileId = $this->intParam($arguments, 'watermark_file_id');

        if ($fileId > 0) {
            return $this->files?->getPath($fileId);
        }

        $url = $this->stringParam($arguments, 'watermark_url');

        return '' !== $url ? $url : null;
    }
}
