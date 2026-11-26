<?php

/**
 * Re-encode a video smaller for delivery.
 *
 * Proposal 164 §164.4.5. The worker's `compress` operation uses a **fixed**
 * H.264 CRF 28 at preset `fast` and puts the moov atom first (`+faststart`), so
 * playback starts before the file finishes downloading.
 *
 * There is no `quality` argument, and that is deliberate: the route has no
 * quality knob, and offering one whose effect is a hard-coded constant is the
 * same lie as a `model` argument that is ignored. `convert_video_format` is the
 * tool for a different container, and `resize_video_resolution` is the tool for
 * fewer pixels.
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

class CompressVideoTool extends AbstractVideoTool
{
    public function getSlug(): string
    {
        return 'compress_video';
    }

    public function getName(): string
    {
        return 'Compress Video';
    }

    public function getDescription(): string
    {
        return 'Re-encode a video smaller with H.264 at a fixed CRF, with the index moved to the front so playback starts immediately. There is no quality setting: the encoder profile is fixed.';
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
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container. Default mp4; `+faststart` applies to mp4 and mov.',
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
        $source = $this->sourcePath($arguments);

        if (null === $source) {
            return $this->errors->validationFailed(
                'video_url or file_id is required.',
                array('file_id' => array('Give the id of a stored video.')),
            );
        }

        $refusal = $this->engineRefusal('compress', 'compress');

        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $result = $this->video->process($source, 'compress', array(
                'format' => $this->stringParam($arguments, 'format', 'mp4'),
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('compress_failed', $e->getMessage());
        }

        return $this->store(
            $result,
            'compressed-video',
            $arguments,
            $context,
            \sprintf(
                'Compressed to %d bytes (%.1f%% saved).',
                (int) $result['bytes'],
                (float) $result['savings_percent'],
            ),
        );
    }
}
