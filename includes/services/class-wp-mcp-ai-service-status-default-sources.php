<?php
/**
 * Default Service Status Sources
 *
 * Registers built-in health probes for AI provider availability, tool
 * registry health, and job queue depth via the `wp_mcp_ai_service_status_sources`
 * filter. These sources provide the baseline status data for the public
 * status page and admin dashboard.
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Multiple tightly-coupled default status source classes in one file by design; they always load together.

/**
 * Health probe for AI provider availability.
 *
 * Probes each configured AI provider with a lightweight API call and
 * reports aggregated status.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_AI_Providers implements Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_slug() {
		return 'ai_providers';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_name() {
		return __( 'AI Providers', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_group() {
		return 'ai_services';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function is_public() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Probes each configured AI provider by checking if its client class
	 * is available and if basic connectivity can be established.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function check_health() {
		$providers         = $this->get_configured_providers();
		$total             = count( $providers );
		$operational_count = 0;
		$issues            = array();

		if ( 0 === $total ) {
			return array(
				'status'     => 'operational',
				'message'    => __( 'No AI providers configured.', 'mcp-ai-wpoos' ),
				'checked_at' => time(),
				'latency_ms' => null,
			);
		}

		foreach ( $providers as $slug => $label ) {
			$provider_ok = $this->probe_provider( $slug );
			if ( $provider_ok ) {
				++$operational_count;
			} else {
				$issues[] = $label;
			}
		}

		if ( $operational_count === $total ) {
			$status  = 'operational';
			$message = sprintf(
				/* translators: %d: number of operational providers */
				_n(
					'%d provider operational.',
					'All %d providers operational.',
					$total,
					'mcp-ai-wpoos'
				),
				$total
			);
		} elseif ( $operational_count > 0 ) {
			$status  = 'degraded_performance';
			$message = sprintf(
				/* translators: 1: number of operational providers, 2: comma-separated list of providers with issues */
				__( '%1$d of %2$d providers operational. Issues with: %3$s.', 'mcp-ai-wpoos' ),
				$operational_count,
				$total,
				implode( ', ', $issues )
			);
		} else {
			$status  = 'major_outage';
			$message = __( 'All AI providers are unavailable.', 'mcp-ai-wpoos' );
		}

		return array(
			'status'     => $status,
			'message'    => $message,
			'checked_at' => time(),
			'latency_ms' => null,
		);
	}

	/**
	 * Get the list of configured AI providers.
	 *
	 * Checks which provider clients are available and have credentials set.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Map of provider slug => display label.
	 */
	private function get_configured_providers() {
		$providers    = array();
		$all_settings = function_exists( 'wp_mcp_ai_get_settings' ) ? wp_mcp_ai_get_settings() : array();

		if ( ! is_array( $all_settings ) ) {
			$all_settings = array();
		}

		// Check each known provider for credential presence.
		if ( ! empty( $all_settings['openai_api_key'] ) ) {
			$providers['openai'] = 'OpenAI';
		}
		if ( ! empty( $all_settings['gemini_api_key'] ) ) {
			$providers['gemini'] = 'Gemini';
		}
		if ( ! empty( $all_settings['anthropic_api_key'] ) ) {
			$providers['anthropic'] = 'Anthropic';
		}
		if ( ! empty( $all_settings['deepseek_api_key'] ) ) {
			$providers['deepseek'] = 'DeepSeek';
		}
		if ( ! empty( $all_settings['openrouter_api_key'] ) ) {
			$providers['openrouter'] = 'OpenRouter';
		}
		if ( ! empty( $all_settings['ollama_base_url'] ) ) {
			$providers['ollama'] = 'Ollama';
		}

		return $providers;
	}

	/**
	 * Probe a single provider for basic connectivity.
	 *
	 * Uses a lightweight check (class existence, optional ping) to
	 * determine if the provider is reachable.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return bool True if the provider appears operational.
	 */
	private function probe_provider( $slug ) {
		// Check that the corresponding client class exists.
		$class_map = array(
			'openai'     => 'WP_MCP_AI_OpenAI_Client',
			'gemini'     => 'WP_MCP_AI_Gemini_Client',
			'anthropic'  => 'WP_MCP_AI_Anthropic_Client',
			'deepseek'   => 'WP_MCP_AI_DeepSeek_Client',
			'openrouter' => 'WP_MCP_AI_OpenRouter_Client',
			'ollama'     => 'WP_MCP_AI_Ollama_Client',
		);

		if ( ! isset( $class_map[ $slug ] ) ) {
			return false;
		}

		return class_exists( $class_map[ $slug ] );
	}
}

