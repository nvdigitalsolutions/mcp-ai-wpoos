<?php
/**
 * Abstract tool base class — the canonical foundation for all AI tools.
 *
 * Provides the success/failure envelope pattern that every tool must
 * follow, plus helpers for composing responses with consistent shapes.
 *
 * Ported from the existing WP_MCP_AI_Tool_Envelope and
 * WP_MCP_AI_Tool_Chat_Response traits, adapted for framework agnosticism
 * via constructor-injected ErrorFactoryInterface.
 *
 * Every tool in the oOS core extends this class.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\DomainError;
use Nvoos\Core\Domain\ValueObject\Option;
use Nvoos\Core\Domain\ValueObject\ToolResult;
use Nvoos\Core\Support\Json;

abstract class AbstractTool implements ToolInterface {

	public function __construct(
		protected readonly ErrorFactoryInterface $errors,
	) {}

	// ─── ToolInterface (abstract — each tool implements) ─────────────

	abstract public function getSlug(): string;

	abstract public function getName(): string;

	abstract public function getDescription(): string;

	abstract public function getParametersSchema(): array;

	/**
	 * The capability required to execute this tool.
	 *
	 * Override in concrete tools. Return empty string for public tools.
	 */
	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	abstract public function execute( array $arguments = array(), array $context = array() ): mixed;

	// ─── Canonical envelope helpers ───────────────────────────────────

	/**
	 * Format a successful tool response.
	 *
	 * This is THE canonical success shape for all tools. Use it instead
	 * of returning raw arrays.
	 *
	 * @return SuccessEnvelope
	 */
	protected function success( string $message, mixed $data = null ): array {
		$response = array(
			'success' => true,
			'message' => $message,
		);

		if ( null !== $data ) {
			$response['data'] = $data;
		}

		return $response;
	}

	/**
	 * Format an empty result (not found / no data).
	 *
	 * @return SuccessEnvelope
	 */
	protected function emptyResult( string $message = 'No results found.' ): array {
		return $this->success( $message, array() );
	}

	/**
	 * Format a collection response with items and total count.
	 *
	 * @return SuccessEnvelope
	 * @param array<mixed, mixed> $items Items to return in the collection.
	 */
	protected function collection( string $message, array $items, int $total ): array {
		return $this->success(
			$message,
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	// ─── Error helpers ────────────────────────────────────────────────

	/**
	 * Build a failed result that mirrors ErrorFactoryInterface::validationFailed().
	 *
	 * The data payload is kept identical (including the 422 status) so the
	 * typed and legacy paths are interchangeable.
	 *
	 * @param array<string, string[]> $fieldErrors Field name → error messages.
	 * @return ToolResult<never>
	 */
	protected function validationFailure( string $message, array $fieldErrors = array() ): ToolResult {
		return ToolResult::failure(
			new DomainError(
				'validation_failed',
				$message,
				array(
					'errors' => $fieldErrors,
					'status' => 422,
				),
			)
		);
	}

	/**
	 * Convert a typed result to the legacy tool surface.
	 *
	 * Success becomes the canonical envelope array; failure becomes the
	 * platform error object (WP_Error, exception payload, DomainError). This
	 * keeps `execute()` byte-identical for callers that still expect the
	 * untyped contract, while the typed path stays pure.
	 *
	 * @param ToolResult<mixed> $result
	 * @return mixed
	 */
	protected function unwrapResult( ToolResult $result ): mixed {
		if ( $result->isOk() ) {
			return $result->toEnvelope();
		}

		$error = $result->error;

		return $this->errors->create(
			$error?->code ?? 'tool_failed',
			$error?->message ?? 'Tool execution failed.',
			$error?->data ?? array(),
		);
	}

	/**
	 * Read an optional parameter, distinguishing absent from present.
	 *
	 * Returns `none()` for a missing key, an explicit null, or an empty
	 * string — the same three cases `requireParam()` used to conflate with
	 * its error return.
	 *
	 * @param ToolArgs $arguments
	 * @return Option<mixed>
	 */
	protected function optionParam( array $arguments, string $key ): Option {
		if ( ! array_key_exists( $key, $arguments ) ) {
			return Option::none();
		}

		$value = $arguments[ $key ];

		if ( null === $value || '' === $value ) {
			return Option::none();
		}

		return Option::some( $value );
	}

	/**
	 * Require an authenticated user.
	 *
	 * Returns normally when authorized and throws otherwise — there is no
	 * "null means success" state to forget to check. The registry converts
	 * the exception into the platform's error type at the execution edge.
	 *
	 * @param ToolContext $context
	 * @throws AccessDeniedException When no user is present in the context.
	 */
	protected function authorize( array $context, string $capability ): void {
		$userId = (int) ( $context['user_id'] ?? 0 );

		if ( $userId <= 0 ) {
			throw new AccessDeniedException(
				'You must be logged in to execute this tool.',
				$userId,
				$capability,
			);
		}
	}

	/**
	 * Require a parameter and return an error if it's missing.
	 *
	 * @deprecated 1.4.0 Use optionParam() instead; this returns a value OR an
	 *             error in the same `mixed`, which callers can silently misuse.
	 *
	 * @return mixed  The parameter value, or an error.
	 * @param ToolArgs $arguments Raw tool arguments.
	 */
	protected function requireParam( array $arguments, string $key ): mixed {
		if ( empty( $arguments[ $key ] ) && ! isset( $arguments[ $key ] ) ) {
			return $this->errors->validationFailed(
				"The '{$key}' parameter is required.",
				array( $key => array( 'This field is required.' ) ),
			);
		}

		return $arguments[ $key ];
	}

	/**
	 * Require the user to have a specific capability.
	 *
	 * @deprecated 1.4.0 Use authorize() instead; `null` meaning "authorized"
	 *             is exactly the illegal state the typed helpers remove.
	 *
	 * @return mixed  null if authorized, error if not.
	 * @param ToolContext $context Execution context.
	 */
	protected function requireCapability( array $context, string $capability ): mixed {
		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to execute this tool.' );
		}

		// The auth provider should be injected if needed; for now,
		// capability is checked by the ToolRegistry before execute().
		return null;
	}

	/**
	 * Sanitize an integer parameter from arguments.
	 * @param ToolArgs $arguments Raw tool arguments.
	 */
	protected function intParam( array $arguments, string $key, int $default = 0 ): int {
		return isset( $arguments[ $key ] ) ? (int) $arguments[ $key ] : $default;
	}

	/**
	 * Sanitize a float parameter from arguments.
	 *
	 * Non-numeric input falls back to the default rather than casting to 0 — a
	 * caller that passed "high" for an hour estimate should not silently write
	 * zero effort.
	 *
	 * @param ToolArgs $arguments Raw tool arguments.
	 */
	protected function floatParam( array $arguments, string $key, float $default = 0.0 ): float {
		if ( ! isset( $arguments[ $key ] ) || ! is_numeric( $arguments[ $key ] ) ) {
			return $default;
		}

		return (float) $arguments[ $key ];
	}

	/**
	 * Sanitize a list-of-integers parameter from arguments.
	 *
	 * Non-numeric entries are dropped and duplicates collapse, so a caller that
	 * sends `[1, "1", "x"]` stores two elements rather than something a JSON
	 * filter will never match again.
	 *
	 * @param ToolArgs $arguments Raw tool arguments.
	 *
	 * @return list<int>
	 */
	protected function intListParam( array $arguments, string $key ): array {
		if ( ! isset( $arguments[ $key ] ) || ! is_array( $arguments[ $key ] ) ) {
			return array();
		}

		$values = array();

		foreach ( $arguments[ $key ] as $value ) {
			if ( is_numeric( $value ) ) {
				$values[] = (int) $value;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Sanitize a string parameter from arguments.
	 * @param ToolArgs $arguments Raw tool arguments.
	 */
	protected function stringParam( array $arguments, string $key, string $default = '' ): string {
		$value = $arguments[ $key ] ?? $default;

		return is_string( $value ) ? \trim( \strip_tags( $value ) ) : $default;
	}

	/**
	 * Sanitize a boolean parameter from arguments.
	 * @param ToolArgs $arguments Raw tool arguments.
	 */
	protected function boolParam( array $arguments, string $key, bool $default = false ): bool {
		$value = $arguments[ $key ] ?? $default;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return \filter_var( $value, \FILTER_VALIDATE_BOOLEAN );
		}

		return (bool) $value;
	}

	/**
	 * Sanitize an array parameter from arguments.
	 * @param ToolArgs $arguments Raw tool arguments.
	 * @param array<mixed, mixed> $default Default used when the key is absent or not an array.
	 * @return array<mixed, mixed>
	 */
	protected function arrayParam( array $arguments, string $key, array $default = array() ): array {
		$value = $arguments[ $key ] ?? $default;

		return is_array( $value ) ? $value : $default;
	}

	/**
	 * Read an object-shaped (string-keyed) array parameter.
	 *
	 * `arrayParam()` accepts any array, but many arguments are documented as
	 * JSON objects. Returning the default when the value carries integer keys
	 * keeps the declared `array<string, mixed>` honest instead of silently
	 * forwarding a list into a map-typed consumer.
	 *
	 * @param ToolArgs             $arguments Raw tool arguments.
	 * @param string               $key       Argument name.
	 * @param array<string, mixed> $default   Default when absent or not a map.
	 * @return array<string, mixed>
	 */
	protected function objectParam( array $arguments, string $key, array $default = array() ): array {
		$value = $this->arrayParam( $arguments, $key, $default );

		$map = array();
		foreach ( $value as $name => $item ) {
			if ( ! \is_string( $name ) ) {
				return $default;
			}

			$map[ $name ] = $item;
		}

		return $map;
	}

	/**
	 * Read a list-of-strings parameter.
	 *
	 * Scalar entries are cast to string and non-scalar entries are dropped, so
	 * the result always satisfies the `array<int, string>` contract that
	 * tag-like consumers declare.
	 *
	 * @param ToolArgs $arguments Raw tool arguments.
	 * @param string   $key       Argument name.
	 * @return array<int, string>
	 */
	protected function stringListParam( array $arguments, string $key ): array {
		$value = $this->arrayParam( $arguments, $key );

		$list = array();
		foreach ( $value as $item ) {
			if ( \is_scalar( $item ) ) {
				$list[] = (string) $item;
			}
		}

		return $list;
	}

	/**
	 * Encode a payload for an HTTP request body.
	 *
	 * `json_encode()` returns `string|false`. Under `declare(strict_types=1)`
	 * passing that `false` into a `?string $body` parameter is a TypeError, so
	 * the failure is surfaced here as a RuntimeException with the encoder's
	 * reason instead of escaping as an opaque type error.
	 *
	 * @param mixed $payload Payload to encode.
	 * @return string JSON-encoded body.
	 *
	 * @throws \RuntimeException When the payload cannot be encoded.
	 */
	protected function jsonBody( mixed $payload ): string {
		return Json::encode( $payload );
	}
}
