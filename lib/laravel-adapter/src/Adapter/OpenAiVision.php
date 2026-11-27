<?php
/**
 * Laravel adapter: image understanding over an OpenAI-compatible chat route.
 *
 * Proposal 164 §164.122. The core contract is `VisionInferenceInterface`; this is
 * the binding that `lib/laravel-adapter` was missing, and its absence is why
 * §164.120 deferred three ecommerce tools rather than porting them onto a seam
 * that could only refuse.
 *
 * ## The contract had no docblock, so this records the shape it implements
 *
 * `VisionInferenceInterface::infer()` returns an array and declares no exception.
 * The only prior implementation — `Nvoos\WordPress\Adapter\VisionInference` —
 * answers `['success' => true, ...]` and, on failure, `['success' => false,
 * 'error' => string]` rather than throwing. A second implementation that threw
 * where the first returned would make the interface useless to a caller that
 * checks `success`, so this follows the same shape:
 *
 *     ['success' => true,  'text' => string, 'model' => string, 'usage' => array|null]
 *     ['success' => false, 'error' => string]
 *
 * `text` is the assistant message content, flattened when a provider answers it
 * as a list of parts. A caller that asked for `response_format` receives JSON in
 * `text` and decodes it, which is exactly what the two validators do.
 *
 * ## Input errors throw; provider errors do not
 *
 * A missing path, an oversized image or an unknown extension is a bug in the
 * caller and throws `InvalidArgumentException`, matching `OpenAiTranscription`.
 * A missing key, a transport failure or a refused request is a runtime
 * condition the caller may want to fall back from, so it returns
 * `success => false` and the reason.
 *
 * ## Nothing about the provider is hardcoded
 *
 * The provider, model, key, base URL and request timeout all come from
 * `SettingsStoreInterface`. That is what lets one adapter serve OpenAI and any
 * OpenAI-compatible endpoint the host has configured, and it is why no model
 * name is invented here: the WordPress validators pin `gpt-6.1-sol`, but a model
 * is a host decision, not a port's.
 *
 * ## The image is inlined rather than linked
 *
 * The WordPress tool sends the provider a public image **URL** and performs its
 * own `wp_remote_head` on it first. This adapter base64-inlines the bytes, like
 * `OpenAiTranscription` does with audio: it removes the requirement that the
 * provider can reach the caller's URL, and it removes the caller-supplied fetch
 * from the request path entirely.
 *
 * MIME is derived from the extension rather than `finfo`, because `finfo` is not
 * guaranteed to be enabled on every host this package ships to.
 *
 * @package Nvoos\Laravel
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\VisionInferenceInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;

final class OpenAiVision implements VisionInferenceInterface {

	private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

	/**
	 * The provider's per-image ceiling, checked before the upload.
	 */
	private const MAX_BYTES = 20971520;

	private const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * The image types the chat route accepts.
	 */
	private const SUPPORTED_MIME = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	private const EXTENSION_MIME = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
	);

	public function __construct(
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
	}

	public function isAvailable(): bool {
		$key = $this->settings->getApiKey( $this->provider( array() ) );

		return \is_string( $key ) && '' !== \trim( $key );
	}

	/**
	 * The models this adapter can serve.
	 *
	 * A single entry, and deliberately: the adapter uses the host's configured
	 * default model, so advertising a catalogue it cannot verify would be a
	 * guess dressed as a capability.
	 *
	 * @return array<int, string>
	 */
	public function getAvailableModels(): array {
		$model = $this->settings->getDefaultModel();

		return '' === $model ? array() : array( $model );
	}

	/**
	 * @param array<string, mixed> $options See the class docblock; `model`,
	 *                                      `provider`, `detail`, `max_tokens`,
	 *                                      `system` and `response_format` are read.
	 *
	 * @return array{success: true, text: string, model: string, usage: array<string, mixed>|null}|array{success: false, error: string}
	 *
	 * @throws \InvalidArgumentException When the image cannot be read or is not one this adapter sends.
	 */
	public function infer( string $imagePath, string $prompt = '', array $options = array() ): array {
		if ( '' === \trim( $imagePath ) || ! \is_file( $imagePath ) || ! \is_readable( $imagePath ) ) {
			throw new \InvalidArgumentException( 'The image does not exist or is not readable: ' . $imagePath );
		}

		$mime = self::mimeFor( $imagePath );

		if ( ! \in_array( $mime, self::SUPPORTED_MIME, true ) ) {
			throw new \InvalidArgumentException(
				'The image type is not supported; send a JPEG, PNG, WebP or GIF. Received: ' . $imagePath,
			);
		}

		$bytes = \filesize( $imagePath );

		if ( false !== $bytes && $bytes > self::MAX_BYTES ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The image is %s; the provider accepts at most %s per image.',
					self::human( $bytes ),
					self::human( self::MAX_BYTES ),
				),
			);
		}

		$payload = \file_get_contents( $imagePath );

		if ( false === $payload || '' === $payload ) {
			throw new \InvalidArgumentException( 'The image file is empty: ' . $imagePath );
		}

		$provider = $this->provider( $options );
		$key      = $this->settings->getApiKey( $provider );

		if ( ! \is_string( $key ) || '' === \trim( $key ) ) {
			return array(
				'success' => false,
				'error'   => 'No API key is configured for the ' . $provider . ' provider.',
			);
		}

		$model = \trim( (string) ( $options['model'] ?? '' ) );

		if ( '' === $model ) {
			$model = $this->settings->getDefaultModel();
		}

		$body = array(
			'model'                 => $model,
			            'messages'              => $this->messages( $mime, $payload, $prompt, $options ),
			'max_completion_tokens' => $this->maxTokens( $options ),
		);

		$format = $options['response_format'] ?? null;

		if ( \is_array( $format ) && array() !== $format ) {
			$body['response_format'] = $format;
		}

		$json = \json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return array( 'success' => false, 'error' => 'The vision request could not be encoded.' );
		}

		try {
			$response = $this->http->send(
				'POST',
				$this->endpoint( $provider ),
				array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				$json,
			);
		} catch ( \RuntimeException $error ) {
			return array(
				'success' => false,
				'error'   => 'The vision request failed to send: ' . $error->getMessage(),
			);
		}

		if ( $response->statusCode < 200 || $response->statusCode >= 300 ) {
			return array( 'success' => false, 'error' => self::refusal( $response ) );
		}

		$decoded = \json_decode( $response->body, true );

		if ( ! \is_array( $decoded ) ) {
			return array( 'success' => false, 'error' => 'The provider answered a response that is not JSON.' );
		}

		$text = self::text( $decoded['choices'][0]['message']['content'] ?? null );

		if ( '' === \trim( $text ) ) {
			return array( 'success' => false, 'error' => 'The provider returned an empty analysis.' );
		}

		return array(
			'success' => true,
			'text'    => $text,
			'model'   => \is_string( $decoded['model'] ?? null ) && '' !== $decoded['model'] ? $decoded['model'] : $model,
			'usage'   => \is_array( $decoded['usage'] ?? null ) ? $decoded['usage'] : null,
		);
	}

	/**
	 * @param array<string, mixed> $options Tool options.
	 */
	private function provider( array $options ): string {
		$provider = \trim( (string) ( $options['provider'] ?? '' ) );

		return '' !== $provider ? $provider : $this->settings->getDefaultProvider();
	}

	private function endpoint( string $provider ): string {
		$base = $this->settings->getApiBaseUrl( $provider );

		if ( ! \is_string( $base ) || '' === \trim( $base ) ) {
			$base = self::DEFAULT_BASE_URL;
		}

		return \rtrim( \trim( $base ), '/' ) . '/chat/completions';
	}

	/**
	 * @param array<string, mixed> $options Tool options.
	 */
	private function maxTokens( array $options ): int {
		$max = (int) ( $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS );

		return $max > 0 ? $max : self::DEFAULT_MAX_TOKENS;
	}

	/**
	 * The chat messages: an optional system turn, then the text and the image.
	 *
	 * @param array<string, mixed> $options Tool options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	    private function messages( string $mime, string $payload, string $prompt, array $options ): array {
		$detail = \trim( (string) ( $options['detail'] ?? 'high' ) );

		if ( ! \in_array( $detail, array( 'low', 'high', 'auto' ), true ) ) {
			$detail = 'high';
		}

		$content = array();

		if ( '' !== \trim( $prompt ) ) {
			$content[] = array(
				'type' => 'text',
				'text' => $prompt,
			);
		}

		$content[] = array(
			'type'      => 'image_url',
			'image_url' => array(
				'url'    => 'data:' . $mime . ';base64,' . \base64_encode( $payload ),
				'detail' => $detail,
			),
		);

		$messages = array();
		$system   = \trim( (string) ( $options['system'] ?? '' ) );

		if ( '' !== $system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $content,
		);

		return $messages;
	}

	/**
	 * The provider's own refusal message, without the request headers.
	 */
	private static function refusal( HttpResponse $response ): string {
		$decoded = \json_decode( $response->body, true );
		$message = \is_array( $decoded ) && \is_string( $decoded['error']['message'] ?? null )
			? \trim( $decoded['error']['message'] )
			: '';

		if ( '' !== $message ) {
			return 'The provider refused the vision request: ' . $message;
		}

		return 'The provider refused the vision request (HTTP ' . $response->statusCode . ').';
	}

	/**
	 * Assistant content as text, whether it arrived as a string or as parts.
	 */
	private static function text( mixed $content ): string {
		if ( \is_string( $content ) ) {
			return $content;
		}

		if ( ! \is_array( $content ) ) {
			return '';
		}

		$text = '';

		foreach ( $content as $part ) {
			if ( \is_array( $part ) && \is_string( $part['text'] ?? null ) ) {
				$text .= $part['text'];
			}
		}

		return $text;
	}

	/**
	 * The MIME type for an image path, from its extension.
	 */
	private static function mimeFor( string $path ): string {
		$extension = \strtolower( (string) \pathinfo( $path, PATHINFO_EXTENSION ) );

		return self::EXTENSION_MIME[ $extension ] ?? '';
	}

	private static function human( int $bytes ): string {
		return \number_format( $bytes / 1048576, 1 ) . ' MB';
	}
}
