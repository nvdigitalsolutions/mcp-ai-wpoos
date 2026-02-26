<?php
/**
 * WebLLM Advanced Features Settings Page
 *
 * Admin settings page for WebLLM enhancement features (Phase 1).
 * Located in Pro plugin section for advanced/experimental features.
 *
 * Features:
 * - Tool calling (browser-side execution of WordPress tools)
 * - Multi-modal support (vision models for image analysis)
 * - Performance optimization settings
 * - Debug/diagnostic options
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebLLM Advanced Features Settings Page
 */
class WP_MCP_AI_WebLLM_Settings_Page {

	/**
	 * Settings option name
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_webllm_settings';

	/**
	 * Page slug
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-webllm-settings';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 26 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add settings page to admin menu
	 */
	public function add_settings_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'WebLLM Advanced Features', 'mcp-ai-wpoos' ),
			__( 'WebLLM Features', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
			)
		);

		// Feature Flags Section.
		add_settings_section(
			'webllm_features',
			__( 'Feature Flags', 'mcp-ai-wpoos' ),
			array( $this, 'render_features_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_tool_calling',
			__( 'Enable Tool Calling', 'mcp-ai-wpoos' ),
			array( $this, 'render_tool_calling_field' ),
			self::PAGE_SLUG,
			'webllm_features'
		);

		add_settings_field(
			'enable_multimodal',
			__( 'Enable Multi-Modal (Vision)', 'mcp-ai-wpoos' ),
			array( $this, 'render_multimodal_field' ),
			self::PAGE_SLUG,
			'webllm_features'
		);

		add_settings_field(
			'enable_langchain',
			__( 'Enable LangChain Orchestration', 'mcp-ai-wpoos' ),
			array( $this, 'render_langchain_field' ),
			self::PAGE_SLUG,
			'webllm_features'
		);

		add_settings_field(
			'langchain_enable_streaming',
			__( 'LangChain Streaming', 'mcp-ai-wpoos' ),
			array( $this, 'render_langchain_streaming_field' ),
			self::PAGE_SLUG,
			'webllm_features'
		);

		add_settings_field(
			'enable_web_workers',
			__( 'Enable Web Workers (Phase 4)', 'mcp-ai-wpoos' ),
			array( $this, 'render_web_workers_field' ),
			self::PAGE_SLUG,
			'webllm_features'
		);

		// Performance Section.
		add_settings_section(
			'webllm_performance',
			__( 'Performance & Optimization', 'mcp-ai-wpoos' ),
			array( $this, 'render_performance_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'cache_models',
			__( 'Cache Models in Browser', 'mcp-ai-wpoos' ),
			array( $this, 'render_cache_models_field' ),
			self::PAGE_SLUG,
			'webllm_performance'
		);

		add_settings_field(
			'max_tools',
			__( 'Maximum Tools per Request', 'mcp-ai-wpoos' ),
			array( $this, 'render_max_tools_field' ),
			self::PAGE_SLUG,
			'webllm_performance'
		);

		add_settings_field(
			'langchain_memory_window',
			__( 'LangChain Memory Window', 'mcp-ai-wpoos' ),
			array( $this, 'render_langchain_memory_window_field' ),
			self::PAGE_SLUG,
			'webllm_performance'
		);

		add_settings_field(
			'langchain_max_retries',
			__( 'LangChain Max Retries', 'mcp-ai-wpoos' ),
			array( $this, 'render_langchain_max_retries_field' ),
			self::PAGE_SLUG,
			'webllm_performance'
		);

		// Debug Section.
		add_settings_section(
			'webllm_debug',
			__( 'Debug & Diagnostics', 'mcp-ai-wpoos' ),
			array( $this, 'render_debug_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_console_logs',
			__( 'Enable Console Logging', 'mcp-ai-wpoos' ),
			array( $this, 'render_console_logs_field' ),
			self::PAGE_SLUG,
			'webllm_debug'
		);
	}

	/**
	 * Get default settings
	 *
	 * @return array
	 */
	private function get_default_settings() {
		return array(
			'enable_tool_calling'        => false,
			'enable_multimodal'          => false,
			'enable_langchain'           => false,
			'langchain_enable_streaming' => false,
			'enable_web_workers'         => false,
			'cache_models'               => true,
			'max_tools'                  => 20,
			'langchain_memory_window'    => 10,
			'langchain_max_retries'      => 3,
			'enable_console_logs'        => false,
		);
	}

	/**
	 * Sanitize settings
	 *
	 * @param array $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		$sanitized['enable_tool_calling']        = ! empty( $input['enable_tool_calling'] );
		$sanitized['enable_multimodal']          = ! empty( $input['enable_multimodal'] );
		$sanitized['enable_langchain']           = ! empty( $input['enable_langchain'] );
		$sanitized['langchain_enable_streaming'] = ! empty( $input['langchain_enable_streaming'] );
		$sanitized['enable_web_workers']         = ! empty( $input['enable_web_workers'] );
		$sanitized['cache_models']               = ! empty( $input['cache_models'] );
		$sanitized['max_tools']                  = absint( $input['max_tools'] ?? 20 );
		$sanitized['langchain_memory_window']    = absint( $input['langchain_memory_window'] ?? 10 );
		$sanitized['langchain_max_retries']      = absint( $input['langchain_max_retries'] ?? 3 );
		$sanitized['enable_console_logs']        = ! empty( $input['enable_console_logs'] );

		// Validate max_tools range.
		if ( $sanitized['max_tools'] < 1 ) {
			$sanitized['max_tools'] = 1;
		} elseif ( $sanitized['max_tools'] > 50 ) {
			$sanitized['max_tools'] = 50;
		}

		// Validate langchain_memory_window range (2–50 turn-pairs).
		$sanitized['langchain_memory_window'] = max( 2, min( 50, $sanitized['langchain_memory_window'] ) );

		// Validate langchain_max_retries range (0–10).
		$sanitized['langchain_max_retries'] = max( 0, min( 10, $sanitized['langchain_max_retries'] ) );

		// Update legacy options for backward compatibility.
		update_option( 'wp_mcp_ai_enable_webllm_tools', $sanitized['enable_tool_calling'] );
		update_option( 'wp_mcp_ai_enable_webllm_vision', $sanitized['enable_multimodal'] );
		update_option( 'wp_mcp_ai_enable_langchain_orchestration', $sanitized['enable_langchain'] );
		update_option( 'wp_mcp_ai_enable_web_workers', $sanitized['enable_web_workers'] );

		return $sanitized;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Phase 1: Advanced WebLLM Integration', 'mcp-ai-wpoos' ); ?></strong><br>
					<?php esc_html_e( 'These features enhance the embedded provider with tool calling and vision support. All AI processing happens in the user\'s browser for privacy and performance.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_NAME );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			
			<div class="webllm-info-card" style="margin-top: 30px; padding: 20px; background: #fff; border-left: 4px solid #2271b1;">
				<h2><?php esc_html_e( 'Documentation & Resources', 'mcp-ai-wpoos' ); ?></h2>
				<ul>
					<li>
						<strong><?php esc_html_e( 'Tool Calling Guide:', 'mcp-ai-wpoos' ); ?></strong>
						<code>docs/features/ai-providers/embedded/TOOL_CALLING_GUIDE.md</code>
					</li>
					<li>
						<strong><?php esc_html_e( 'Implementation Plan:', 'mcp-ai-wpoos' ); ?></strong>
						<code>docs/proposals/WEB-LLM-IMPLEMENTATION-PHASE-1.md</code>
					</li>
					<li>
						<strong><?php esc_html_e( 'Bundle Impact:', 'mcp-ai-wpoos' ); ?></strong>
						~10.7KB minified (~5KB gzipped) - Zero npm packages added
					</li>
				</ul>
				
				<h3><?php esc_html_e( 'Feature Status', 'mcp-ai-wpoos' ); ?></h3>
				<table class="widefat striped" style="margin-top: 10px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Feature', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Bundle Size', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Tool Calling', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></td>
							<td>6.8KB minified</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Multi-Modal (Vision)', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></td>
							<td>3.9KB minified</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'LangChain Orchestration (Phase 3)', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?></td>
							<td>9.7KB minified</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'System Prompt Diagnostics', 'mcp-ai-wpoos' ); ?></td>
							<td><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e( 'Integrated', 'mcp-ai-wpoos' ); ?></td>
							<td>0KB (built-in)</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render features section description
	 */
	public function render_features_section() {
		echo '<p>' . esc_html__( 'Enable experimental WebLLM features. These features are loaded conditionally to minimize bundle size.', 'mcp-ai-wpoos' ) . '</p>';
	}

	/**
	 * Render tool calling field
	 */
	public function render_tool_calling_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['enable_tool_calling'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_tool_calling]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable browser-side tool calling (Experimental)', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Allows embedded LLM to execute WordPress tools directly from the browser. Tool execution happens server-side via REST API with proper permission checks.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Bundle Impact:', 'mcp-ai-wpoos' ); ?></strong> +6.8KB minified (tool adapter + function calling client)
		</p>
		<?php
	}

	/**
	 * Render multimodal field
	 */
	public function render_multimodal_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['enable_multimodal'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_multimodal]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable vision models for image analysis (Experimental)', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Enables browser-based image analysis using LLaVA and Qwen2-VL models. Models download on-demand (1.5GB-4.5GB) and are cached in browser.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Supported Models:', 'mcp-ai-wpoos' ); ?></strong> LLaVA-1.5-7B, Qwen2-VL-2B, Qwen2-VL-7B
			<br>
			<strong><?php esc_html_e( 'Bundle Impact:', 'mcp-ai-wpoos' ); ?></strong> +3.9KB minified (multimodal client)
			<br>
			<em><?php esc_html_e( 'Note: Requires tool calling to be enabled', 'mcp-ai-wpoos' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Render LangChain orchestration field
	 */
	public function render_langchain_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['enable_langchain'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_langchain]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable LangChain.js orchestration (Phase 3 - Experimental)', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Enables sophisticated multi-step reasoning, chains, agents, and memory management using LangChain.js in the browser.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Features:', 'mcp-ai-wpoos' ); ?></strong> Sequential chains, agent-based workflows, conversation memory, self-reflection
			<br>
			<strong><?php esc_html_e( 'Bundle Impact:', 'mcp-ai-wpoos' ); ?></strong> +9.7KB minified (orchestration client + tool adapter), +~800KB lazy-loaded from CDN
			<br>
			<em><?php esc_html_e( 'Note: Requires tool calling to be enabled. LangChain libraries are loaded from CDN on-demand.', 'mcp-ai-wpoos' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Render Web Workers field
	 */
	public function render_web_workers_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['enable_web_workers'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_web_workers]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable Web Workers for non-blocking UI (Phase 4 - Experimental)', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Offloads AI computation to Web Workers, preventing UI blocking during model loading and inference. Ensures smooth 60fps performance.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Features:', 'mcp-ai-wpoos' ); ?></strong> Non-blocking UI, background model loading, smooth animations, better mobile performance
			<br>
			<strong><?php esc_html_e( 'Bundle Impact:', 'mcp-ai-wpoos' ); ?></strong> +8.3KB minified (worker manager + worker script)
			<br>
			<strong><?php esc_html_e( 'Browser Support:', 'mcp-ai-wpoos' ); ?></strong> Chrome 4+, Firefox 3.5+, Safari 4+, Edge (all versions)
			<br>
			<em><?php esc_html_e( 'Note: Automatically falls back to main thread if Web Workers are not supported.', 'mcp-ai-wpoos' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Render LangChain streaming toggle field
	 */
	public function render_langchain_streaming_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['langchain_enable_streaming'] );
		?>
		<label>
			<input type="checkbox"
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[langchain_enable_streaming]"
					value="1"
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable token streaming for LangChain responses', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Streams LLM output token-by-token as it is generated, giving users faster perceived response time. Requires LangChain Orchestration to be enabled.', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Render LangChain memory window field
	 */
	public function render_langchain_memory_window_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$value    = absint( $settings['langchain_memory_window'] ?? 10 );
		?>
		<input type="number"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[langchain_memory_window]"
				value="<?php echo esc_attr( $value ); ?>"
				min="2"
				max="50"
				class="small-text">
		<p class="description">
			<?php esc_html_e( 'Number of conversation turn-pairs (user + assistant) to retain in memory. Higher values improve context but increase token usage.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Range:', 'mcp-ai-wpoos' ); ?></strong> 2–50 &nbsp;
			<strong><?php esc_html_e( 'Default:', 'mcp-ai-wpoos' ); ?></strong> 10
		</p>
		<?php
	}

	/**
	 * Render LangChain max retries field
	 */
	public function render_langchain_max_retries_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$value    = absint( $settings['langchain_max_retries'] ?? 3 );
		?>
		<input type="number"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[langchain_max_retries]"
				value="<?php echo esc_attr( $value ); ?>"
				min="0"
				max="10"
				class="small-text">
		<p class="description">
			<?php esc_html_e( 'Maximum automatic retries on failed LLM or tool calls (exponential back-off). Set to 0 to disable retries.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Range:', 'mcp-ai-wpoos' ); ?></strong> 0–10 &nbsp;
			<strong><?php esc_html_e( 'Default:', 'mcp-ai-wpoos' ); ?></strong> 3
		</p>
		<?php
	}

	/**
	 * Render performance section description
	 */
	public function render_performance_section() {
		echo '<p>' . esc_html__( 'Configure performance and optimization settings for WebLLM features.', 'mcp-ai-wpoos' ) . '</p>';
	}

	/**
	 * Render cache models field
	 */
	public function render_cache_models_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['cache_models'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cache_models]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Cache downloaded models in browser storage', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Models are cached using IndexedDB for faster subsequent loads. Disable if experiencing storage issues.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Recommended:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Enabled (default)', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Render max tools field
	 */
	public function render_max_tools_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$value    = absint( $settings['max_tools'] ?? 20 );
		?>
		<input type="number" 
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[max_tools]" 
				value="<?php echo esc_attr( $value ); ?>" 
				min="1" 
				max="50" 
				class="small-text">
		<p class="description">
			<?php esc_html_e( 'Maximum number of tools to pass to the model in a single request. Lower values improve performance but limit tool availability.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Range:', 'mcp-ai-wpoos' ); ?></strong> 1-50 tools
			<br>
			<strong><?php esc_html_e( 'Recommended:', 'mcp-ai-wpoos' ); ?></strong> 20 tools
		</p>
		<?php
	}

	/**
	 * Render debug section description
	 */
	public function render_debug_section() {
		echo '<p>' . esc_html__( 'Debug and diagnostic options for troubleshooting WebLLM features.', 'mcp-ai-wpoos' ) . '</p>';
	}

	/**
	 * Render console logs field
	 */
	public function render_console_logs_field() {
		$settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
		$checked  = ! empty( $settings['enable_console_logs'] );
		?>
		<label>
			<input type="checkbox" 
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_console_logs]" 
					value="1" 
					<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable verbose console logging', 'mcp-ai-wpoos' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Outputs detailed logs to browser console for debugging. Includes system prompt detection, tool calls, and streaming events.', 'mcp-ai-wpoos' ); ?>
			<br>
			<strong><?php esc_html_e( 'Log Examples:', 'mcp-ai-wpoos' ); ?></strong>
			<br>
			<code>[NV oOS WebLLM] System prompt detected...</code>
			<br>
			<code>[NV oOS WebLLM] Tool calling enabled...</code>
			<br>
			<em><?php esc_html_e( 'Note: Some logging is always enabled for PR #3197 diagnostics', 'mcp-ai-wpoos' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		// Add custom CSS for settings page.
		wp_add_inline_style(
			'wp-admin',
			'
			.webllm-info-card h2 { margin-top: 0; }
			.webllm-info-card ul { margin-left: 20px; }
			.webllm-info-card code { 
				background: #f0f0f1; 
				padding: 2px 6px; 
				border-radius: 3px; 
				font-size: 13px;
			}
		'
		);
	}
}

// Initialize settings page.
new WP_MCP_AI_WebLLM_Settings_Page();
