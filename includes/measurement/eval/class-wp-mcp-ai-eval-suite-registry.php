<?php
/**
 * Eval Suite Registry
 *
 * Collects eval suites registered via the `wp_mcp_ai_register_eval_suites`
 * action. Mirrors the verifier/reward registry pattern: singleton, boot()
 * once per request, idempotent registration, slug-based lookup.
 *
 * Suites are intentionally stored in memory. Persistence of eval RESULTS
 * (scores, timings, verifier evidence) is deferred to later PRs — this
 * registry only tracks the declarative definitions.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eval Suite Registry.
 */
class WP_MCP_AI_Eval_Suite_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Eval_Suite_Registry|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Suites keyed by slug.
	 *
	 * @var array<string,WP_MCP_AI_Eval_Suite>
	 */
	private $suites = array();

	/**
	 * Get singleton.
	 *
	 * @return WP_MCP_AI_Eval_Suite_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Boot: fires the registration hook exactly once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Fires when the eval suite registry is ready to accept suites.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_MCP_AI_Eval_Suite_Registry $registry Registry instance.
		 */
		do_action( 'wp_mcp_ai_register_eval_suites', $this );
	}

	/**
	 * Register a suite. Accepts a suite instance or an args array.
	 *
	 * @param WP_MCP_AI_Eval_Suite|array $suite Suite or definition.
	 * @return WP_MCP_AI_Eval_Suite|WP_Error
	 */
	public function register( $suite ) {
		if ( is_array( $suite ) ) {
			try {
				$suite = new WP_MCP_AI_Eval_Suite( $suite );
			} catch ( InvalidArgumentException $e ) {
				return new WP_Error( 'wp_mcp_ai_eval_suite_invalid', $e->getMessage() );
			}
		}
		if ( ! $suite instanceof WP_MCP_AI_Eval_Suite ) {
			return new WP_Error(
				'wp_mcp_ai_eval_suite_invalid',
				__( 'Eval suite must be an array definition or a WP_MCP_AI_Eval_Suite instance.', 'mcp-ai-wpoos' )
			);
		}
		$this->suites[ $suite->get_slug() ] = $suite;
		return $suite;
	}

	/**
	 * Unregister a suite.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function unregister( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( isset( $this->suites[ $slug ] ) ) {
			unset( $this->suites[ $slug ] );
			return true;
		}
		return false;
	}

	/**
	 * Get a suite by slug.
	 *
	 * @param string $slug Slug.
	 * @return WP_MCP_AI_Eval_Suite|null
	 */
	public function get( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( $this->suites[ $slug ] ) ? $this->suites[ $slug ] : null;
	}

	/**
	 * Get all registered suites.
	 *
	 * @return array<string,WP_MCP_AI_Eval_Suite>
	 */
	public function all() {
		return $this->suites;
	}

	/**
	 * Get suites scoped to a specific artifact type (and optionally ID).
	 *
	 * A suite matches when its artifact type equals `$artifact_type` and
	 * either side of the ID comparison is empty (empty ID = wildcard).
	 *
	 * @since 1.9.0
	 *
	 * @param string $artifact_type Artifact type (e.g. 'prompt').
	 * @param string $artifact_id   Optional artifact identifier.
	 * @return array<string,WP_MCP_AI_Eval_Suite>
	 */
	public function get_suites_for_artifact( $artifact_type, $artifact_id = '' ) {
		$artifact_type = sanitize_key( (string) $artifact_type );
		$artifact_id   = sanitize_key( (string) $artifact_id );

		if ( '' === $artifact_type ) {
			return array();
		}

		$matches = array();
		foreach ( $this->suites as $slug => $suite ) {
			if ( $suite->get_artifact_type() !== $artifact_type ) {
				continue;
			}
			$suite_id = $suite->get_artifact_id();
			if ( '' !== $artifact_id && '' !== $suite_id && $artifact_id !== $suite_id ) {
				continue;
			}
			$matches[ $slug ] = $suite;
		}

		return $matches;
	}

	/**
	 * Get the general-purpose suites (not scoped to any artifact).
	 *
	 * @since 1.9.0
	 *
	 * @return array<string,WP_MCP_AI_Eval_Suite>
	 */
	public function get_general_suites() {
		$matches = array();
		foreach ( $this->suites as $slug => $suite ) {
			if ( '' === $suite->get_artifact_type() ) {
				$matches[ $slug ] = $suite;
			}
		}

		return $matches;
	}
}
