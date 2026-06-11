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
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;

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
	 * @return array{success: true, message: string, data: mixed}
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
	 * @return array{success: true, message: string, data: array}
	 */
	protected function emptyResult( string $message = 'No results found.' ): array {
		return $this->success( $message, array() );
	}

	/**
	 * Format a collection response with items and total count.
	 *
	 * @return array{success: true, message: string, data: array{items: array, total: int}}
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
	 * Require a parameter and return an error if it's missing.
	 *
	 * @return mixed  The parameter value, or an error.
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
	 * @return mixed  null if authorized, error if not.
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
	 */
	protected function intParam( array $arguments, string $key, int $default = 0 ): int {
		return isset( $arguments[ $key ] ) ? (int) $arguments[ $key ] : $default;
	}

	/**
	 * Sanitize a string parameter from arguments.
	 */
	protected function stringParam( array $arguments, string $key, string $default = '' ): string {
		$value = $arguments[ $key ] ?? $default;

		return is_string( $value ) ? \trim( \strip_tags( $value ) ) : $default;
	}

	/**
	 * Sanitize a boolean parameter from arguments.
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
	 */
	protected function arrayParam( array $arguments, string $key, array $default = array() ): array {
		$value = $arguments[ $key ] ?? $default;

		return is_array( $value ) ? $value : $default;
	}
}
