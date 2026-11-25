<?php

/**
 * Resolve the audio a transcription tool was asked about.
 *
 * `transcribe_openai_audio` has always advertised `attachment_id` and never
 * resolved it: the argument was read only to decide whether *something* had been
 * supplied, so a caller that passed an id and no URL got a request with **no
 * file part at all** and a 400 from the provider. Reading a file and sending its
 * bytes is a handful of rules — ownership, type, size, read — and a tool that
 * gets one of them wrong leaks another tenant's audio or waits out an upload
 * that was always going to be refused. They live here.
 *
 * ## The two facts shared with the adapter
 *
 * `OpenAiTranscription` needs the same ceiling and the same extension → type
 * mapping when it sends a path the video pipeline produced. Two copies of "25 MB"
 * would eventually disagree, so both live here.
 *
 * ## What the mapping is, and what it is not
 *
 * The transcription endpoint accepts **flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav
 * and webm**. The map below is deliberately wider than that: it answers "what
 * type are these bytes" — the question the multipart part has to answer
 * honestly — not "will the provider take them". An extension the map does not
 * know falls back to `audio/mpeg`, which is what the tool has always declared
 * for an opaque URL.
 *
 * The extensions are the ones this runtime can actually meet: `extract_audio`
 * writes mp3, m4a and aac, speech generation writes mp3, and the file registry
 * accepts what libmagic reports — which was measured, not assumed:
 *
 * | file | libmagic |
 * |---|---|
 * | `.m4a` | `audio/x-m4a` |
 * | `.aac` | `audio/x-hx-aac-adts` |
 * | `.flac` | `audio/flac` |
 * | `.webm` | `video/webm` (even when it is audio only) |
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

final class AudioInput
{
    /**
     * The transcription endpoint's per-request ceiling, in bytes (25 MB).
     */
    public const MAX_BYTES = 26214400;

    /**
     * Extension → MIME type for the audio this runtime produces and stores.
     *
     * @var array<string, string>
     */
    private const TYPES = array(
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        'm4a'  => 'audio/mp4',
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'audio/mp4',
        'mpeg' => 'audio/mpeg',
        'mpga' => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav'  => 'audio/wav',
        'webm' => 'audio/webm',
    );

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly ?FileStoreInterface $files = null,
    ) {
    }

    /**
     * The declared type for a filename, or the endpoint's safe default.
     */
    public static function mimeFor(string $filename): string
    {
        $extension = \strtolower((string) \pathinfo($filename, PATHINFO_EXTENSION));

        return self::TYPES[ $extension ] ?? 'audio/mpeg';
    }

    /**
     * Read an audio file the registry already owns.
     *
     * @param array<string, mixed> $context Execution context (`user_id`, `owner_id`).
     *
     * @return array{bytes: string, mime: string, filename: string}|mixed
     *                                                                 The audio, or the error value to return unchanged.
     */
    public function fromRegistry(int $fileId, array $context = array()): mixed
    {
        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'This host has no file registry, so a file id cannot be read. Send the audio as audio_url instead.',
            );
        }

        $stored = $this->files->getMetadata($fileId);

        if (null === $stored) {
            return $this->errors->notFound('No file with that id exists.');
        }

        $userId = (int) ($context['user_id'] ?? $context['owner_id'] ?? 0);

        if ($userId > 0 && ! $this->files->userCanAccess($fileId, $userId)) {
            // The same answer as a missing file: whether the row exists is not
            // something a caller should be able to learn from the response.
            return $this->errors->notFound('No file with that id exists.');
        }

        if (! $stored->isAudio()) {
            return $this->errors->validationFailed(
                \str_starts_with($stored->mimeType, 'video/')
                    ? 'That file is a video. Use transcribe_video, which extracts the audio track first.'
                    : 'That file is not audio.',
                array( 'file_id' => array( 'Not an audio file.' ) ),
            );
        }

        if ($stored->sizeBytes > self::MAX_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'That audio is %d bytes; the transcription endpoint accepts at most %d. Transcribe a shorter clip.',
                    $stored->sizeBytes,
                    self::MAX_BYTES,
                ),
                array( 'file_id' => array( 'Audio too large.' ) ),
            );
        }

        $bytes = @\file_get_contents($stored->localPath);

        if (! \is_string($bytes) || '' === $bytes) {
            return $this->errors->create(
                'file_unreadable',
                'That file could not be read from storage.',
            );
        }

        return array(
            'bytes'    => $bytes,
            'mime'     => $stored->mimeType,
            'filename' => $stored->filename,
        );
    }
}
