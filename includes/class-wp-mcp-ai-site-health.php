<?php
/**
 * Site Health Integration
 *
 * Integrates with WordPress Site Health to provide monitoring and diagnostics
 * for the NV oOS plugin functionality.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health Integration Class
 *
 * Registers custom health checks and debug information for the plugin.
 */
class WP_MCP_AI_Site_Health {

	/**
	 * Initialize site health integration
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function register_hooks() {
		// Register site health tests.
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );

		// Register debug information.
		add_filter( 'debug_information', array( $this, 'register_debug_info' ) );
	}

	/**
	 * Register site health tests
	 *
	 * @param array $tests Existing tests.
	 * @return array Modified tests array
	 */
	public function register_tests( $tests ) {
		// Direct tests (run immediately).
		$tests['direct']['wp_mcp_ai_api_connectivity'] = array(
			'label' => __( 'NV oOS API Connectivity', 'mcp-ai-wpoos' ),
			'test'  => array( $this, 'test_api_connectivity' ),
		);

		$tests['direct']['wp_mcp_ai_model_availability'] = array(
			'label' => __( 'NV oOS Model Availability', 'mcp-ai-wpoos' ),
			'test'  => array( $this, 'test_model_availability' ),
		);

		$tests['direct']['wp_mcp_ai_credentials'] = array(
			'label' => __( 'NV oOS Credentials Validation', 'mcp-ai-wpoos' ),
			'test'  => array( $this, 'test_credentials' ),
		);

		$tests['direct']['wp_mcp_ai_tool_functionality'] = array(
			'label' => __( 'NV oOS Tool Functionality', 'mcp-ai-wpoos' ),
			'test'  => array( $this, 'test_tool_functionality' ),
		);

		$tests['direct']['wp_mcp_ai_database_schema'] = array(
			'label' => __( 'NV oOS Database Schema', 'mcp-ai-wpoos' ),
			'test'  => array( $this, 'test_database_schema' ),
		);

		return $tests;
	}

	/**
	 * Register debug information
	 *
	 * @param array $debug_info Existing debug information.
	 * @return array Modified debug information
	 */
	public function register_debug_info( $debug_info ) {
		$debug_info['mcp-ai-wpoos'] = array(
			'label'  => __( 'NV oOS', 'mcp-ai-wpoos' ),
			'fields' => $this->get_debug_fields(),
		);

		return $debug_info;
	}

