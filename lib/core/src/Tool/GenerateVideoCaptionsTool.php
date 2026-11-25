<?php

/**
 * Generate a subtitle file from a video's speech.
 *
 * Proposal 164 §164.27. The WordPress tool with this slug is a stub — it returns
 * `success: true` with a note that video processing needs FFmpeg, and produces
 * no captions. This one runs the pipeline: extract the audio, transcribe it with
 * timings, format the cues, and (optionally) store the file in the media library.
 *
 * ## The format is built locally, not requested
 *
 * `whisper-1` can answer SRT and VTT directly, but only `whisper-1`: the
 * `gpt-4o-transcribe` family answers JSON without timings, and nothing emits
 * ASS. Asking for `verbose_json` and formatting here keeps all three formats
 * available on one code path, and makes {@see \Nvoos\Core\Application\Media\CaptionFormatter}
 * a pure unit with no provider in its tests.
 *
 * ## `auto_sync` means something
 *
 * `true` uses the provider's segment boundaries — the cues land on the words.
 * `false` re-flows the transcript one sentence per cue and allocates each cue a
 * share of the clip's duration proportional to its length. That is a reading
 * pace, not speech alignment, and `timings` in the answer says which one the
 * caller got. Ignoring the flag would be worse than either behaviour: a caller
 * who turns sync off is asking for something.
 *
 * ## ASS is returned as text and not stored
 *
 * The media registry types a file from its bytes, and an ASS file is
 * indistinguishable from `text/plain` — libmagic says so, which was measured
 * rather than assumed. Stored, it would become a `.txt` that no player treats as
 * a subtitle. Rather than teach the store to trust a caller's extension, the ASS
 * document is returned in the envelope and `media_skipped` says why.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\CaptionFormatter;
use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\AudioTranscriptionInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoCompositionInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;

class GenerateVideoCaptionsTool extends AbstractVideoTool
{
    /**
     * Speech-to-text bitrate for the extracted track.
     */
    private const EXTRACT_BITRATE = '64k';

    /**
     * Cue length when neither the provider nor the container knew the duration.
     */
    private const SECONDS_PER_SENTENCE = 3.0;

    /**
     * Formats the media registry can type from their bytes.
     */
    private const STORABLE = array('srt', 'vtt');

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
        return 'generate_video_captions';
    }

    public function getName(): string
    {
        return 'Generate Video Captions';
    }

    public function getDescription(): string
    {
        return 'Generate timed subtitles (SRT, WebVTT or ASS) from the speech in a stored video or audio file. Cues come from the provider\'s segment timings, or from a reading-paced re-flow when auto_sync is off. SRT and WebVTT can be saved to the media library; ASS is returned as text.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'video_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the video (or audio file). The WordPress parameter name; `file_id` is accepted as a synonym.',
                    'minimum' => 1,
                ),
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id, as used by the other media tools.',
                    'minimum' => 1,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'ISO 639-1 language of the speech. A hint, not a filter; "auto" omits it and lets the provider detect.',
                    'default' => 'en',
                ),
                'format' => array(
                    'type' => 'string',
                    'description' => 'Subtitle container. ASS is returned as text because the media registry cannot tell it apart from plain text.',
                    'enum' => CaptionFormatter::FORMATS,
                    'default' => 'srt',
                ),
                'auto_sync' => array(
                    'type' => 'boolean',
                    'description' => 'true uses the provider\'s segment timings. false re-flows one sentence per cue at a reading pace, proportional to length.',
                    'default' => true,
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
        // `video_id` first because that is the documented WordPress parameter;
        // `file_id` is the name every other media tool here uses.
        $id = $this->intParam($arguments, 'video_id') ?: $this->intParam($arguments, 'file_id');

        if ($id <= 0) {
            return $this->errors->validationFailed(
                'A video_id or file_id is required.',
                array('video_id' => array('Give a stored file id.')),
            );
        }

        $format = \strtolower(\trim($this->stringParam($arguments, 'format', 'srt')));

        if (! \in_array($format, CaptionFormatter::FORMATS, true)) {
            return $this->errors->validationFailed(
                'The caption format must be one of: ' . \implode(', ', CaptionFormatter::FORMATS) . '.',
                array('format' => array('Got ' . $format . '.')),
            );
        }

        if (null === $this->transcription) {
            return $this->errors->create(
                'no_transcription_engine',
                'No speech-to-text engine is bound: this host needs the transcription adapter and an OpenAI key.',
            );
        }

        if (! $this->transcription->isAvailable()) {
            return $this->errors->create('missing_key', 'No OpenAI API key configured.');
        }

        $path = $this->files?->getPath($id);

        if (null === $path || '' === $path) {
            return $this->errors->create('file_not_found', 'No stored file with id ' . $id . '.');
        }

        $meta      = $this->files?->getMetadata($id);
        $isAudio   = null !== $meta && $meta->isAudio();
        $language  = $this->language($arguments);
        $extracted = null;

        try {
            if ($isAudio) {
                $audioPath = $path;
            } else {
                $refusal = $this->engineRefusal('extract_audio', 'extract audio from video');

                if (null !== $refusal) {
                    return $refusal;
                }

                $produced  = $this->video->process($path, 'extract_audio', array(
                    'format'        => 'mp3',
                    'audio_bitrate' => self::EXTRACT_BITRATE,
                ));

                $extracted = $audioPath = (string) $produced['path'];
            }

            $transcript = $this->transcription->transcribe($audioPath, array('language' => $language));
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('captions_failed', $e->getMessage());
        } finally {
            if (null !== $extracted && \is_file($extracted)) {
                \unlink($extracted);
            }
        }

        $duration = $transcript['duration'];

        if (null === $duration && array() !== $transcript['segments']) {
            $duration = (float) $transcript['segments'][ \count($transcript['segments']) - 1 ]['end'];
        }

        $autoSync = $this->boolParam($arguments, 'auto_sync', true);
        $fromProvider = $autoSync && array() !== $transcript['segments'];

        $cues = $fromProvider
            ? $transcript['segments']
            : $this->proportional($transcript['text'], $duration);

        $captions = CaptionFormatter::format($cues, $format);
        $cueCount = \count($this->cues($cues));

        $payload = array(
            'format'    => $format,
            'language'  => $transcript['language'] ?? null,
            'timings'   => $fromProvider ? 'provider' : 'proportional',
            'cue_count' => $cueCount,
            'duration'  => $duration,
            'captions'  => $captions,
        );

        if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
            $payload = $this->attach($payload, $captions, $format, $arguments, $context);
        }

        return $this->success(
            \sprintf('Wrote %d %s cue(s) for file %d.', $cueCount, \strtoupper($format), $id),
            $payload,
        );
    }

    /**
     * Save the subtitle file, or say why it was returned as text only.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function attach(array $payload, string $captions, string $format, array $arguments, array $context): array
    {
        if (! \in_array($format, self::STORABLE, true)) {
            $payload['media_skipped'] = \strtoupper($format)
                . ' has no distinct sniffable type — the registry would file it as plain text — so it is '
                . 'returned in `captions` and not stored.';

            return $payload;
        }

        $temporary = \tempnam(\sys_get_temp_dir(), 'nvoos-caption-');

        if (false === $temporary) {
            $payload['media_failed'] = array('Could not write a temporary file for the captions.');

            return $payload;
        }

        try {
            \file_put_contents($temporary, $captions);

            return (new GeneratedMediaPersister($this->store))->attachFile(
                $payload,
                $temporary,
                $context,
                GeneratedMediaPersister::displayName($arguments, $context, 'captions'),
                $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                self::mime($format),
            );
        } finally {
            if (\is_file($temporary)) {
                \unlink($temporary);
            }
        }
    }

    /**
     * The language hint, with `auto` meaning "omit it".
     *
     * The WordPress default is `en`, and it is kept — but a caller captioning
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

    /**
     * One cue per sentence, each given time in proportion to its length.
     *
     * This is what `auto_sync: false` buys: no alignment to the words, but a
     * stable, readable pace that never outruns the clip. Abbreviations split a
     * sentence in two here — `Mr. Smith` becomes two cues — which is the cost of
     * not sending the text through a tokeniser a caption tool does not have.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function proportional(string $text, ?float $duration): array
    {
        $sentences = \preg_split('/(?<=[.!?])\s+/', \trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: array();

        if (array() === $sentences) {
            return array();
        }

        $weights = \array_map(static fn (string $sentence): int => \max(1, \strlen($sentence)), $sentences);
        $total   = \array_sum($weights);
        $span    = (null !== $duration && $duration > 0)
            ? $duration
            : self::SECONDS_PER_SENTENCE * \count($sentences);

        $cursor = 0.0;
        $cues   = array();

        foreach ($sentences as $index => $sentence) {
            $share = $span * ($weights[ $index ] / $total);

            $cues[] = array('start' => $cursor, 'end' => $cursor + $share, 'text' => $sentence);

            $cursor += $share;
        }

        return $cues;
    }

    /**
     * The cues the formatter will actually write.
     *
     * Counted through the same rules the formatter applies so the reported
     * `cue_count` matches the file rather than the provider's segment list.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cues(array $cues): array
    {
        return \array_values(\array_filter(
            $cues,
            static fn (array $cue): bool => '' !== \trim((string) ($cue['text'] ?? '')),
        ));
    }
}
