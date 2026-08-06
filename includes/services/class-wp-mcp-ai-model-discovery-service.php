<?php
/**
 * Model Catalog Discovery Service
 *
 * Centralises the logic that compares each provider's published model list
 * against the locally bundled catalog (includes/data/model-catalog.json) and
 * produces a human-reviewable list of suggested catalog updates.
 *
 * The service is consumed by:
 *   - The WP-Cron job `wp_mcp_ai_model_catalog_discovery` (daily by default).
 *   - The user-facing tool `discover_new_models` (see
 *     includes/tools/class-wp-mcp-ai-tool-discover-new-models.php).
 *
 * Suggestions are *never* applied automatically. Admins review them in the
 * "Model catalog suggestions" panel and explicitly approve each entry, which
 * writes through the standard model editor code path.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service that discovers new / sunset / re-priced models across providers.
 */
class WP_MCP_AI_Model_Discovery_Service {

	/**
	 * Option key under which the latest discovery diff is stored.
	 */
	const SUGGESTIONS_OPTION = 'wp_mcp_ai_model_catalog_suggestions';

	/**
	 * Option key recording the last successful run timestamp.
	 */
	const LAST_RUN_OPTION = 'wp_mcp_ai_model_catalog_discovery_last_run';

	/**
	 * Run discovery for the supplied providers (or all enabled providers).
	 *
	 * @param array $providers   Optional list of provider slugs to query. Empty = all enabled.
	 * @param array $options     Optional flags: array( 'persist' => true ).
	 * @return array {
	 *     @type array $additions  Models present at provider but not in catalog.
	 *     @type array $sunsets    Models present in catalog but not at provider.
	 *     @type array $price_changes Models whose pricing differs from catalog.
	 *     @type array $errors     Per-provider error messages.
	 *     @type string $status    'ok' or 'partial'.
	 * }
	 */
	public function run( array $providers = array(), array $options = array() ) {
		$persist = ! isset( $options['persist'] ) || (bool) $options['persist'];

		$diff = array(
			'additions'     => array(),
			'sunsets'       => array(),
			'price_changes' => array(),
			'errors'        => array(),
			'status'        => 'ok',
			'generated_at'  => current_time( 'mysql', true ),
		);

		if ( empty( $providers ) ) {
			$providers = $this->detect_enabled_providers();
		}

		$catalog = $this->load_catalog_index();

		foreach ( $providers as $provider ) {
			$provider = sanitize_text_field( $provider );
			$result   = $this->fetch_provider_models( $provider );

			if ( is_wp_error( $result ) ) {
				$diff['errors'][ $provider ] = $result->get_error_message();
				$diff['status']              = 'partial';
				continue;
			}

			foreach ( $result as $model_id => $remote_meta ) {
				$model_id = sanitize_text_field( $model_id );
				if ( '' === $model_id ) {
					continue;
				}

				if ( ! isset( $catalog[ $model_id ] ) ) {
					$diff['additions'][] = array(
						'provider' => $provider,
						'model_id' => $model_id,
						'remote'   => $remote_meta,
					);
					continue;
				}

				$local = $catalog[ $model_id ];
				$this->detect_price_change( $local, $remote_meta, $provider, $model_id, $diff );
			}

			// Sunset detection: catalog entries for this provider missing from remote.
			foreach ( $catalog as $cat_id => $cat_entry ) {
				if ( empty( $cat_entry['provider'] ) || $cat_entry['provider'] !== $provider ) {
					continue;
				}
				if ( isset( $result[ $cat_id ] ) ) {
					continue;
				}
				$diff['sunsets'][] = array(
					'provider' => $provider,
					'model_id' => $cat_id,
					'status'   => isset( $cat_entry['status'] ) ? $cat_entry['status'] : 'active',
				);
			}
		}

		if ( $persist ) {
			update_option( self::SUGGESTIONS_OPTION, $diff, false );
			update_option( self::LAST_RUN_OPTION, time(), false );

			/**
			 * Fires after the discovery service has produced a fresh diff.
			 *
			 * @since 2026.04
			 *
			 * @param array $diff Discovery diff payload.
			 */
			do_action( 'wp_mcp_ai_model_catalog_suggestions_updated', $diff );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'model_catalog_discovery_completed',
					'Model catalog discovery cron run completed.',
					array(
						'providers' => $providers,
						'status'    => $diff['status'],
						'counts'    => array(
							'additions'     => count( $diff['additions'] ),
							'sunsets'       => count( $diff['sunsets'] ),
							'price_changes' => count( $diff['price_changes'] ),
							'errors'        => count( $diff['errors'] ),
						),
					)
				);
			}
		}

		return $diff;
	}

	/**
	 * Build an associative index of the current catalog keyed by model id.
	 *
	 * @return array
	 */
	protected function load_catalog_index() {
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			return array();
		}

		$entries = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();
		$index   = array();
		foreach ( (array) $entries as $entry ) {
			if ( empty( $entry['model_name'] ) ) {
				continue;
			}
			$index[ $entry['model_name'] ] = $entry;
		}
		return $index;
	}

	/**
	 * Detect price drift between catalog and remote metadata.
	 *
	 * @param array  $local       Catalog entry.
	 * @param array  $remote      Remote metadata (best effort - providers vary).
	 * @param string $provider    Provider slug.
	 * @param string $model_id    Model identifier.
	 * @param array  $diff        Diff array to mutate (passed by reference).
	 * @return void
	 */
	protected function detect_price_change( $local, $remote, $provider, $model_id, array &$diff ) {
		if ( ! is_array( $remote ) ) {
			return;
		}

		$local_in   = isset( $local['cost_per_1k_input_tokens'] ) ? (float) $local['cost_per_1k_input_tokens'] : null;
		$local_out  = isset( $local['cost_per_1k_output_tokens'] ) ? (float) $local['cost_per_1k_output_tokens'] : null;
		$remote_in  = isset( $remote['cost_per_1k_input_tokens'] ) ? (float) $remote['cost_per_1k_input_tokens'] : null;
		$remote_out = isset( $remote['cost_per_1k_output_tokens'] ) ? (float) $remote['cost_per_1k_output_tokens'] : null;

		if ( null === $remote_in && null === $remote_out ) {
			return;
		}
		$tolerance = 0.000001;
		$changed   = false;
		if ( null !== $local_in && null !== $remote_in && abs( $local_in - $remote_in ) > $tolerance ) {
			$changed = true;
		}
		if ( null !== $local_out && null !== $remote_out && abs( $local_out - $remote_out ) > $tolerance ) {
			$changed = true;
		}
		if ( $changed ) {
			$diff['price_changes'][] = array(
				'provider' => $provider,
				'model_id' => $model_id,
				'local'    => array(
					'input_per_1k'  => $local_in,
					'output_per_1k' => $local_out,
				),
				'remote'   => array(
					'input_per_1k'  => $remote_in,
					'output_per_1k' => $remote_out,
				),
			);
		}
	}

	/**
	 * Detect which providers are configured/enabled in plugin settings.
	 *
	 * @return array
	 */
	protected function detect_enabled_providers() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = array();
		$keys     = array(
			'openai'       => 'openai_api_key',
			'anthropic'    => 'anthropic_api_key',
			'gemini'       => 'gemini_api_key',
			'huggingface'  => 'huggingface_api_key',
			'nvidia'       => 'nvidia_api_key',
			'cloudflare'   => 'cloudflare_api_token',
			'deepseek'     => 'deepseek_api_key',
			'openrouter'   => 'openrouter_api_key',
			'digitalocean' => 'digitalocean_api_key',
			'baseten'      => 'baseten_api_key',
		);
		foreach ( $keys as $provider => $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$enabled[] = $provider;
			}
		}
		return $enabled;
	}

	/**
	 * Fetch the model list from a single provider.
	 *
	 * Delegates to the legacy tool for HTTP behavior so we do not duplicate
	 * provider-specific request patterns. Returns a map of model_id => meta.
	 *
	 * @param string $provider Provider slug.
	 * @return array|WP_Error
	 */
	protected function fetch_provider_models( $provider ) {
		$tool_path = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-discover-new-models.php';
		if ( ! file_exists( $tool_path ) ) {
			return new WP_Error( 'discovery_tool_missing', __( 'Model discovery tool is unavailable.', 'mcp-ai-wpoos' ) );
		}
		require_once $tool_path;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Discover_New_Models' ) ) {
			return new WP_Error( 'discovery_tool_missing_class', __( 'Discovery tool class missing.', 'mcp-ai-wpoos' ) );
		}

		$tool = new WP_MCP_AI_Tool_Discover_New_Models();

		// NOTE: The tool's HTTP fetcher is intentionally protected. We use reflection
		// here to share that implementation with the cron job without duplicating
		// per-provider request logic. If the tool's `fetch_provider_models()` method
		// signature ever changes, this branch will return a tool-missing-fetcher
		// error rather than crashing — the cron run will be marked `partial` and
		// the offending provider listed in the diff's `errors` map.
		// TODO: Promote `fetch_provider_models()` to a public, interface-backed
		// API once the tool surface is refactored to a shared
		// WP_MCP_AI_Provider_Models_Source contract.
		$reflection = new ReflectionClass( $tool );
		if ( ! $reflection->hasMethod( 'fetch_provider_models' ) ) {
			return new WP_Error( 'discovery_no_fetcher', __( 'Discovery tool does not expose a fetcher.', 'mcp-ai-wpoos' ) );
		}
		$method = $reflection->getMethod( 'fetch_provider_models' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return array();
		}
		return $result;
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public static function cron_handler() {
		/**
		 * Filter whether the model catalog discovery cron should run.
		 *
		 * @since 2026.04
		 *
		 * @param bool $enabled Defaults to true.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_model_discovery_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		$service = new self();
		$service->run();
	}
}