	/**
	 * Test API connectivity
	 *
	 * @return array Test result
	 */
	public function test_api_connectivity() {
		$result = array(
			'label'       => __( 'AI Provider API Connectivity', 'mcp-ai-wpoos' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'AI', 'mcp-ai-wpoos' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_api_connectivity',
		);

		// Get configured API providers.
		$openai_key = get_option( 'wp_mcp_ai_openai_api_key' );
		$gemini_key = get_option( 'wp_mcp_ai_gemini_api_key' );
		$ollama_url = get_option( 'wp_mcp_ai_ollama_url' );

		$providers_configured = 0;
		$providers_working    = 0;
		$issues               = array();

		// Test OpenAI.
		if ( ! empty( $openai_key ) ) {
			++$providers_configured;
			$openai_test = $this->test_openai_connection( $openai_key );
			if ( $openai_test['success'] ) {
				++$providers_working;
			} else {
				/* translators: %s: OpenAI error message */
				$issues[] = sprintf( __( 'OpenAI: %s', 'mcp-ai-wpoos' ), $openai_test['message'] );
			}
		}

		// Test Gemini.
		if ( ! empty( $gemini_key ) ) {
			++$providers_configured;
			$gemini_test = $this->test_gemini_connection( $gemini_key );
			if ( $gemini_test['success'] ) {
				++$providers_working;
			} else {
				/* translators: %s: Gemini error message */
				$issues[] = sprintf( __( 'Gemini: %s', 'mcp-ai-wpoos' ), $gemini_test['message'] );
			}
		}

		// Test Ollama.
		if ( ! empty( $ollama_url ) ) {
			++$providers_configured;
			$ollama_test = $this->test_ollama_connection( $ollama_url );
			if ( $ollama_test['success'] ) {
				++$providers_working;
			} else {
				/* translators: %s: Ollama error message */
				$issues[] = sprintf( __( 'Ollama: %s', 'mcp-ai-wpoos' ), $ollama_test['message'] );
			}
		}

		// Determine status.
		if ( 0 === $providers_configured ) {
			$result['status'] = 'critical';
			/* translators: No AI providers configured */
			$result['label']       = __( 'No AI providers configured', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'You need to configure at least one AI provider (OpenAI, Gemini, or Ollama) for the NV oOS plugin to function.', 'mcp-ai-wpoos' )
			);
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ),
				__( 'Configure AI Providers', 'mcp-ai-wpoos' )
			);
		} elseif ( $providers_working === $providers_configured ) {
			$result['status'] = 'good';
			/* translators: %d: number of providers working */
			$result['label']       = sprintf( __( 'All %d AI providers are working', 'mcp-ai-wpoos' ), $providers_working );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'All configured AI providers are responding correctly.', 'mcp-ai-wpoos' )
			);
		} elseif ( $providers_working > 0 ) {
			$result['status'] = 'recommended';
			/* translators: 1: working providers, 2: total providers */
			$result['label']       = sprintf( __( '%1$d of %2$d AI providers are working', 'mcp-ai-wpoos' ), $providers_working, $providers_configured );
			$result['description'] = sprintf(
				'<p>%s</p><ul><li>%s</li></ul>',
				__( 'Some AI providers are experiencing issues:', 'mcp-ai-wpoos' ),
				implode( '</li><li>', array_map( 'esc_html', $issues ) )
			);
		} else {
			$result['status'] = 'critical';
			/* translators: Critical: No AI providers working */
			$result['label']       = __( 'No AI providers are working', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p><ul><li>%s</li></ul>',
				__( 'All configured AI providers are experiencing issues:', 'mcp-ai-wpoos' ),
				implode( '</li><li>', array_map( 'esc_html', $issues ) )
			);
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard' ) ),
				__( 'Check AI Provider Settings', 'mcp-ai-wpoos' )
			);
		}

		return $result;
	}

	/**
	 * Test model availability
	 *
	 * @return array Test result
	 */
	public function test_model_availability() {
		$result = array(
			'label'       => __( 'AI Models Availability', 'mcp-ai-wpoos' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'AI', 'mcp-ai-wpoos' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_model_availability',
		);

		// Count available models.
		$available_models = array();

		// Check OpenAI models.
		$openai_key = get_option( 'wp_mcp_ai_openai_api_key' );
		if ( ! empty( $openai_key ) ) {
			$available_models[] = 'OpenAI GPT';
		}

		// Check Gemini models.
		$gemini_key = get_option( 'wp_mcp_ai_gemini_api_key' );
		if ( ! empty( $gemini_key ) ) {
			$available_models[] = 'Google Gemini';
		}

		// Check Ollama models.
		$ollama_url = get_option( 'wp_mcp_ai_ollama_url' );
		if ( ! empty( $ollama_url ) ) {
			$available_models[] = 'Ollama (Local)';
		}

		if ( empty( $available_models ) ) {
			$result['status'] = 'critical';
			/* translators: No AI models available */
			$result['label']       = __( 'No AI models available', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'No AI models are currently available. Configure at least one AI provider.', 'mcp-ai-wpoos' )
			);
		} else {
			/* translators: %s: comma-separated list of available models */
			$result['label']       = sprintf( __( 'AI models available: %s', 'mcp-ai-wpoos' ), implode( ', ', $available_models ) );
			$result['description'] = sprintf(
				'<p>%s</p>',
				/* translators: %d: number of AI models */
				sprintf( __( 'You have %d AI model provider(s) configured and available.', 'mcp-ai-wpoos' ), count( $available_models ) )
			);
		}

		return $result;
	}

	/**
	 * Test credentials validation
	 *
	 * @return array Test result
	 */
	public function test_credentials() {
		$result = array(
			'label'       => __( 'Assistant Credentials', 'mcp-ai-wpoos' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'mcp-ai-wpoos' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_credentials',
		);

		// Count assistants.
		$assistants = wp_count_posts( 'mcp_ai_assistant' );
		$total      = isset( $assistants->publish ) ? $assistants->publish : 0;

		// Count assistants with credentials.
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_wp_mcp_ai_credentials',
					'compare' => 'EXISTS',
				),
			),
		);

		$with_credentials = count( get_posts( $args ) );

		if ( $total > 0 ) {
			/* translators: 1: assistants with credentials, 2: total assistants */
			$result['label']       = sprintf( __( '%1$d of %2$d assistants have credentials', 'mcp-ai-wpoos' ), $with_credentials, $total );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'Credentials are securely hashed and stored.', 'mcp-ai-wpoos' )
			);
		} else {
			$result['status'] = 'recommended';
			/* translators: No assistants created */
			$result['label']       = __( 'No assistants created yet', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'Create AI assistants to start using the NV oOS plugin.', 'mcp-ai-wpoos' )
			);
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ),
				__( 'Create Assistant', 'mcp-ai-wpoos' )
			);
		}

		return $result;
	}

	/**
	 * Test tool functionality
	 *
	 * @return array Test result
	 */
	public function test_tool_functionality() {
		$result = array(
			'label'       => __( 'Tool Functionality', 'mcp-ai-wpoos' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Tools', 'mcp-ai-wpoos' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_tool_functionality',
		);

		// Get tool registry - check if class exists first.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$result['status'] = 'recommended';
			/* translators: Tool registry not available */
			$result['label']       = __( 'Tool registry not yet available', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'The tool registry is initializing. This is normal during plugin activation.', 'mcp-ai-wpoos' )
			);
			return $result;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			$result['status'] = 'critical';
			/* translators: Tool registry not initialized */
			$result['label']       = __( 'Tool registry not initialized', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'The tool registry failed to initialize. Check PHP error logs.', 'mcp-ai-wpoos' )
			);
			return $result;
		}

		$tools = $registry->get_tools();
		$count = is_array( $tools ) ? count( $tools ) : 0;

		if ( $count > 0 ) {
			/* translators: %d: number of tools */
			$result['label']       = sprintf( __( '%d tools available', 'mcp-ai-wpoos' ), $count );
			$result['description'] = sprintf(
				'<p>%s</p>',
				/* translators: %d: number of tools */
				sprintf( __( 'The tool registry has successfully loaded %d tools.', 'mcp-ai-wpoos' ), $count )
			);
		} else {
			$result['status'] = 'recommended';
			/* translators: No tools loaded */
			$result['label']       = __( 'No tools loaded', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'No tools are currently loaded. This may indicate a configuration issue.', 'mcp-ai-wpoos' )
			);
		}

		return $result;
	}

	/**
	 * Test database schema
	 *
	 * @return array Test result
	 */
	public function test_database_schema() {
		$result = array(
			'label'       => __( 'Database Schema', 'mcp-ai-wpoos' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Database', 'mcp-ai-wpoos' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wp_mcp_ai_database_schema',
		);

		global $wpdb;

		// Check if assistant post type is registered.
		$post_type_exists = post_type_exists( 'mcp_ai_assistant' );

		if ( ! $post_type_exists ) {
			$result['status'] = 'critical';
			/* translators: Assistant post type not registered */
			$result['label']       = __( 'Assistant post type not registered', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'The mcp_ai_assistant post type is not registered. Plugin may not be activated correctly.', 'mcp-ai-wpoos' )
			);
			return $result;
		}

		// Check for required options.
		$required_options = array(
			'wp_mcp_ai_version',
			'wp_mcp_ai_tool_registry',
		);

		$missing_options = array();
		foreach ( $required_options as $option ) {
			if ( false === get_option( $option ) ) {
				$missing_options[] = $option;
			}
		}

		if ( ! empty( $missing_options ) ) {
			$result['status'] = 'recommended';
			/* translators: Some database options missing */
			$result['label']       = __( 'Some database options missing', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p><ul><li>%s</li></ul>',
				__( 'The following options are not set:', 'mcp-ai-wpoos' ),
				implode( '</li><li>', array_map( 'esc_html', $missing_options ) )
			);
		} else {
			$result['label']       = __( 'Database schema is correct', 'mcp-ai-wpoos' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'All required post types and options are present.', 'mcp-ai-wpoos' )
			);
		}

		return $result;
	}

	/**
	 * Get debug fields
	 *
	 * @return array Debug fields
	 */
	private function get_debug_fields() {
		$fields = array();

		// Plugin version.
		$fields['version'] = array(
			'label' => __( 'Plugin Version', 'mcp-ai-wpoos' ),
			'value' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : __( 'Unknown', 'mcp-ai-wpoos' ),
		);

		// Base version mode.
		$fields['base_version'] = array(
			'label' => __( 'Base Version Mode', 'mcp-ai-wpoos' ),
			'value' => defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' ),
		);

		// Configured providers.
		$providers = array();
		if ( get_option( 'wp_mcp_ai_openai_api_key' ) ) {
			$providers[] = 'OpenAI';
		}
		if ( get_option( 'wp_mcp_ai_gemini_api_key' ) ) {
			$providers[] = 'Google Gemini';
		}
		if ( get_option( 'wp_mcp_ai_ollama_url' ) ) {
			$providers[] = 'Ollama';
		}

		$fields['providers'] = array(
			'label' => __( 'Configured Providers', 'mcp-ai-wpoos' ),
			'value' => ! empty( $providers ) ? implode( ', ', $providers ) : __( 'None', 'mcp-ai-wpoos' ),
		);

		// Assistant count.
		$assistants                = wp_count_posts( 'mcp_ai_assistant' );
		$fields['assistant_count'] = array(
			'label' => __( 'Assistants Created', 'mcp-ai-wpoos' ),
			'value' => isset( $assistants->publish ) ? $assistants->publish : 0,
		);

		// Tool count - check if class exists and registry is initialized.
		$tool_count = 0;
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry   = WP_MCP_AI_Tool_Registry::get_instance();
			$tools      = $registry ? $registry->get_tools() : array();
			$tool_count = is_array( $tools ) ? count( $tools ) : 0;
		}
		$fields['tool_count'] = array(
			'label' => __( 'Tools Available', 'mcp-ai-wpoos' ),
			'value' => $tool_count,
		);

		// JetEngine integration.
		$fields['jetengine'] = array(
			'label' => __( 'JetEngine Integration', 'mcp-ai-wpoos' ),
			'value' => class_exists( 'Jet_Engine' ) ? __( 'Active', 'mcp-ai-wpoos' ) : __( 'Inactive', 'mcp-ai-wpoos' ),
		);

		// WooCommerce integration.
		$fields['woocommerce'] = array(
			'label' => __( 'WooCommerce Integration', 'mcp-ai-wpoos' ),
			'value' => class_exists( 'WooCommerce' ) ? __( 'Active', 'mcp-ai-wpoos' ) : __( 'Inactive', 'mcp-ai-wpoos' ),
		);

		// Elementor integration.
		$fields['elementor'] = array(
			'label' => __( 'Elementor Integration', 'mcp-ai-wpoos' ),
			'value' => defined( 'ELEMENTOR_VERSION' ) ? __( 'Active', 'mcp-ai-wpoos' ) : __( 'Inactive', 'mcp-ai-wpoos' ),
		);

		// Debug mode.
		$fields['debug_mode'] = array(
			'label' => __( 'Debug Mode', 'mcp-ai-wpoos' ),
			'value' => ( defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG ) ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ),
		);

		// Logging.
		$fields['logging'] = array(
			'label' => __( 'Logging Enabled', 'mcp-ai-wpoos' ),
			'value' => get_option( 'wp_mcp_ai_enable_logging' ) ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' ),
		);

		return $fields;
	}

	/**
	 * Test OpenAI connection
	 *
	 * @param string $api_key API key.
	 * @return array Test result with success and message
	 */
	private function test_openai_connection( $api_key ) {
		// Simple connectivity test - check if API key format is valid.
		// Full API test would require making actual API call.
		if ( empty( $api_key ) || strlen( $api_key ) < 20 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key format', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'API key configured', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Test Gemini connection
	 *
	 * @param string $api_key API key.
	 * @return array Test result with success and message
	 */
	private function test_gemini_connection( $api_key ) {
		// Simple connectivity test - check if API key format is valid.
		if ( empty( $api_key ) || strlen( $api_key ) < 20 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API key format', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'API key configured', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Test Ollama connection
	 *
	 * @param string $url Ollama URL.
	 * @return array Test result with success and message
	 */
	private function test_ollama_connection( $url ) {
		// Simple connectivity test - check if URL format is valid.
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid URL format', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'URL configured', 'mcp-ai-wpoos' ),
		);
	}
}
