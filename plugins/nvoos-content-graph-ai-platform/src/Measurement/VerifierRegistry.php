<?php
/**
 * Verifier Registry
 *
 * Tracks registered verifiers, runs independence checks (verifier's law), and
 * dispatches verification through the `wp_mcp_ai_verifier_result` action.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifier registry singleton.
 */
class VerifierRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var VerifierRegistry|null
	 */
	private static $instance = null;

	/**
	 * Registered verifiers keyed by slug.
	 *
	 * @var array<string,Verifier>
	 */
	private $verifiers = array();

	/**
	 * Whether the `wp_mcp_ai_register_verifiers` action has fired.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return VerifierRegistry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton. Intended for tests only.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Fire the verifier registration action exactly once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Fires when third parties should register verifiers.
		 *
		 * @since 1.3.0
		 *
		 * @param VerifierRegistry $registry Verifier registry.
		 */
		do_action( 'wp_mcp_ai_register_verifiers', $this );
	}

	/**
	 * Register a verifier.
	 *
	 * @param Verifier $verifier Verifier.
	 * @return bool True on success.
	 */
	public function register( Verifier $verifier ) {
		$slug = $verifier->get_slug();
		if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
			return false;
		}
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return false;
		}
		if ( isset( $this->verifiers[ $slug ] ) ) {
			return false;
		}
		$this->verifiers[ $slug ] = $verifier;
		return true;
	}

	/**
	 * Remove a verifier.
	 *
	 * @param string $slug Verifier slug.
	 * @return bool
	 */
	public function unregister( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( isset( $this->verifiers[ $slug ] ) ) {
			unset( $this->verifiers[ $slug ] );
			return true;
		}
		return false;
	}

	/**
	 * Get a verifier by slug.
	 *
	 * @param string $slug Slug.
	 * @return Verifier|null
	 */
	public function get( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( $this->verifiers[ $slug ] ) ? $this->verifiers[ $slug ] : null;
	}

	/**
	 * All registered verifiers.
	 *
	 * @return array<string,Verifier>
	 */
	public function all() {
		return $this->verifiers;
	}

	/**
	 * Check whether a verifier is independent of the generator context.
	 *
	 * Enforces verifier's law: a verifier that shares the model/provider/tool
	 * that produced the output is disallowed.
	 *
	 * @param Verifier $verifier Verifier.
	 * @param array                        $generator_context  Context describing the generator.
	 * @return true|WP_Error
	 */
	public function check_independence( Verifier $verifier, array $generator_context ) {
		$profile = $verifier->get_independence_profile();

		$checks = array(
			'provider' => array(
				'value'    => isset( $generator_context['provider'] ) ? (string) $generator_context['provider'] : '',
				'disallow' => isset( $profile['disallowed_providers'] ) ? $profile['disallowed_providers'] : array(),
				/* translators: %s: provider name. */
				'message'  => __( 'Verifier shares provider "%s" with generator.', 'nvoos-content-graph-ai-platform' ),
			),
			'model'    => array(
				'value'    => isset( $generator_context['model'] ) ? (string) $generator_context['model'] : '',
				'disallow' => isset( $profile['disallowed_models'] ) ? $profile['disallowed_models'] : array(),
				/* translators: %s: model name. */
				'message'  => __( 'Verifier shares model "%s" with generator.', 'nvoos-content-graph-ai-platform' ),
			),
			'tool'     => array(
				'value'    => isset( $generator_context['tool'] ) ? (string) $generator_context['tool'] : '',
				'disallow' => isset( $profile['disallowed_tools'] ) ? $profile['disallowed_tools'] : array(),
				/* translators: %s: tool slug. */
				'message'  => __( 'Verifier shares tool "%s" with generator.', 'nvoos-content-graph-ai-platform' ),
			),
		);

		foreach ( $checks as $check ) {
			if ( '' !== $check['value']
				&& ! empty( $check['disallow'] )
				&& in_array( $check['value'], $check['disallow'], true )
			) {
				return new \WP_Error(
					'wp_mcp_ai_verifier_not_independent',
					sprintf( $check['message'], $check['value'] )
				);
			}
		}
		return true;
	}

	/**
	 * Run a verifier and dispatch the `wp_mcp_ai_verifier_result` action.
	 *
	 * @param string $slug              Verifier slug.
	 * @param array  $subject           Subject to verify.
	 * @param array  $context           Context passed to the verifier.
	 * @param array  $generator_context Optional: generator context for independence check.
	 * @return array<string,mixed>|WP_Error
	 */
	public function run( $slug, array $subject, array $context = array(), array $generator_context = array() ) {
		$verifier = $this->get( $slug );
		if ( null === $verifier ) {
			return new \WP_Error(
				'wp_mcp_ai_verifier_not_found',
				/* translators: %s: verifier slug. */
				sprintf( __( 'Verifier "%s" is not registered.', 'nvoos-content-graph-ai-platform' ), $slug )
			);
		}

		if ( ! empty( $generator_context ) ) {
			$independence = $this->check_independence( $verifier, $generator_context );
			if ( is_wp_error( $independence ) ) {
				return $independence;
			}
		}

		$result = $verifier->verify( $subject, $context );

		/**
		 * Fires after a verifier runs.
		 *
		 * @since 1.3.0
		 *
		 * @param array|WP_Error                   $result   Verifier output.
		 * @param Verifier     $verifier Verifier instance.
		 * @param array                            $subject  Subject that was verified.
		 * @param array                            $context  Pipeline context.
		 */
		do_action( 'wp_mcp_ai_verifier_result', $result, $verifier, $subject, $context );

		return $result;
	}
}
