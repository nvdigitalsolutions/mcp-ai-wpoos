<?php

/**
 * Shared plumbing for the video write tools.
 *
 * Five tools differ only in which operation they ask for and which options they
 * validate; everything after that — resolving a `file_id` to a path, refusing a
 * host with no ffmpeg, storing the result — is identical. Copying it five times
 * would give five slightly different refusal messages, which is exactly what
 * `GeneratedMediaPersister` was introduced to stop for images.
 *
 * This is deliberately thin. It does not declare `execute()`: a tool that could
 * inherit the operation would be able to inherit the wrong one.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;

abstract class AbstractVideoTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly VideoProcessingInterface $video,
        protected readonly ?FileStoreInterface $files = null,
        protected readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    /**
     * The `file_id` first, then the URL — the same precedence every media tool
     * uses, so a generation can be trimmed without a re-upload.
     *
     * @param array<string, mixed> $arguments
     */
    protected function sourcePath(array $arguments): ?string
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId > 0) {
            return $this->files?->getPath($fileId);
        }

        $url = $this->stringParam($arguments, 'video_url');

        return '' !== $url ? $url : null;
    }

    /**
     * One refusal, one wording, for a host with no ffmpeg.
     *
     * The diagnostics page reports the `video` domain as unavailable in this
     * state, so the message points there rather than at a config file the
     * operator may not own.
     */
    protected function engineRefusal(string $operation, string $capability): mixed
    {
        if ($this->video->supports($operation)) {
            return null;
        }

        return $this->errors->create(
            'no_video_engine',
            'The bound video engine cannot ' . $capability . ': the media worker needs ffmpeg and ffprobe, and reports the `video` domain as unavailable until then.',
        );
    }

    /**
     * Store the produced file and return the tool envelope.
     *
     * `$mime` is derived from the container rather than guessed, because a
     * `.webm` stored as `video/mp4` is a file no player will open.
     *
     * @param array{path: string, operation: string, format: string, bytes: int, original_bytes: int, savings_percent: float} $result
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    protected function store(array $result, string $prefix, array $arguments, array $context, string $message): array
    {
        $format = (string) $result['format'];

        $payload = array(
            'format' => $format,
            'bytes' => $result['bytes'],
            'original_bytes' => $result['original_bytes'],
            'savings_percent' => $result['savings_percent'],
        );

        if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
            $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                $payload,
                (string) $result['path'],
                $context,
                GeneratedMediaPersister::displayName($arguments, $context, $prefix),
                $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                self::mime($format),
            );
        }

        return $this->success($message, $payload);
    }

    /**
     * The MIME type for a container.
     */
    protected static function mime(string $format): string
    {
        return match ($format) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
