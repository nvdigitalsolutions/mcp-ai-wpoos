<?php

/**
 * Fit a video to one platform's recommended frame.
 *
 * Proposal 164 §164.4.5 follow-on.
 *
 * ## Why this is not a rename of `resize_video_resolution`
 *
 * `resize` preserves the aspect ratio, so a 16:9 source sent to a 1080×1920
 * request comes back 1080×607 — a letterbox-shaped speck in a vertical feed.
 * A platform preset has to *fill the frame*, which means scaling until both
 * sides are covered and then cropping the overflow (`fit: cover`), or padding
 * the remainder (`fit: contain`). That is a different filter chain, so it is a
 * different operation on the route.
 *
 * ## Better than the WordPress tool, deliberately
 *
 * The WordPress `optimize_for_platform` is a stub: its `execute()` is a
 * `// TODO: Implement` followed by `return array( 'success' => true, ... )`.
 * It reports success without touching the file. This one does the work, and
 * refuses a combination that has no frame rather than inventing one.
 *
 * The frames below are the three sizes that cover nearly every surface —
 * 1920×1080 landscape, 1080×1920 vertical, and 1080×1350 for a 4:5 feed post —
 * checked against 2026 platform guidance rather than copied from the stub,
 * which declared no sizes at all.
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

class OptimizeForPlatformTool extends AbstractVideoTool
{
    /**
     * platform → content type → [width, height, aspect label].
     *
     * Only combinations that exist are listed. A missing pair is refused with
     * the ones the platform does support, because silently substituting a
     * different frame is how a vertical video ends up pillar-boxed.
     *
     * @var array<string, array<string, array{0: int, 1: int, 2: string}>>
     */
    private const PRESETS = array(
        'youtube' => array(
            'feed' => array(1920, 1080, '16:9'),
            'short' => array(1080, 1920, '9:16'),
        ),
        'instagram' => array(
            // 4:5 is the tallest ratio the feed accepts; 1:1 still works but
            // uses less of the screen.
            'feed' => array(1080, 1350, '4:5'),
            'story' => array(1080, 1920, '9:16'),
            'reel' => array(1080, 1920, '9:16'),
        ),
        'tiktok' => array(
            'feed' => array(1080, 1920, '9:16'),
            'story' => array(1080, 1920, '9:16'),
            'reel' => array(1080, 1920, '9:16'),
            'short' => array(1080, 1920, '9:16'),
        ),
        'facebook' => array(
            'feed' => array(1280, 720, '16:9'),
            'story' => array(1080, 1920, '9:16'),
            'reel' => array(1080, 1920, '9:16'),
        ),
        'twitter' => array(
            'feed' => array(1280, 720, '16:9'),
        ),
    );

    public function getSlug(): string
    {
        return 'optimize_for_platform';
    }

    public function getName(): string
    {
        return 'Optimize for Platform';
    }

    public function getDescription(): string
    {
        return 'Fit a video to one platform\'s recommended frame (YouTube, Instagram, TikTok, Facebook, X) by scaling to the frame and cropping or padding the overflow. Offers the whole frame, not a letterbox-shaped video inside it.';
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
                'platform' => array(
                    'type' => 'string',
                    'description' => 'Target platform.',
                    'enum' => array('youtube', 'instagram', 'tiktok', 'facebook', 'twitter'),
                ),
                'content_type' => array(
                    'type' => 'string',
                    'description' => 'Placement. Not every platform has every placement; an unsupported pair is refused with the ones it does support.',
                    'enum' => array('feed', 'story', 'reel', 'short'),
                    'default' => 'feed',
                ),
                'fit' => array(
                    'type' => 'string',
                    'description' => '"cover" fills the frame and crops the overflow; "contain" keeps every pixel and pads the remainder.',
                    'enum' => array('cover', 'contain'),
                    'default' => 'cover',
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Output container. Default mp4; every platform above accepts it.',
                    'enum' => array('mp4', 'webm', 'mov'),
                    'default' => 'mp4',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('platform'),
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

        $platform = \strtolower(\trim($this->stringParam($arguments, 'platform')));

        if (! isset(self::PRESETS[$platform])) {
            return $this->errors->validationFailed(
                'Unknown platform: ' . ('' === $platform ? '(none given)' : $platform)
                    . '. Supported: ' . \implode(', ', \array_keys(self::PRESETS)) . '.',
                array('platform' => array('Supported: ' . \implode(', ', \array_keys(self::PRESETS)) . '.')),
            );
        }

        $contentType = \strtolower(\trim($this->stringParam($arguments, 'content_type', 'feed')));

        if (! isset(self::PRESETS[$platform][$contentType])) {
            $supported = \implode(', ', \array_keys(self::PRESETS[$platform]));

            // The supported list is repeated in the message because some hosts'
            // error factories keep only the message and drop the field errors.
            return $this->errors->validationFailed(
                \sprintf(
                    '%s has no "%s" placement. Supported: %s.',
                    \ucfirst($platform),
                    '' === $contentType ? '(none given)' : $contentType,
                    $supported,
                ),
                array('content_type' => array('Supported for ' . $platform . ': ' . $supported . '.')),
            );
        }

        $fit = \strtolower(\trim($this->stringParam($arguments, 'fit', 'cover')));

        if (! \in_array($fit, array('cover', 'contain'), true)) {
            return $this->errors->validationFailed(
                'fit must be either cover or contain.',
                array('fit' => array('Got ' . $fit . '.')),
            );
        }

        $refusal = $this->engineRefusal('fit', 'fit a frame');

        if (null !== $refusal) {
            return $refusal;
        }

        [$width, $height, $aspect] = self::PRESETS[$platform][$contentType];

        try {
            $result = $this->video->process($source, 'fit', array(
                'width' => $width,
                'height' => $height,
                'fit' => $fit,
                'format' => $this->stringParam($arguments, 'format', 'mp4'),
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('platform_optimization_failed', $e->getMessage());
        }

        $envelope = $this->store(
            $result,
            'platform-video',
            $arguments,
            $context,
            \sprintf('Fitted to %s %s at %d×%d (%s), %s.', $platform, $contentType, $width, $height, $aspect, $fit),
        );

        // `store()` owns persistence and keeps a fixed payload, so the preset
        // is added here rather than by widening that shared helper.
        if (\is_array($envelope['data'] ?? null)) {
            $envelope['data'] += array(
                'platform' => $platform,
                'content_type' => $contentType,
                'width' => $width,
                'height' => $height,
                'aspect' => $aspect,
                'fit' => $fit,
            );
        }

        return $envelope;
    }
}
