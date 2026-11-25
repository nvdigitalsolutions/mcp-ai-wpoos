<?php

/**
 * Transcribe OpenAI Audio — speech-to-text via OpenAI Whisper API.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Transcribe_OpenAI_Audio.
 *
 * ## Two sources, and the one that was broken
 *
 * `audio_url` has always worked: it is fetched and posted as the file part.
 * `attachment_id` did not — the argument was read only to decide whether
 * *something* had been supplied, so a caller that passed an id and no URL sent a
 * transcription request with **no file** and got a 400 back. It now resolves
 * through {@see \Nvoos\Core\Application\Media\AudioInput}, which is the same
 * registry contract the vision tools read, including its ownership rule: a
 * caller who cannot download a file must not be able to send it to a provider.
 *
 * `file_id` is accepted as the name every other media tool in this runtime uses;
 * `attachment_id` is kept because it is the published WordPress name. They are
 * the same id, so supplying two different ones is refused rather than resolved
 * by a hidden precedence.
 *
 * ## The part has to describe the bytes
 *
 * The filename and the declared type used to be the literal `audio.mp3` and
 * `audio/mpeg`, whatever the source was. A `.wav` URL was therefore posted as an
 * mp3, and a stored `.m4a` had no correct answer available at all. Both now come
 * from the source: the registry's sniffed type for a stored file, the URL's own
 * extension for a fetched one.
 *
 * `audio_url` is fetched rather than passed through, which is a pre-existing
 * SSRF surface (`HttpClientInterface` exposes no redirect or size controls); it
 * is out of scope here and recorded as such in Proposal 164 §164.28. What this
 * change does add is a ceiling on both paths, so an oversized body is refused
 * before it is uploaded to a provider that was always going to reject it.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\AudioInput;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Http\MultipartBody;

class TranscribeOpenAIAudioTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'transcribe_openai_audio';
    }
    public function getName(): string
    {
        return 'Transcribe OpenAI Audio';
    }
    public function getDescription(): string
    {
        return 'Converts an audio file into text using OpenAI Whisper transcription. Accepts a stored file id or a URL to fetch.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'audio_url'       => array( 'type' => 'string', 'description' => 'URL of the audio file to fetch and transcribe.' ),
                'file_id'         => array( 'type' => 'integer', 'description' => 'Id of an audio file in this runtime\'s file registry.', 'minimum' => 1 ),
                'attachment_id'   => array( 'type' => 'integer', 'description' => 'The WordPress name for the same id. Either name works; supplying both with different values is refused.', 'minimum' => 1 ),
                'translate'       => array( 'type' => 'boolean', 'description' => 'Translate to English instead of transcribing.', 'default' => false ),
                'model'           => array( 'type' => 'string', 'description' => 'Whisper model. Default: whisper-1.', 'default' => 'whisper-1' ),
                'response_format' => array( 'type' => 'string', 'description' => 'Response format.', 'enum' => array( 'json', 'text', 'srt', 'verbose_json', 'vtt' ), 'default' => 'json' ),
                'language'        => array( 'type' => 'string', 'description' => 'Optional: ISO 639-1 language code.' ),
                'prompt'          => array( 'type' => 'string', 'description' => 'Optional context prompt to guide transcription.' ),
            ),
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $apiKey = $this->settings->getApiKey('openai');
        if (null === $apiKey || '' === $apiKey) {
            return $this->errors->create('missing_key', 'No OpenAI API key configured.');
        }

        $audioUrl     = $this->stringParam($arguments, 'audio_url');
        $attachmentId = $this->intParam($arguments, 'attachment_id');
        $fileId       = $this->intParam($arguments, 'file_id');

        if ($fileId > 0 && $attachmentId > 0 && $fileId !== $attachmentId) {
            return $this->errors->validationFailed(
                'file_id and attachment_id are the same id in this runtime; supply one.',
                array( 'file_id' => array( 'Conflicts with attachment_id.' ) ),
            );
        }

        $fileId = $fileId > 0 ? $fileId : $attachmentId;

        if ('' !== $audioUrl && $fileId > 0) {
            return $this->errors->validationFailed(
                'Supply exactly one audio source: audio_url or a file id, not both.',
                array( 'audio_url' => array( 'Conflicts with file_id.' ) ),
            );
        }

        if ('' === $audioUrl && $fileId <= 0) {
            return $this->errors->validationFailed(
                'An audio source is required: audio_url, file_id or attachment_id.',
                array( 'audio_url' => array( 'Audio source is required.' ) ),
            );
        }

        $translate = $this->boolParam($arguments, 'translate');
        $model     = $this->stringParam($arguments, 'model', 'whisper-1');
        $format    = $this->stringParam($arguments, 'response_format', 'json');
        $language  = $this->stringParam($arguments, 'language');
        $prompt    = $this->stringParam($arguments, 'prompt');

        $file = $fileId > 0
            ? $this->fromRegistry($fileId, $context)
            : $this->fromUrl($audioUrl);

        if ($this->errors->isError($file)) {
            return $file;
        }

        $endpoint = $translate
            ? 'https://api.openai.com/v1/audio/translations'
            : 'https://api.openai.com/v1/audio/transcriptions';

        $multipart = MultipartBody::build(
            \array_filter(
                array(
                    'model'           => $model,
                    'response_format' => $format,
                    'language'        => $language,
                    'prompt'          => $prompt,
                ),
                static fn (string $value): bool => '' !== $value,
            ),
            array(
                'name'     => 'file',
                'filename' => $file['filename'],
                'mime'     => $file['mime'],
                'bytes'    => $file['bytes'],
            ),
        );

        try {
            $response = $this->http->send(
                'POST',
                $endpoint,
                array(
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => $multipart['contentType'],
                ),
                $multipart['body'],
            );

            if ($response->statusCode >= 400) {
                $data   = \json_decode($response->body, true);
                $errMsg = $data['error']['message'] ?? "Whisper API error (HTTP {$response->statusCode}).";
                return $this->errors->create('transcription_error', $errMsg);
            }

            $text = 'json' === $format ? ( \json_decode($response->body, true)['text'] ?? $response->body ) : $response->body;

            return $this->success(
                'Audio transcribed successfully.',
                array(
                    'text'     => \is_string($text) ? $text : \json_encode($text),
                    'model'    => $model,
                    'format'   => $format,
                    'filename' => $file['filename'],
                ),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('request_failed', "Transcription request failed: {$e->getMessage()}");
        }
    }

    /**
     * The file part for a stored audio file.
     *
     * @param array<string, mixed> $context
     *
     * @return array{bytes: string, mime: string, filename: string}|mixed
     */
    private function fromRegistry(int $fileId, array $context): mixed
    {
        return (new AudioInput($this->errors, $this->files))->fromRegistry($fileId, $context);
    }

    /**
     * The file part for a URL, fetched here because the provider cannot.
     *
     * @return array{bytes: string, mime: string, filename: string}|mixed
     */
    private function fromUrl(string $audioUrl): mixed
    {
        try {
            $audioResp = $this->http->send('GET', $audioUrl);
        } catch (\Throwable $e) {
            return $this->errors->create('fetch_failed', "Failed to fetch audio: {$e->getMessage()}");
        }

        if ($audioResp->statusCode >= 400) {
            return $this->errors->create('fetch_failed', "Failed to fetch audio from URL (HTTP {$audioResp->statusCode}).");
        }

        $bytes = (string) $audioResp->body;

        if (\strlen($bytes) > AudioInput::MAX_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'The audio at that URL is %d bytes; the transcription endpoint accepts at most %d. Transcribe a shorter clip.',
                    \strlen($bytes),
                    AudioInput::MAX_BYTES,
                ),
                array( 'audio_url' => array( 'Audio too large.' ) ),
            );
        }

        $filename = \basename((string) \parse_url($audioUrl, PHP_URL_PATH));

        // A signed CDN link often carries no extension at all. The endpoint is
        // told the safe default rather than being handed a bare token as a name.
        if ('' === $filename || '' === \pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename = 'audio.mp3';
        }

        return array(
            'bytes'    => $bytes,
            'mime'     => AudioInput::mimeFor($filename),
            'filename' => $filename,
        );
    }
}
