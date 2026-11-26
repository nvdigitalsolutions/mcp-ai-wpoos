<?php
/**
 * Laravel adapter: OpenAI speech-to-text with timings.
 *
 * Proposal 164 §164.27. The core contract is `AudioTranscriptionInterface`; this
 * is the adapter that speaks OpenAI's `/v1/audio/transcriptions` route and
 * normalises `verbose_json` into the segment list the caption formatter needs.
 *
 * ## Why `verbose_json` and not `srt`
 *
 * OpenAI can format subtitles itself — but only on `whisper-1`:
 * `gpt-4o-transcribe` and `gpt-4o-mini-transcribe` answer `json` and `text`
 * only, and no model emits ASS at all. Asking for the timed JSON and building
 * every format locally keeps the tool model-agnostic and makes the formatter a
 * pure unit with no network in its tests. The trade-off is that a model which
 * cannot answer `verbose_json` cannot serve this adapter, so it is refused
 * up-front with the reason instead of failing at the provider with a 400.
 *
 * ## Why the framework's HTTP client rather than the core contract
 *
 * `HttpClientInterface::send()` carries no per-call options, so it inherits
 * `oos.request_timeout` (60 s). A half-hour recording takes longer than that to
 * transcribe, and the fix is not a global timeout for every request the host
 * makes. `MediaWorkerClient` set the precedent: an adapter may reach for the
 * framework's client when it needs a per-call option the contract does not
 * carry. The timeout is `oos.transcription_timeout` (300 s default).
 *
 * ## The 25 MB ceiling is the provider's, not this host's
 *
 * OpenAI refuses an audio file over 25 MB. That is checked here, before the
 * upload, so a caller learns the limit rather than waiting for a transfer to
 * finish and then being told the file was too big.
 *
 * @package Nvoos\Laravel
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Media\AudioInput;
use Nvoos\Core\Domain\Contract\AudioTranscriptionInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use RuntimeException;

class OpenAiTranscription implements AudioTranscriptionInterface {

	private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

	/**
	 * The model that answers `verbose_json`.
	 *
	 * Named rather than defaulted silently: a caller who asks for another model
	 * is told why it cannot serve a timed transcript.
	 */
	private const MODEL = 'whisper-1';

	public function __construct(
		private readonly SettingsStoreInterface $settings,
	) {
	}

	public function isAvailable(): bool {
		$key = $this->settings->getApiKey( 'openai' );

		return \is_string( $key ) && '' !== \trim( $key );
	}

	public function transcribe( string $localPath, array $options = array() ): array {
		if ( '' === \trim( $localPath ) || ! \is_file( $localPath ) || ! \is_readable( $localPath ) ) {
			throw new \InvalidArgumentException( 'The audio file does not exist or is not readable: ' . $localPath );
		}

		$key = $this->settings->getApiKey( 'openai' );

		if ( ! \is_string( $key ) || '' === \trim( $key ) ) {
			throw new RuntimeException( 'No OpenAI API key configured.' );
		}

		$model = \trim( (string) ( $options['model'] ?? self::MODEL ) );

		if ( self::MODEL !== $model ) {
			throw new RuntimeException(
				'Only ' . self::MODEL . ' returns the timed segments a transcript with captions needs; '
					. $model . ' answers JSON without them. Leave `model` unset to use ' . self::MODEL . '.',
			);
		}

		$bytes = \filesize( $localPath );

		if ( false !== $bytes && $bytes > AudioInput::MAX_BYTES ) {
			throw new RuntimeException(
				\sprintf(
					'The audio is %s; OpenAI accepts at most %s per transcription request. Transcribe a shorter clip.',
					self::human( $bytes ),
					self::human( AudioInput::MAX_BYTES ),
				),
			);
		}

		$payload = \file_get_contents( $localPath );

		if ( false === $payload || '' === $payload ) {
			throw new RuntimeException( 'The audio file is empty: ' . $localPath );
		}

		$fields = \array_filter(
			array(
				'model'           => $model,
				'response_format' => 'verbose_json',
				'language'        => \trim( (string) ( $options['language'] ?? '' ) ),
				'prompt'          => \trim( (string) ( $options['prompt'] ?? '' ) ),
			),
			static fn ( string $value ): bool => '' !== $value,
		);

		try {
			$response = Http::timeout( \max( 1, (int) config( 'oos.transcription_timeout', 300 ) ) )
				->withToken( $key )
				->attach( 'file', $payload, \basename( $localPath ), array(
					'Content-Type' => AudioInput::mimeFor( \basename( $localPath ) ),
				) )
				->post( self::ENDPOINT, $fields );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'The transcription request failed: ' . $error->getMessage(), 0, $error );
		}

		if ( $response->failed() ) {
			$message = $response->json( 'error.message' );

			throw new RuntimeException(
				\is_string( $message ) && '' !== $message
					? 'The provider refused the transcription: ' . $message
					: 'The provider refused the transcription (HTTP ' . $response->status() . ').',
			);
		}

		$body = $response->json();

		if ( ! \is_array( $body ) ) {
			throw new RuntimeException( 'The provider answered something that is not a transcription object.' );
		}

		$segments = array();

		foreach ( (array) ( $body['segments'] ?? array() ) as $segment ) {
			if ( ! \is_array( $segment ) ) {
				continue;
			}

			$segments[] = array(
				'start' => (float) ( $segment['start'] ?? 0.0 ),
				'end'   => (float) ( $segment['end'] ?? 0.0 ),
				'text'  => (string) ( $segment['text'] ?? '' ),
			);
		}

		$text = \is_string( $body['text'] ?? null ) ? $body['text'] : '';

		if ( '' === \trim( $text ) && array() !== $segments ) {
			// A provider that answered segments but no roll-up text is not a
			// failure; the transcript is the segments joined, which is the same
			// thing the roll-up would have said.
			$text = \trim( \implode( ' ', \array_column( $segments, 'text' ) ) );
		}

		return array(
			'text'     => $text,
			'language' => \is_string( $body['language'] ?? null ) ? $body['language'] : null,
			'duration' => isset( $body['duration'] ) && \is_numeric( $body['duration'] ) ? (float) $body['duration'] : null,
			'model'    => $model,
			'segments' => $segments,
		);
	}

	private static function human( int $bytes ): string {
		return \number_format( $bytes / 1048576, 1 ) . ' MB';
	}
}
