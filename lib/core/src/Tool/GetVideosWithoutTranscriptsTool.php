<?php

/**
 * Tool: get_videos_without_transcripts — the caption gap, as the registry knows it.
 *
 * Port of `WP_MCP_AI_Tool_Get_Videos_Without_Transcripts` under Proposal 164
 * §164.46. WordPress asks for attachments that lack transcript or caption meta.
 *
 * ## What counts as a transcript here
 *
 * A **caption sidecar** — the `.srt` or `.vtt` that `generate_video_captions`
 * writes and stores. That is the only transcript artefact this runtime keeps:
 * `transcribe_video` returns text in its payload and stores nothing, so a
 * transcript that was never saved is not a record and this tool will list the
 * video again. Saying so in the description matters, because a caller who has
 * just transcribed a video will otherwise read the repeat as a bug.
 *
 * WCAG 2.2 SC 1.2.2 (captions, Level A) is the reason the gap is worth measuring:
 * a video with no caption file is one a deaf or hard-of-hearing viewer cannot
 * follow.
 *
 * ## Bounded, and it says so
 *
 * At most `limit` videos, and `truncated` when the limit was reached. A video
 * older than the registry's scan window is not listed at all, never listed as
 * missing a caption it may have.
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

final class GetVideosWithoutTranscriptsTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'get_videos_without_transcripts';
    }

    public function getName(): string
    {
        return 'Videos Without Transcripts';
    }

    public function getDescription(): string
    {
        return 'Lists stored videos that have no caption or transcript file recorded against them. A caption counts when generate_video_captions saved it from that video (an .srt or .vtt sidecar); a transcript returned as text and never saved is not a record, so that video will be listed again. Read-only. Returns at most `limit` videos and sets `truncated` when the limit was reached.';
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
            $report = $gaps->report(FileDerivativeStoreInterface::ROLE_CAPTION, $limit);
        } catch (\Throwable $error) {
            return $this->errors->create('video_inventory_failed', $error->getMessage());
        }

        return $this->success(
            sprintf('%d of %d video(s) have no caption file.', $report['count'], $report['examined']),
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
            'The file registry on this host cannot record where a file came from, so a caption cannot be told from an unrelated file. The host needs an adapter that implements the derivative registry.',
        );
    }
}
