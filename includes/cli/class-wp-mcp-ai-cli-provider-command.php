<?php
/**
 * WP-CLI command for AI provider management.
 *
 * @package WP_MCP_AI
 * @since   1.1.30
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage AI providers: list, test connections, and list models.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Provider_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Provider short name → human-readable label map.
	 *
	 * @var array<string,string>
	 */
	private static $provider_labels = array(
		'openai'       => 'OpenAI',
		'gemini'       => 'Google Gemini',
		'anthropic'    => 'Anthropic',
		'deepseek'     => 'DeepSeek',
		'openrouter'   => 'OpenRouter',
		'baseten'      => 'Baseten',
		'kimi'         => 'Kimi (Moonshot AI)',
		'digitalocean' => 'DigitalOcean Serverless Inference',
		'nvidia'       => 'NVIDIA NIM',
		'cloudflare'   => 'Cloudflare Worker AI',
		'huggingface'  => 'Hugging Face',
		'ollama'       => 'Ollama',
		'lm_studio'    => 'LM Studio',
	);

	/**
	 * List all configured AI providers and their status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai provider list
	 *     $ wp mcp-ai provider list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format = $assoc_args['format'] ?? 'table';
		$items  = array();

		$all_providers = self::$provider_labels;

		// Discover active providers via the model config when available.
		if ( class_exists( 'WP_MCP_AI_Model_Config' ) && method_exists( 'WP_MCP_AI_Model_Config', 'get_available_providers' ) ) {
			$available = WP_MCP_AI_Model_Config::get_available_providers();
		} else {
			// Fallback: check settings for enabled providers the old way.
			$settings  = get_option( 'wp_mcp_ai_settings', array() );
			$available = array();
			foreach ( array_keys( self::$provider_labels ) as $slug ) {
				$enable_key = 'enable_' . $slug;
				if ( ! empty( $settings[ $enable_key ] ) ) {
					$available[ $slug ] = self::$provider_labels[ $slug ];
				}
			}
		}

		foreach ( $all_providers as $slug => $label ) {
			$enabled = isset( $available[ $slug ] );
			$items[] = array(
				'Provider' => $label,
				'Slug'     => $slug,
				'Status'   => $enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ),
			);
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %1$d: total provider count, %2$d: enabled provider count */
				__( 'Found %1$d providers (%2$d enabled).', 'mcp-ai-wpoos' ),
				count( $items ),
				count( $available )
			)
		);
	}

	/**
	 * Test a provider connection.
	 *
	 * ## OPTIONS
	 *
	 * <provider>
	 * : Provider slug (e.g., openai, gemini, anthropic, ollama).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai provider test openai
	 *     $ wp mcp-ai provider test ollama
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$slug = sanitize_key( (string) ( $args[0] ?? '' ) );

		if ( '' === $slug ) {
			$this->error( __( 'Provider slug is required. Use "wp mcp-ai provider list" to see available providers.', 'mcp-ai-wpoos' ) );
		}

		$client = $this->get_provider_client( $slug );
		if ( is_wp_error( $client ) ) {
			$this->error( $client->get_error_message() );
		}

		if ( ! method_exists( $client, 'test_connection' ) ) {
			$this->error(
				sprintf(
					/* translators: %s: provider name */
					__( '%s does not support connection testing.', 'mcp-ai-wpoos' ),
					self::$provider_labels[ $slug ] ?? $slug
				)
			);
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: provider name */
				__( 'Testing %s connection…', 'mcp-ai-wpoos' ),
				self::$provider_labels[ $slug ] ?? $slug
			)
		);

		$start  = microtime( true );
		$result = null;
		try {
			$result = $client->test_connection();
		} catch ( \Exception $e ) {
			$result = new WP_Error( 'connection_test_exception', $e->getMessage() );
		}
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			$this->error(
				sprintf(
					/* translators: %1$s: latency in ms, %2$s: error message */
					__( 'Connection failed (%1$s ms): %2$s', 'mcp-ai-wpoos' ),
					$elapsed,
					$result->get_error_message()
				),
				false
			);
			return;
		}

		// Build a friendly result display.
		$details = is_array( $result ) ? $result : array( 'message' => (string) $result );

		// Show results as key-value pairs for clarity.
		$items = array(
			array(
				'Key'   => 'Latency',
				'Value' => $elapsed . ' ms',
			),
		);
		foreach ( $details as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$items[] = array(
					'Key'   => ucwords( str_replace( '_', ' ', (string) $key ) ),
					'Value' => (string) $value,
				);
			}
		}

		$this->format_output( $items, 'table' );
		$this->success(
			sprintf(
				/* translators: %s: provider name */
				__( '%s connection successful.', 'mcp-ai-wpoos' ),
				self::$provider_labels[ $slug ] ?? $slug
			)
		);
	}

	/**
	 * List available models for a provider.
	 *
	 * ## OPTIONS
	 *
	 * <provider>
	 * : Provider slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai provider models openai
	 *     $ wp mcp-ai provider models ollama
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function models( $args, $assoc_args ) {
		$slug   = sanitize_key( (string) ( $args[0] ?? '' ) );
		$format = $assoc_args['format'] ?? 'table';

		if ( '' === $slug ) {
			$this->error( __( 'Provider slug is required.', 'mcp-ai-wpoos' ) );
		}

		$client = $this->get_provider_client( $slug );
		if ( is_wp_error( $client ) ) {
			$this->error( $client->get_error_message() );
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: provider name */
				__( 'Fetching models for %s…', 'mcp-ai-wpoos' ),
				self::$provider_labels[ $slug ] ?? $slug
			)
		);

		try {
			$models = $client->list_models();
		} catch ( \Exception $e ) {
			$this->error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to list models: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $models ) ) {
			$this->error( $models->get_error_message() );
		}

		if ( empty( $models ) ) {
			$this->warning( __( 'No models returned. The provider may require authentication or list_models() returned empty.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $models as $model ) {
			if ( is_string( $model ) ) {
				$items[] = array( 'Model' => $model );
			} elseif ( is_array( $model ) ) {
				$items[] = array(
					'Model'    => $model['id'] ?? $model['name'] ?? '',
					'Owned By' => $model['owned_by'] ?? '',
				);
			}
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of models */
				__( 'Found %d models.', 'mcp-ai-wpoos' ),
				count( $models )
			)
		);
	}

	/**
	 * Get a provider client instance by slug.
	 *
	 * @param string $slug Provider slug.
	 * @return object|WP_Error Client instance or WP_Error.
	 */
	private function get_provider_client( $slug ) {
		// Map slugs to client class names.
		$client_map = array(
			'openai'       => 'WP_MCP_AI_OpenAI_Client',
			'gemini'       => 'WP_MCP_AI_Gemini_Client',
			'anthropic'    => 'WP_MCP_AI_Anthropic_Client',
			'deepseek'     => 'WP_MCP_AI_DeepSeek_Client',
			'openrouter'   => 'WP_MCP_AI_OpenRouter_Client',
			'baseten'      => 'WP_MCP_AI_Baseten_Client',
			'kimi'         => 'WP_MCP_AI_Kimi_Client',
			'digitalocean' => 'WP_MCP_AI_DigitalOcean_Client',
			'nvidia'       => 'WP_MCP_AI_Nvidia_Client',
			'cloudflare'   => 'WP_MCP_AI_Cloudflare_Client',
			'huggingface'  => 'WP_MCP_AI_Huggingface_Client',
			'ollama'       => 'WP_MCP_AI_Ollama_Client',
			'lm_studio'    => 'WP_MCP_AI_LM_Studio_Client',
		);

		if ( ! isset( $client_map[ $slug ] ) ) {
			return new WP_Error(
				'provider_not_found',
				sprintf(
					/* translators: %1$s: provider slug, %2$s: comma-separated list of available providers */
					__( 'Unknown provider "%1$s". Available: %2$s', 'mcp-ai-wpoos' ),
					$slug,
					implode( ', ', array_keys( $client_map ) )
				)
			);
		}

		$class = $client_map[ $slug ];
		if ( ! class_exists( $class ) ) {
			return new WP_Error(
				'provider_class_missing',
				sprintf(
					/* translators: %s: class name */
					__( 'Provider class "%s" not found. The provider may be disabled or not installed.', 'mcp-ai-wpoos' ),
					$class
				)
			);
		}

		return new $class();
	}
}

WP_CLI::add_command( 'mcp-ai provider', 'WP_MCP_AI_CLI_Provider_Command' );
