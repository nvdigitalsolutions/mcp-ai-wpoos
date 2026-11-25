<?php

/**
 * Transcribe the audio track of stored videos.
 *
 * Proposal 164 §164.27. The WordPress tool with this slug does not transcribe:
 * it stamps `_mcp_video_transcribe_status = pending` on each attachment and
 * reports "queued". There is no queue in this runtime and no attachment meta to
 * stamp, so a faithful port would be a tool that takes money-shaped arguments
 * and does nothing. This one does the work: it pulls the audio, sends it to the
 * configured speech-to-text provider, and returns the transcript per item.
 *
 * ## Why the batch shape survived
 *
 * `video_ids` is an array and the answer is `processed[]` + `errors[]`, because
 * that is the contract the toolkit publishes and an agent written against the
 * WordPress docs will send a list. Per-item failure is what makes a batch
 * usable: one video with no audio track must not lose the other nineteen
 * transcripts.
 *
 * ## `dry_run` defaults to false — a deliberate deviation
 *
 * WordPress defaults it to `true` because the tool cannot process anything, so
 * "do nothing" is the only honest default it has. Here the work is real, and a
 * default that silently performs no work is a trap: an agent that passes
 * `video_ids` and reads `success: true` would believe it had transcripts. The
 * parameter is honoured — a dry run probes each source and reports duration and
 * whether an audio track exists, without uploading to a provider.
 *
 * ## The extracted audio is scratch
 *
 * Transcription needs an audio file, and OpenAI's ceiling is 25 MB. A video's
 * audio is extracted at 64 kbps — speech is downmixed to 16 kHz mono by the
 * provider anyway, so a higher bitrate buys nothing and costs headroom: 64 kbps
 * keeps roughly fifty minutes inside the limit. The temporary file is removed
 * whether the transcription succeeded or not, because nothing downstream wants
 * it.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AudioTranscriptionInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoCompositionInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

class TranscribeVideoTool extends AbstractVideoTool
{
    /**
     * Speech-to-text bitrate for the extracted track.
     */
    private const EXTRACT_BITRATE = '64k';

    public function __construct(
        ErrorFactoryInterface $e,
        VideoProcessingInterface $video,
        ?FileStoreInterface $files = null,
        ?FileVisibilityStoreInterface $store = null,
        ?VideoCompositionInterface $composition = null,
        private readonly ?AudioTranscriptionInterface $transcription = null,
    ) {
        parent::__construct($e, $video, $files, $store, $composition);
    }

    public function getSlug(): string
    {
        return 'transcribe_video';
    }

    public function getName(): string
    {
        return 'Transcribe Video';
    }

    public function getDescription(): string
    {
        return 'Transcribe one or more stored videos to text, with timings when asked for. The audio track is extracted first (a stored audio file is transcribed directly), so this needs ffmpeg on the media worker for video and only an OpenAI key for audio. Reports per-item failures rather than failing the batch.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'video_ids' => array(
                    'type' => 'array',
                    'description' => 'Stored file ids of the videos (or audio files) to transcribe.',
                    'items' => array('type' => 'integer', 'minimum' => 1),
                    'minItems' => 1,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'ISO 639-1 language of the speech. A hint, not a filter; "auto" omits it and lets the provider detect.',
                    'default' => 'en',
                ),
                'include_segments' => array(
                    'type' => 'boolean',
                    'description' => 'Include the timed segments as well as the text. Off by default: a transcript is the text, and the segments can be several times its size.',
                    'default' => false,
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'Report what would be transcribed — duration, audio track, size — without uploading to the provider. Defaults to false: unlike the WordPress tool, this one performs the work.',
                    'default' => false,
                ),
            ),
            'required' => array('video_ids'),
            'additionalProperties' => false,
        );
    }

    /**
     * The media capability, as for every other video-production tool.
     *
     * The WordPress tool declares `edit_posts`, which in that runtime is the
     * capability the attachment queue is guarded by. Nothing here is guarded by
     * a post: the operation reads stored media, so it takes the capability that
     * governs stored media.
     */
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $ids = array_values(array_filter(
            $this->intListParam($arguments, 'video_ids'),
            static fn (int $id): bool => $id > 0,
        ));

        if (array() === $ids) {
            return $this->errors->validationFailed(
                'At least one video id is required.',
                array('video_ids' => array('Give one or more stored file ids.')),
            );
        }

        $dryRun   = $this->boolParam($arguments, 'dry_run', false);
        $language = $this->language($arguments);

        if (! $dryRun) {
            if (null === $this->transcription) {
                return $this->errors->create(
                    'no_transcription_engine',
                    'No speech-to-text engine is bound: this host needs the transcription adapter and an OpenAI key.',
                );
            }

            if (! $this->transcription->isAvailable()) {
                return $this->errors->create('missing_key', 'No OpenAI API key configured.');
            }

            $refusal = $this->engineRefusalFor($ids, 'transcribe');

            if (null !== $refusal) {
                return $refusal;
            }
        }

        $processed = array();
        $errors    = array();

        foreach ($ids as $id) {
            $entry = $this->one($id, $arguments, $dryRun, $language);

            if ($entry instanceof \Throwable) {
                $errors[] = array('video_id' => $id, 'message' => $entry->getMessage());
                continue;
            }

            $processed[] = $entry;
        }

        $message = $dryRun
            ? \sprintf(
                'Dry run: %d source(s) could be transcribed, %d could not be resolved.',
                \count($processed),
                \count($errors),
            )
            : \sprintf(
                'Transcribed %d of %d source(s).',
                \count($processed),
                \count($ids),
            );

        return $this->success($message, array(
            'dry_run'    => $dryRun,
            'processed'  => $processed,
            'errors'     => $errors,
            'total'      => \count($ids),
            'successful' => \count($processed),
            'failed'     => \count($errors),
        ));
    }

    /**
     * One source, or the reason it produced nothing.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|\Throwable
     */
    private function one(int $id, array $arguments, bool $dryRun, string $language): array|\Throwable
    {
        $extracted = null;

        try {
            $path = $this->files?->getPath($id);

            if (null === $path || '' === $path) {
                return new \RuntimeException('No stored file with id ' . $id . '.');
            }

            $meta = $this->files?->getMetadata($id);

            if ($dryRun) {
                return $this->preview($id, $path, $meta, $language);
            }

            // A stored audio file is transcribed as it is: extracting it would
            // copy the same track into a temporary file for no gain.
            $isAudio = null !== $meta && \str_starts_with($meta->mimeType, 'audio/');

            if ($isAudio) {
                $audioPath = $path;
            } else {
                $produced  = $this->video->process($path, 'extract_audio', array(
                    'format'        => 'mp3',
                    'audio_bitrate' => self::EXTRACT_BITRATE,
                ));

                $extracted = $audioPath = (string) $produced['path'];
            }

            $transcript = $this->transcription->transcribe($audioPath, array('language' => $language));

            $entry = array(
                'video_id'      => $id,
                'status'        => 'transcribed',
                'language'      => $transcript['language'] ?? $language,
                'duration'      => $transcript['duration'],
                'model'         => $transcript['model'],
                'text'          => $transcript['text'],
                'segment_count' => \count($transcript['segments']),
            );

            if ($this->boolParam($arguments, 'include_segments', false)) {
                $entry['segments'] = $transcript['segments'];
            }

            return $entry;
        } catch (\Throwable $error) {
            return $error;
        } finally {
            // The extracted track exists only for this call; a batch of twenty
            // would otherwise leave twenty audio files behind.
            if (null !== $extracted && \is_file($extracted)) {
                \unlink($extracted);
            }
        }
    }

    /**
     * What a dry run can say without spending money.
     *
     * A stored audio file needs no extraction, so it is describable on a host
     * with no media worker at all — which is why the probe is skipped there and
     * `duration` is honestly null rather than invented.
     *
     * @param \Nvoos\Core\Domain\Entity\StoredFile|null $meta
     *
     * @return array<string, mixed>
     */
    private function preview(int $id, string $path, ?StoredFile $meta, string $language): array
    {
        $isAudio = null !== $meta && \str_starts_with($meta->mimeType, 'audio/');

        $entry = array(
            'video_id'            => $id,
            'status'              => 'dry_run',
            'language'            => $language,
            'mime_type'           => $meta?->mimeType,
            'size_bytes'          => $meta?->sizeBytes ?? (\is_file($path) ? (int) \filesize($path) : null),
            'requires_extraction' => ! $isAudio,
            'duration'            => null,
            'audio_present'       => $isAudio ? true : null,
        );

        if ($isAudio) {
            return $entry;
        }

        $info = $this->video->info($path);

        $entry['duration']      = $info['duration'];
        $entry['audio_present'] = null !== $info['audio'];

        return $entry;
    }

    /**
     * Refuse once, before the loop, when a video target needs an engine the
     * worker cannot serve.
     *
     * A stored audio file is transcribed without the worker, so the refusal only
     * applies when at least one target is a video. Checking up-front keeps the
     * wording identical to every other video tool instead of forking it into a
     * per-item message, and avoids uploading a queue of videos to find out.
     *
     * An id that resolves to nothing is skipped rather than diagnosed as a
     * missing engine: the loop reports "no stored file with id N" for it, and a
     * typo should not be answered with a sentence about ffmpeg.
     *
     * @param list<int> $ids
     */
    private function engineRefusalFor(array $ids, string $capability): mixed
    {
        foreach ($ids as $id) {
            $meta = $this->files?->getMetadata($id);

            if (null === $meta || \str_starts_with($meta->mimeType, 'audio/')) {
                continue;
            }

            return $this->engineRefusal('extract_audio', $capability . ' video');
        }

        return null;
    }

    /**
     * The language hint, with `auto` meaning "omit it".
     *
     * The WordPress default is `en`, and it is kept — but a caller transcribing
     * Spanish audio must be able to say "detect" rather than being quietly
     * decoded as English, which is what an always-sent `language` would do.
     *
     * @param array<string, mixed> $arguments
     */
    private function language(array $arguments): string
    {
        $language = $this->stringParam($arguments, 'language', 'en');

        return 'auto' === \strtolower($language) ? '' : $language;
    }
}