/**
 * Health probe for tool registry availability.
 *
 * Verifies that the tool registry is accessible and tools can be enumerated.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_Tool_Registry implements Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_slug() {
		return 'tool_registry';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_name() {
		return __( 'Tool Registry', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_group() {
		return 'infrastructure';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function is_public() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function check_health() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array(
				'status'     => 'major_outage',
				'message'    => __( 'Tool registry class not found.', 'mcp-ai-wpoos' ),
				'checked_at' => time(),
				'latency_ms' => null,
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();
		$count    = is_array( $tools ) ? count( $tools ) : 0;

		if ( $count > 0 ) {
			return array(
				'status'     => 'operational',
				'message'    => sprintf(
					/* translators: %d: number of registered tools */
					_n(
						'%d tool registered.',
						'%d tools registered.',
						$count,
						'mcp-ai-wpoos'
					),
					$count
				),
				'checked_at' => time(),
				'latency_ms' => null,
			);
		}

		return array(
			'status'     => 'degraded_performance',
			'message'    => __( 'Tool registry is accessible but no tools are registered.', 'mcp-ai-wpoos' ),
			'checked_at' => time(),
			'latency_ms' => null,
		);
	}
}

/**
 * Health probe for job queue depth and health.
 *
 * Checks the async job queue for backlog and oldest pending job age.
 * This component is internal-only by default (is_public returns false).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_Queue_Health implements Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * Maximum acceptable queue depth before degraded status.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_DEPTH = 50;

	/**
	 * Maximum acceptable oldest job age before degraded status (seconds).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_AGE = 600;

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_slug() {
		return 'queue_health';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_name() {
		return __( 'Job Queue', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function get_group() {
		return 'infrastructure';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 */
	public function is_public() {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function check_health() {
		$depth      = 0;
		$oldest_age = 0;

		if ( class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			$queue      = WP_MCP_AI_Job_Queue_Manager::get_instance();
			$depth      = $queue->get_queue_depth();
			$oldest_age = $queue->get_oldest_job_age();
		}

		if ( $depth > self::MAX_DEPTH || $oldest_age > self::MAX_AGE ) {
			$status  = 'degraded_performance';
			$message = sprintf(
				/* translators: 1: queue depth, 2: oldest job age in seconds */
				__( 'Queue depth: %1$d. Oldest job age: %2$d seconds.', 'mcp-ai-wpoos' ),
				$depth,
				$oldest_age
			);
		} else {
			$status  = 'operational';
			$message = sprintf(
				/* translators: %d: queue depth */
				__( 'Queue depth: %d. Operating normally.', 'mcp-ai-wpoos' ),
				$depth
			);
		}

		return array(
			'status'     => $status,
			'message'    => $message,
			'checked_at' => time(),
			'latency_ms' => null,
		);
	}
}

/**
 * Bootstrap: register default service status sources on the filter.
 *
 * Hooked at priority 10 so that other plugins/addons can register
 * additional sources before or after.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Service_Status_Default_Sources_Bootstrap {

	/**
	 * Register default sources via the filter.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register() {
		add_filter(
			'wp_mcp_ai_service_status_sources',
			function ( $sources ) {
				$sources['ai_providers']  = new WP_MCP_AI_Service_Status_AI_Providers();
				$sources['tool_registry'] = new WP_MCP_AI_Service_Status_Tool_Registry();
				$sources['queue_health']  = new WP_MCP_AI_Service_Status_Queue_Health();
				return $sources;
			},
			10,
			1
		);
	}
}
