<?php
/**
 * Multi-Agent Dashboard Admin Page
 *
 * Provides a dedicated interface for viewing and managing the 6 default
 * multi-agent orchestration system assistants.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-Agent Dashboard Admin Page.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Admin_Multi_Agent_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_multi_agent_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wp_mcp_ai_reinstall_agents', array( $this, 'ajax_reinstall_agents' ) );
	}

	/**
	 * Add admin menu page.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Multi-Agent System', 'mcp-ai-wpoos' ),
			__( 'Multi-Agent System', 'mcp-ai-wpoos' ),
			'manage_options',
			'mcp-ai-multi-agent',
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Check if this is our multi-agent dashboard page.
		$is_multi_agent_page = false !== strpos( $hook, 'mcp-ai-multi-agent' );

		if ( ! $is_multi_agent_page ) {
			return;
		}

		// Enqueue chat assets for test modal.
		$this->enqueue_chat_assets();

		// Use file modification time for cache busting.
		$css_path    = WP_MCP_AI_PATH . 'assets/css/admin-multi-agent-dashboard.css';
		$js_path     = WP_MCP_AI_PATH . 'assets/js/admin-multi-agent-dashboard.js';
		$css_version = file_exists( $css_path ) ? filemtime( $css_path ) : WP_MCP_AI_VERSION;
		$js_version  = file_exists( $js_path ) ? filemtime( $js_path ) : WP_MCP_AI_VERSION;

		// Enqueue test assistant CSS for modal styling.
		$test_css_path    = WP_MCP_AI_PATH . 'assets/css/admin-test-assistant.css';
		$test_css_version = file_exists( $test_css_path ) ? filemtime( $test_css_path ) : WP_MCP_AI_VERSION;

		// Enqueue shared monitor CSS for consistent styling.
		$shared_css_path    = WP_MCP_AI_PATH . 'assets/css/admin-monitor-shared.css';
		$shared_css_version = file_exists( $shared_css_path ) ? filemtime( $shared_css_path ) : WP_MCP_AI_VERSION;

		wp_enqueue_style(
			'wp-mcp-ai-admin-monitor-shared',
			WP_MCP_AI_URL . 'assets/css/admin-monitor-shared.css',
			array(),
			$shared_css_version
		);

		wp_enqueue_style(
			'wp-mcp-ai-admin-test-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-test-assistant.css',
			array( 'wp-mcp-ai-chat' ),
			$test_css_version
		);

		wp_enqueue_style(
			'wp-mcp-ai-multi-agent-dashboard',
			WP_MCP_AI_URL . 'assets/css/admin-multi-agent-dashboard.css',
			array( 'wp-mcp-ai-admin-monitor-shared' ),
			$css_version
		);

		// Enqueue test assistant JS for modal functionality.
		$test_js_path    = WP_MCP_AI_PATH . 'assets/js/admin-test-assistant.js';
		$test_js_version = file_exists( $test_js_path ) ? filemtime( $test_js_path ) : WP_MCP_AI_VERSION;

		wp_enqueue_script(
			'wp-mcp-ai-admin-test-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-test-assistant.js',
			array( 'wp-mcp-ai-chat' ),
			$test_js_version,
			true
		);

		wp_enqueue_script(
			'wp-mcp-ai-multi-agent-dashboard',
			WP_MCP_AI_URL . 'assets/js/admin-multi-agent-dashboard.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-multi-agent-dashboard',
			'wpMcpAiMultiAgent',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_multi_agent' ),
				'strings' => array(
					'confirmReinstall' => __( 'Are you sure you want to reinstall all default assistants? This will update their configurations.', 'mcp-ai-wpoos' ),
					'reinstalling'     => __( 'Reinstalling...', 'mcp-ai-wpoos' ),
					'reinstallSuccess' => __( 'Default assistants reinstalled successfully!', 'mcp-ai-wpoos' ),
					'reinstallError'   => __( 'Failed to reinstall assistants.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Enqueue chat interface assets.
	 * Reused from test page pattern.
	 *
	 * @return void
	 */
	protected function enqueue_chat_assets() {
		// Use bundled JavaScript file.
		$script_relative            = 'assets/js/chat-bundle.min.js';
		$style_relative             = 'assets/css/chat.css';
		$cron_status_style_relative = 'assets/css/cron-status.css';

		$script_path            = WP_MCP_AI_URL . $script_relative;
		$style_path             = WP_MCP_AI_URL . $style_relative;
		$cron_status_style_path = WP_MCP_AI_URL . $cron_status_style_relative;

		$script_version            = $this->get_asset_version( $script_relative );
		$style_version             = $this->get_asset_version( $style_relative );
		$cron_status_style_version = $this->get_asset_version( $cron_status_style_relative );

		wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			$cron_status_style_path,
			array(),
			$cron_status_style_version
		);

		wp_enqueue_style(
			'wp-mcp-ai-chat',
			$style_path,
			array( 'wp-mcp-ai-cron-status' ),
			$style_version
		);

		wp_enqueue_script(
			'wp-mcp-ai-chat',
			$script_path,
			array(),
			$script_version,
			true
		);

		// Safety check: Ensure REST constants exist.
		$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

		// Use the shortcode helper method for consistent async tool timeout calculation.
		$async_timeout_ms = class_exists( 'WP_MCP_AI_Shortcode' )
			? WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

		// Get plugin settings for cost display and capability flags configuration.
		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$show_usage_costs = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;

		// Allow filtering of cost display setting.
		$show_usage_costs = apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, get_current_user_id() );

		// Get capability flags display setting.
		$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;

		// Allow filtering of capability flags display setting.
		$show_capability_flags = apply_filters( 'wp_mcp_ai_show_capability_flags', $show_capability_flags, get_current_user_id() );

		wp_localize_script(
			'wp-mcp-ai-chat',
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( rest_url( $rest_namespace ) ) ),
				'uploadEndpoint'      => esc_url_raw( rest_url( 'wp/v2/media' ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( rest_url( $rest_namespace . '/files' ) ) ),
				'toolsEndpoint'       => esc_url_raw( rest_url( $rest_namespace . '/tools' ) ),
				'transcriptsEndpoint' => esc_url_raw( rest_url( $rest_namespace . '/chat-transcripts' ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'showCapabilityFlags' => $show_capability_flags,
				'asyncToolTimeout'    => $async_timeout_ms,
				'strings'             => $this->get_chat_strings(),
			)
		);
	}

	/**
	 * Get chat interface strings for localization.
	 *
	 * @return array
	 */
	protected function get_chat_strings() {
		return array(
			'placeholder'     => __( 'Ask something…', 'mcp-ai-wpoos' ),
			'send'            => __( 'Send', 'mcp-ai-wpoos' ),
			'attachFile'      => __( 'Attach file', 'mcp-ai-wpoos' ),
			'transcribeAudio' => __( 'Transcribe audio', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get asset version based on file modification time.
	 *
	 * @param string $relative_path Asset path relative to plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$absolute_path = WP_MCP_AI_PATH . $relative_path;

		if ( file_exists( $absolute_path ) ) {
			$modified = filemtime( $absolute_path );

			if ( $modified ) {
				return WP_MCP_AI_VERSION . '.' . $modified;
			}
		}

		return WP_MCP_AI_VERSION;
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		// Get statistics.
		$stats = $this->get_agent_statistics();

		?>
		<div class="wrap wp-mcp-ai-multi-agent-dashboard">
			<h1>
				<?php esc_html_e( 'Multi-Agent Orchestration System', 'mcp-ai-wpoos' ); ?>
			</h1>

			<p class="description">
				<?php
				printf(
					/* translators: %d: Number of agents */
					esc_html__( 'Manage your intelligent content and data orchestration grid with %d specialized AI assistants using various workflow patterns for optimal task execution.', 'mcp-ai-wpoos' ),
					esc_html( $stats['total_agents'] )
				);
				?>
			</p>

			<!-- Auto-Refresh Controls -->
			<div class="auto-refresh-controls">
				<label>
					<input type="checkbox" id="toggle-auto-refresh" />
					<?php esc_html_e( 'Auto-refresh', 'mcp-ai-wpoos' ); ?>
				</label>
				<button type="button" class="button button-secondary" id="manual-refresh-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="last-refresh-time">
					<?php esc_html_e( 'Last updated:', 'mcp-ai-wpoos' ); ?>
					<strong id="last-refresh-time"><?php echo esc_html( current_time( 'H:i:s' ) ); ?></strong>
				</span>
			</div>

			<!-- System Status Banner -->
			<div class="multi-agent-status-banner">
				<?php $this->render_status_banner( $stats ); ?>
			</div>

			<!-- Quick Stats -->
			<div class="multi-agent-stats-grid">
				<?php $this->render_quick_stats( $stats ); ?>
			</div>

			<!-- Agent Grid -->
			<div class="multi-agent-grid-container">
				<div class="section-header">
					<h2><?php esc_html_e( 'Agent System Overview', 'mcp-ai-wpoos' ); ?></h2>
					<div class="section-actions">
						<button type="button" class="button" id="reinstall-agents-btn">
							<span class="dashicons dashicons-update-alt"></span>
							<?php esc_html_e( 'Reinstall Agents', 'mcp-ai-wpoos' ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button">
							<span class="dashicons dashicons-list-view"></span>
							<?php esc_html_e( 'View All Assistants', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>
				</div>
				<?php $this->render_agent_grid( $stats ); ?>
			</div>

			<!-- Workflow Diagrams -->
			<div class="workflow-diagram-container">
				<?php $this->render_workflow_diagrams( $stats ); ?>
			</div>

			<!-- Documentation -->
			<div class="multi-agent-documentation">
				<h2><?php esc_html_e( 'Documentation & Resources', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_documentation(); ?>
			</div>

			<!-- Modal container for chat interface -->
			<div id="wp-mcp-ai-test-modal" class="wp-mcp-ai-test-modal" style="display: none;">
				<div class="wp-mcp-ai-test-modal__backdrop"></div>
				<div class="wp-mcp-ai-test-modal__panel">
					<div class="wp-mcp-ai-test-modal__header">
						<h2 id="wp-mcp-ai-test-modal__title"><?php echo esc_html__( 'Test Assistant', 'mcp-ai-wpoos' ); ?></h2>
						<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'mcp-ai-wpoos' ); ?>">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="wp-mcp-ai-test-modal__body">
						<!-- Chat interface will be initialized here -->
						<div id="wp-mcp-ai-test-chat-container"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get agent statistics.
	 *
	 * @return array Statistics data.
	 */
	protected function get_agent_statistics() {
		$stats = array(
			'installed'     => WP_MCP_AI_Default_Assistants::is_installed(),
			'installation'  => WP_MCP_AI_Default_Assistants::get_installation_info(),
			'agents'        => array(),
			'total_agents'  => 0,
			'active_agents' => 0,
			'total_tools'   => 0,
			'is_pro_active' => defined( 'WP_MCP_AI_PRO_VERSION' ),
			'patterns'      => array(),
		);

		if ( ! $stats['installed'] ) {
			return $stats;
		}

		// Get all default assistants.
		$default_configs       = WP_MCP_AI_Default_Assistants::get_default_assistants();
		
		// Also get Architect Agent config if method exists.
		if ( method_exists( 'WP_MCP_AI_Default_Assistants', 'get_architect_agent_assistant_config' ) ) {
			$architect_config    = WP_MCP_AI_Default_Assistants::get_architect_agent_assistant_config();
			$default_configs[]   = $architect_config;
		}
		
		$stats['total_agents'] = count( $default_configs );

		// Build slugs to configs map.
		$config_map = array();
		foreach ( $default_configs as $config ) {
			$config_map[ $config['slug'] ] = $config;
		}

		// Get actual assistant posts.
		$assistant_ids = isset( $stats['installation']['assistant_ids'] ) ? $stats['installation']['assistant_ids'] : array();
		
		// Also check for Architect Agent separately since it may be created independently.
		$architect_agent = get_page_by_path( 'architect-agent', OBJECT, 'mcp_ai_assistant' );
		if ( $architect_agent && ! in_array( $architect_agent->ID, $assistant_ids, true ) ) {
			$assistant_ids[] = $architect_agent->ID;
		}

		foreach ( $assistant_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
				continue;
			}

			// Find matching config by slug.
			$config = isset( $config_map[ $post->post_name ] ) ? $config_map[ $post->post_name ] : null;

			// Get metadata.
			$provider      = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true );
			$model         = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_MODEL, true );
			$temperature   = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, true );
			$tools         = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
			$primary_roles = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, true );

			// Get usage stats from chat transcripts (if available).
			$last_used = $this->get_last_used_time( $post_id );

			// Determine workflow pattern based on slug and roles.
			$workflow_pattern = $this->detect_workflow_pattern( $post->post_name, $primary_roles );

			$agent_data = array(
				'id'               => $post_id,
				'title'            => $post->post_title,
				'slug'             => $post->post_name,
				'status'           => $post->post_status,
				'description'      => $post->post_content,
				'provider'         => $provider,
				'model'            => $model,
				'temperature'      => $temperature,
				'tools'            => is_array( $tools ) ? $tools : array(),
				'tool_count'       => is_array( $tools ) ? count( $tools ) : 0,
				'primary_roles'    => is_array( $primary_roles ) ? $primary_roles : array(),
				'last_used'        => $last_used,
				'config'           => $config,
				'workflow_pattern' => $workflow_pattern,
			);

			$stats['agents'][] = $agent_data;

			// Group by pattern.
			if ( ! isset( $stats['patterns'][ $workflow_pattern ] ) ) {
				$stats['patterns'][ $workflow_pattern ] = array();
			}
			$stats['patterns'][ $workflow_pattern ][] = $agent_data;

			if ( 'publish' === $post->post_status ) {
				++$stats['active_agents'];
			}

			$stats['total_tools'] += $agent_data['tool_count'];
		}

		return $stats;
	}

	/**
	 * Detect workflow pattern based on agent slug and roles.
	 *
	 * @param string $slug         Agent slug.
	 * @param array  $primary_roles Agent primary roles.
	 * @return string Workflow pattern (sequential, loop, router, hierarchical).
	 */
	protected function detect_workflow_pattern( $slug, $primary_roles ) {
		// Architect Agent uses Loop/Reflection pattern.
		if ( 'architect-agent' === $slug || in_array( 'architect', $primary_roles, true ) ) {
			return 'loop';
		}

		// Default sequential workflow agents.
		$sequential_slugs = array(
			'orchestrator-supervisor',
			'research-operative',
			'unstructured-parser',
			'content-drafter',
			'seo-compliance-auditor',
			'publisher-terminal',
		);

		if ( in_array( $slug, $sequential_slugs, true ) ) {
			return 'sequential';
		}

		// Default to sequential for unknown agents.
		return 'sequential';
	}

	/**
	 * Get last used time for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string|null Last used time or null.
	 */
	protected function get_last_used_time( $assistant_id ) {
		// Check if JetEngine CCT is available for chat transcripts.
		if ( ! wp_mcp_ai_is_jetengine_available() ) {
			return null;
		}

		// Query most recent chat transcript for this assistant.
		global $wpdb;
		$table_name = $wpdb->prefix . 'jet_cct_mcp_ai_chat_transcripts';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return null;
		}

		// Get most recent transcript.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last_transcript = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cct_created FROM {$table_name} WHERE assistant_id = %d ORDER BY cct_created DESC LIMIT 1",
				$assistant_id
			)
		);

		return $last_transcript ? $last_transcript->cct_created : null;
	}

	/**
	 * Render status banner.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_status_banner( $stats ) {
		if ( ! $stats['installed'] ) {
			?>
			<div class="status-banner status-warning">
				<span class="dashicons dashicons-warning"></span>
				<div class="status-content">
					<strong><?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos' ); ?></strong>
					<p><?php esc_html_e( 'The multi-agent system has not been installed. Please activate the plugin to install default agents.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		$status_class = $stats['active_agents'] === $stats['total_agents'] ? 'status-success' : 'status-warning';
		?>
		<div class="status-banner <?php echo esc_attr( $status_class ); ?>">
			<span class="dashicons dashicons-yes-alt"></span>
			<div class="status-content">
				<strong><?php esc_html_e( 'System Operational', 'mcp-ai-wpoos' ); ?></strong>
				<p>
					<?php
					printf(
						/* translators: 1: Active agents count, 2: Total agents count */
						esc_html__( '%1$d of %2$d agents active and ready for orchestration.', 'mcp-ai-wpoos' ),
						esc_html( $stats['active_agents'] ),
						esc_html( $stats['total_agents'] )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render quick statistics.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_quick_stats( $stats ) {
		?>
		<div class="stat-card">
			<div class="stat-icon">🤖</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $stats['total_agents'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total Agents', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">✅</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $stats['active_agents'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Active Agents', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">🔧</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $stats['total_tools'] ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Total Tools', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">⚡</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo esc_html( $stats['is_pro_active'] ? 'Pro' : 'Base' ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Version', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render agent grid.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_agent_grid( $stats ) {
		if ( empty( $stats['agents'] ) ) {
			?>
			<div class="no-agents-message">
				<p><?php esc_html_e( 'No agents installed. Please reinstall the default agents.', 'mcp-ai-wpoos' ); ?></p>
			</div>
			<?php
			return;
		}

		// Render agents grouped by workflow pattern.
		$pattern_labels = array(
			'sequential' => __( 'Sequential Workflow Agents', 'mcp-ai-wpoos' ),
			'loop'       => __( 'Loop/Reflection Pattern Agents', 'mcp-ai-wpoos' ),
			'router'     => __( 'Router Pattern Agents', 'mcp-ai-wpoos' ),
			'hierarchical' => __( 'Hierarchical Pattern Agents', 'mcp-ai-wpoos' ),
		);

		$pattern_descriptions = array(
			'sequential'   => __( 'Agents arranged in a sequential pipeline, passing output from one to the next in a defined order.', 'mcp-ai-wpoos' ),
			'loop'         => __( 'Agents that iteratively revisit and refine their outputs through feedback loops and self-reflection.', 'mcp-ai-wpoos' ),
			'router'       => __( 'Agents that dynamically route tasks to specialized agents based on context and requirements.', 'mcp-ai-wpoos' ),
			'hierarchical' => __( 'Agents arranged in supervisor-subordinate relationships for task decomposition and delegation.', 'mcp-ai-wpoos' ),
		);

		foreach ( $stats['patterns'] as $pattern => $pattern_agents ) {
			if ( empty( $pattern_agents ) ) {
				continue;
			}

			$pattern_label = isset( $pattern_labels[ $pattern ] ) ? $pattern_labels[ $pattern ] : ucfirst( $pattern ) . ' ' . __( 'Agents', 'mcp-ai-wpoos' );
			$pattern_desc  = isset( $pattern_descriptions[ $pattern ] ) ? $pattern_descriptions[ $pattern ] : '';
			?>
			<div class="agent-pattern-group" data-pattern="<?php echo esc_attr( $pattern ); ?>">
				<div class="pattern-header">
					<h3 class="pattern-title">
						<?php echo esc_html( $pattern_label ); ?>
						<span class="pattern-count"><?php echo esc_html( count( $pattern_agents ) ); ?></span>
					</h3>
					<?php if ( $pattern_desc ) : ?>
						<p class="pattern-description"><?php echo esc_html( $pattern_desc ); ?></p>
					<?php endif; ?>
				</div>

				<div class="agent-grid">
					<?php foreach ( $pattern_agents as $agent ) : ?>
						<div class="agent-card" data-agent-id="<?php echo esc_attr( $agent['id'] ); ?>" data-pattern="<?php echo esc_attr( $pattern ); ?>">
							<div class="agent-header">
								<h3 class="agent-title"><?php echo esc_html( $agent['title'] ); ?></h3>
								<span class="agent-status <?php echo esc_attr( 'publish' === $agent['status'] ? 'status-active' : 'status-inactive' ); ?>">
									<?php echo esc_html( 'publish' === $agent['status'] ? __( 'Active', 'mcp-ai-wpoos' ) : __( 'Inactive', 'mcp-ai-wpoos' ) ); ?>
								</span>
							</div>

							<div class="agent-roles">
								<?php if ( ! empty( $agent['primary_roles'] ) ) : ?>
									<?php foreach ( $agent['primary_roles'] as $role ) : ?>
										<span class="role-badge"><?php echo esc_html( $role ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<p class="agent-description"><?php echo esc_html( wp_trim_words( $agent['description'], 20 ) ); ?></p>

							<div class="agent-meta">
								<div class="meta-row">
									<span class="meta-label"><?php esc_html_e( 'Model:', 'mcp-ai-wpoos' ); ?></span>
									<span class="meta-value"><?php echo esc_html( $agent['model'] ); ?></span>
								</div>
								<div class="meta-row">
									<span class="meta-label"><?php esc_html_e( 'Temperature:', 'mcp-ai-wpoos' ); ?></span>
									<span class="meta-value"><?php echo esc_html( $agent['temperature'] ); ?></span>
								</div>
								<div class="meta-row">
									<span class="meta-label"><?php esc_html_e( 'Tools:', 'mcp-ai-wpoos' ); ?></span>
									<span class="meta-value"><?php echo esc_html( $agent['tool_count'] ); ?></span>
								</div>
								<?php if ( $agent['last_used'] ) : ?>
									<div class="meta-row">
										<span class="meta-label"><?php esc_html_e( 'Last Used:', 'mcp-ai-wpoos' ); ?></span>
										<span class="meta-value"><?php echo esc_html( human_time_diff( strtotime( $agent['last_used'] ), current_time( 'timestamp' ) ) . ' ago' ); ?></span>
									</div>
								<?php endif; ?>
							</div>

							<div class="agent-actions">
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $agent['id'] . '&action=edit' ) ); ?>" class="button button-small">
									<span class="dashicons dashicons-edit"></span>
									<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
								</a>
								<button
									type="button"
									class="button button-small wp-mcp-ai-test-assistant-btn"
									data-assistant-id="<?php echo esc_attr( $agent['id'] ); ?>"
									data-assistant-title="<?php echo esc_attr( $agent['title'] ); ?>"
									data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $this->get_assistant_tool_shortcuts( $agent['id'] ) ) ); ?>"
									data-provider="<?php echo esc_attr( $agent['provider'] ); ?>"
									data-model="<?php echo esc_attr( $agent['model'] ); ?>"
								>
									<span class="dashicons dashicons-admin-tools"></span>
									<?php esc_html_e( 'Test', 'mcp-ai-wpoos' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Render workflow diagrams based on patterns present.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_workflow_diagrams( $stats ) {
		if ( empty( $stats['patterns'] ) ) {
			return;
		}

		// Render Sequential Workflow if present.
		if ( ! empty( $stats['patterns']['sequential'] ) ) {
			?>
			<div class="workflow-section">
				<h2><?php esc_html_e( 'Sequential Workflow Pattern', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_sequential_workflow_diagram(); ?>
			</div>
			<?php
		}

		// Render Loop/Reflection Workflow if present.
		if ( ! empty( $stats['patterns']['loop'] ) ) {
			?>
			<div class="workflow-section">
				<h2><?php esc_html_e( 'Loop/Reflection Pattern (OODA Cycle)', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_loop_reflection_workflow_diagram(); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render sequential workflow diagram.
	 *
	 * @return void
	 */
	protected function render_sequential_workflow_diagram() {
		?>
		<div class="workflow-diagram">
			<div class="workflow-step">
				<div class="step-icon">👤</div>
				<div class="step-label"><?php esc_html_e( 'User Request', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step workflow-supervisor">
				<div class="step-icon">🎯</div>
				<div class="step-label"><?php esc_html_e( 'Orchestrator', 'mcp-ai-wpoos' ); ?></div>
				<div class="step-description"><?php esc_html_e( 'Routes & Coordinates', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">↓</div>
			<div class="workflow-step">
				<div class="step-icon">🔍</div>
				<div class="step-label"><?php esc_html_e( 'Research', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">📊</div>
				<div class="step-label"><?php esc_html_e( 'Parser', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✍️</div>
				<div class="step-label"><?php esc_html_e( 'Drafter', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✅</div>
				<div class="step-label"><?php esc_html_e( 'Auditor', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">🚀</div>
				<div class="step-label"><?php esc_html_e( 'Publisher', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>
		<p class="workflow-note">
			<?php esc_html_e( 'Sequential workflow with Orchestrator managing delegation and coordination between specialized agents.', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Render loop/reflection workflow diagram (OODA cycle).
	 *
	 * @return void
	 */
	protected function render_loop_reflection_workflow_diagram() {
		?>
		<div class="workflow-diagram workflow-loop">
			<div class="workflow-step">
				<div class="step-icon">👤</div>
				<div class="step-label"><?php esc_html_e( 'Task Request', 'mcp-ai-wpoos' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-loop-cycle">
				<div class="cycle-label"><?php esc_html_e( 'OODA Loop (Iterative)', 'mcp-ai-wpoos' ); ?></div>
				<div class="cycle-steps">
					<div class="workflow-step workflow-observe">
						<div class="step-icon">👁️</div>
						<div class="step-label"><?php esc_html_e( 'OBSERVE', 'mcp-ai-wpoos' ); ?></div>
						<div class="step-description"><?php esc_html_e( 'Gather Data', 'mcp-ai-wpoos' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-orient">
						<div class="step-icon">🧭</div>
						<div class="step-label"><?php esc_html_e( 'ORIENT', 'mcp-ai-wpoos' ); ?></div>
						<div class="step-description"><?php esc_html_e( 'Analyze Context', 'mcp-ai-wpoos' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-decide">
						<div class="step-icon">🤔</div>
						<div class="step-label"><?php esc_html_e( 'DECIDE', 'mcp-ai-wpoos' ); ?></div>
						<div class="step-description"><?php esc_html_e( 'Choose Action', 'mcp-ai-wpoos' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-act">
						<div class="step-icon">⚡</div>
						<div class="step-label"><?php esc_html_e( 'ACT', 'mcp-ai-wpoos' ); ?></div>
						<div class="step-description"><?php esc_html_e( 'Execute & Validate', 'mcp-ai-wpoos' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow-back">↻</div>
					<div class="cycle-back-note"><?php esc_html_e( 'Iterate until goal achieved', 'mcp-ai-wpoos' ); ?></div>
				</div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✅</div>
				<div class="step-label"><?php esc_html_e( 'Task Complete', 'mcp-ai-wpoos' ); ?></div>
			</div>
		</div>
		<p class="workflow-note">
			<?php esc_html_e( 'Loop/Reflection pattern using OODA cycle (Observe-Orient-Decide-Act) for iterative refinement and self-correction. Rapid cycling enables adaptive, context-aware development.', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Render workflow diagram.
	 *
	 * @deprecated Use render_sequential_workflow_diagram() instead.
	 * @return void
	 */
	protected function render_workflow_diagram() {
		$this->render_sequential_workflow_diagram();
	}

	/**
	 * Render documentation section.
	 *
	 * @return void
	 */
	protected function render_documentation() {
		?>
		<div class="documentation-grid">
			<div class="doc-card">
				<h3><span class="dashicons dashicons-book"></span> <?php esc_html_e( 'Implementation Guide', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Learn about the multi-agent architecture, industry best practices, and workflow patterns.', 'mcp-ai-wpoos' ); ?></p>
				<a href="<?php echo esc_url( WP_MCP_AI_URL . 'docs/MULTI_AGENT_ORCHESTRATION_IMPLEMENTATION.md' ); ?>" class="button" target="_blank">
					<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<div class="doc-card">
				<h3><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Tool Reference', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Explore the 141+ base tools and 52 Pro tools available to your agents.', 'mcp-ai-wpoos' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button">
					<?php esc_html_e( 'Browse Tools', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<div class="doc-card">
				<h3><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'All Assistants', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'View and manage all assistants including custom ones beyond the default 6.', 'mcp-ai-wpoos' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button">
					<?php esc_html_e( 'Manage Assistants', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to get multi-agent statistics.
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'wp_mcp_ai_multi_agent', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$stats = $this->get_agent_statistics();
		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler to reinstall default agents.
	 *
	 * @return void
	 */
	public function ajax_reinstall_agents() {
		check_ajax_referer( 'wp_mcp_ai_multi_agent', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$result = WP_MCP_AI_Default_Assistants::reinstall();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Default agents reinstalled successfully.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * Get assistant tool shortcuts.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of tool shortcuts.
	 */
	protected function get_assistant_tool_shortcuts( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		// Safety check: Ensure class exists.
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return array();
		}

		// Use the shortcode class method if it exists.
		if ( method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
			return WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );
		}

		return array();
	}
}

// Initialize the dashboard.
new WP_MCP_AI_Admin_Multi_Agent_Dashboard();
