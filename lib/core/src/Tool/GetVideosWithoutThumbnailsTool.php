<?php

/**
 * Tool: get_videos_without_thumbnails — the poster gap, as the registry knows it.
 *
 * Port of `WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails` under Proposal 164
 * §164.46. The WordPress tool queries attachments lacking `_thumbnail_id`, which
 * is a relationship WordPress maintains for free.
 *
 * This runtime had no equivalent, so the port waited for one: §164.46 gave the
 * registry `derived_from` / `derived_role`, and this is the first reader. A video
 * is reported only when **no** stored file was produced from it as a poster,
 * which is a fact rather than an inference — an image that merely looks like a
 * thumbnail is not counted.
 *
 * ## What it deliberately does not do
 *
 * WordPress filters by target platform (`youtube`, `vimeo`, `local`). Nothing in
 * this runtime records a target platform — the only writer would be
 * `upload_video_batch`, which is deferred — so the parameter is absent rather than
 * accepted and ignored.
 *
 * ## Bounded, and it says so
 *
 * The registry answers metadata queries by scanning its newest rows, so this is a
 * window: at most `limit` videos, and `truncated` when the limit was reached. A
 * video older than the scan is not listed at all, never listed as missing a
 * poster it may have.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\VideoAssetGaps;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class GetVideosWithoutThumbnailsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Mirrors the WordPress default.
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * The registry caps a metadata read, so promising more would be a lie.
     */
    public const MAX_LIMIT = 100;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_videos_without_thumbnails';
    }

    public function getName(): string
    {
        return 'Videos Without Thumbnails';
    }

    public function getDescription(): string
    {
        return 'Lists stored videos that have no poster recorded against them. A poster counts when generate_video_thumbnails produced it from that video, because the registry records the link; an image that merely looks like a thumbnail is not counted. Read-only. Returns at most `limit` videos and sets `truncated` when the limit was reached.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'How many videos to examine. Default ' . self::DEFAULT_LIMIT . '; the registry caps a metadata read at ' . self::MAX_LIMIT . '.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
            ),
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
        return array('read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $gaps   = new VideoAssetGaps($this->files);
        $reason = $gaps->unavailableReason();

        if (null !== $reason) {
            return $this->refusal($reason);
        }

        $limit = min(self::MAX_LIMIT, max(1, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));

        try {
            $report = $gaps->report(FileDerivativeStoreInterface::ROLE_POSTER, $limit);
        } catch (\Throwable $error) {
            return $this->errors->create('video_inventory_failed', $error->getMessage());
        }

        return $this->success(
            sprintf('%d of %d video(s) have no poster.', $report['count'], $report['examined']),
            $report,
        );
    }

    /**
     * Two hosts, two sentences. One has no registry; the other has a registry
     * that cannot say where a file came from, which is an upgrade rather than a
     * setting.
     */
    private function refusal(string $reason): mixed
    {
        if (VideoAssetGaps::NO_FILE_REGISTRY === $reason) {
            return $this->errors->create(
                $reason,
                'No file registry is bound on this host, so there are no stored videos to inventory.',
            );
        }

        return $this->errors->create(
            $reason,
            'The file registry on this host cannot record where a file came from, so a poster cannot be told from an unrelated image. The host needs an adapter that implements the derivative registry.',
        );
    }
}
