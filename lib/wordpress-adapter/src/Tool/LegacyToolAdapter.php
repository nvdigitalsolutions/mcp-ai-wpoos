<?php
/**
 * LegacyToolAdapter — anti-corruption layer exposing WP_MCP_AI tools to the
 * framework-agnostic OOS engine (Proposal 029, Phase 1).
 *
 * Wraps any object implementing the WP_MCP_AI_Tool_Interface surface
 * (get_slug / get_name / get_description / get_parameters_schema /
 * get_required_capability / execute) behind Nvoos\Core ToolInterface.
 *
 * Duck-typed on purpose: the legacy interface is ABSPATH-guarded, so the
 * adapter cannot reference it. Any object with the same method surface
 * works — including fakes in the core unit-test suite.
 *
 * Normalization rules:
 *  - WP_Error results become framework errors via ErrorFactoryInterface
 *    (the WP adapter converts them back to WP_Error at the boundary).
 *  - Empty parameter schemas get a default open-object schema so provider
 *    payloads always contain valid JSON Schema.
 *  - The declared capability is surfaced for registry enforcement; the
 *    legacy tool still performs its own internal capability checks.
 *
 * @package Nvoos\WordPress
 * @since   1.3.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolWriteClassInterface;

final class LegacyToolAdapter implements ToolInterface, ToolWriteClassInterface {

	/**
	 * @var object  Duck-typed WP_MCP_AI_Tool_Interface implementation.
	 */
	private object $legacy;

	public function __construct(
		object $legacyTool,
		private readonly ErrorFactoryInterface $errors,
	) {
		$this->legacy = $legacyTool;
	}

	public function getSlug(): string {
		return (string) $this->legacy->get_slug();
	}

	public function getName(): string {
		if ( \method_exists( $this->legacy, 'get_name' ) ) {
			$name = $this->legacy->get_name();

			if ( \is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}

		return $this->getSlug();
	}

	public function getDescription(): string {
		if ( ! \method_exists( $this->legacy, 'get_description' ) ) {
			return '';
		}

		return (string) $this->legacy->get_description();
	}

	public function getParametersSchema(): array {
		$schema = array();

		if ( \method_exists( $this->legacy, 'get_parameters_schema' ) ) {
			$candidate = $this->legacy->get_parameters_schema();

			if ( \is_array( $candidate ) ) {
				$schema = $candidate;
			}
		}

		// Ensure a valid JSON Schema root: default to an open object.
		if ( ! isset( $schema['type'] ) ) {
			$schema = array(
				'type'       => 'object',
				'properties' => $schema,
			);
		}

		// `properties` must encode as a JSON object (`{}`), never as an
		// empty JSON array (`[]`) — strict providers (DeepSeek) reject
		// "[] is not of type 'object'". Preserve object-valued property
		// maps (stdClass) and upgrade empty arrays to an empty stdClass.
		if ( ! isset( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		} elseif ( ! \is_array( $schema['properties'] ) && ! \is_object( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		} elseif ( \is_array( $schema['properties'] ) && array() === $schema['properties'] ) {
			$schema['properties'] = new \stdClass();
		}

		return $schema;
	}

	/**
	 * Write-class classification for shadow-mode suppression.
	 *
	 * Capability flags win when present: any write-class flag makes the
	 * tool write-class; tools that declare only read-type flags are read.
	 * Without flags, the required capability decides (empty/read/public
	 * → read; anything else → write, failing safe).
	 */
	public function isWriteClass(): bool {
		if ( \method_exists( $this->legacy, 'get_capability_flags' ) ) {
			$flags = (array) $this->legacy->get_capability_flags();

			if ( array() !== $flags ) {
				return array() !== \array_intersect(
					$flags,
					array(
						'write',
						'state-changing',
						'irreversible',
						'data-destruction',
						'financial-impact',
						'external-communication',
						'access-control-change',
					),
				);
			}
		}

		$capability = $this->getRequiredCapability();

		return '' !== $capability && 'read' !== $capability && 'public' !== $capability;
	}

	public function getRequiredCapability(): string {
		if ( ! \method_exists( $this->legacy, 'get_required_capability' ) ) {
			return '';
		}

		return (string) $this->legacy->get_required_capability();
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Context-restricted legacy tools branch on the endpoint/source keys;
		// default them to the MCP chat surface so OOS runs behave like the
		// controlled chat endpoint rather than an unknown surface.
		$context += array(
			'endpoint' => 'chat',
			'source'   => 'oos_engine',
		);

		$result = $this->legacy->execute( $arguments, $context );

		return $this->normalizeResult( $result );
	}

	/**
	 * Normalize a legacy tool result into the OOS error/result convention.
	 *
	 * WP_Error becomes a framework error; everything else passes through
	 * unchanged (the canonical success envelope is identical on both sides).
	 */
	private function normalizeResult( mixed $result ): mixed {
		// instanceof against an undefined class is always false without
		// autoloading — safe in standalone (non-WordPress) test runs.
		if ( $result instanceof \WP_Error ) {
			return $this->errors->create(
				(string) $result->get_error_code(),
				(string) $result->get_error_message(),
				array( 'wp_error_data' => $result->get_error_data() ),
			);
		}

		return $result;
	}
}
