<?php
/**
 * Multi-Agent dashboard (Wave E-UI-1, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Multi_Agent_Dashboard`
 * (`includes/admin/class-wp-mcp-ai-admin-multi-agent-dashboard.php`):
 * byte-identical dashboard surface — the `mcp-ai-multi-agent` page
 * slug, the `wp_mcp_ai_get_multi_agent_stats` /
 * `wp_mcp_ai_reinstall_agents` AJAX actions with the
 * `wp_mcp_ai_multi_agent` nonce, the `wpMcpAiMultiAgent` localized
 * config envelope, the statistics shape (installed/installation/
 * agents/total_agents/active_agents/total_tools/is_pro_active/
 * patterns), the workflow-pattern classification (architect → loop;
 * the six sequential slugs), the status banner / quick stats / agent
 * grid (pattern-grouped with role badges, meta rows, edit + test
 * actions) / workflow diagrams / documentation sections, and the
 * JetEngine-gated last-used lookup.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform`).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerAdmin()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith (double registration would duplicate the
 *    page). Standalone the page registers under the platform's
 *    "NV Platform" menu (`ai-platform-dashboard`) — the ported
 *    dashboards form the submenu-level navigation layer of the
 *    platform admin (the E-UI-1 review outcome: the base operational
 *    dashboards are single-page section-composed surfaces with AJAX
 *    auto-refresh, so the base's settings-level tab/subtab/view
 *    routing is not replicated here; the orchestration dashboard's
 *    section view-routing lands with its sub-cluster).
 *  - Collaborators resolve per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator — never bare
 *    class_exists): base `WP_MCP_AI_Default_Assistants` (installed/
 *    installation info/default configs/reinstall) monolith — standalone
 *    degrades to the byte-identical absent-seeder shape
 *    (`installed` false → "Not Installed" banner; reinstall AJAX →
 *    `wp_mcp_ai_default_assistants_unavailable` error, documented);
 *    assistant meta keys via the base `WP_MCP_AI_Assistant_CPT`
 *    constants monolith / the AI addon's `AssistantPostType` constants
 *    standalone (identical values); the JetEngine probe monolith /
 *    false standalone; the base chat-bundle + test-assistant assets
 *    are monolith-only (standalone renders without the embedded
 *    test-chat modal — documented degradation); shortcode helpers
 *    monolith / defaults standalone; settings registry monolith /
 *    `wp_mcp_ai_settings` option standalone.
 *  - The dashboard's own assets (admin-multi-agent-dashboard.css/.js,
 *    admin-monitor-shared.css, admin-test-assistant.css/.js) are
 *    copied byte-identically into the platform asset tree; the base's
 *    `defined( 'WP_MCP_AI_REST::REST_NAMESPACE' )` constant probe
 *    collapses to the literal `mcp-ai/v1` (byte-identical behavior).
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Dashboards
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Dashboards;

/**
 * Multi-Agent System dashboard.
 *
 * Views and manages the default multi-agent orchestration assistants.
 *
 * @since 2.0.0
 */
class MultiAgentDashboard {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcp-ai-multi-agent';

	/**
	 * Nonce action for the dashboard AJAX handlers.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_multi_agent';

	/**
	 * Register the dashboard hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_get_multi_agent_stats', array( $this, 'ajax_get_stats' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_reinstall_agents', array( $this, 'ajax_reinstall_agents' ) );
	}

	/**
	 * Add admin menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		\add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Multi-Agent System', 'nvoos-content-graph-ai-platform' ),
			__( 'Multi-Agent System', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		// Check if this is our multi-agent dashboard page.
		$is_multi_agent_page = false !== \strpos( $hook, self::PAGE_SLUG );

		if ( ! $is_multi_agent_page ) {
			return;
		}

		$version = self::asset_version();

		// Enqueue shared monitor CSS for consistent styling.
		\wp_enqueue_style(
			'wp-mcp-ai-admin-monitor-shared',
			self::asset_url( 'css/admin-monitor-shared.css' ),
			array(),
			$version
		);

		\wp_enqueue_style(
			'wp-mcp-ai-multi-agent-dashboard',
			self::asset_url( 'css/admin-multi-agent-dashboard.css' ),
			array( 'wp-mcp-ai-admin-monitor-shared' ),
			$version
		);

		\wp_enqueue_script(
			'wp-mcp-ai-multi-agent-dashboard',
			self::asset_url( 'js/admin-multi-agent-dashboard.js' ),
			array( 'jquery' ),
			$version,
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-multi-agent-dashboard',
			'wpMcpAiMultiAgent',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE_ACTION ),
				'strings' => array(
					'confirmReinstall' => __( 'Are you sure you want to reinstall all default assistants? This will update their configurations.', 'nvoos-content-graph-ai-platform' ),
					'reinstalling'     => __( 'Reinstalling...', 'nvoos-content-graph-ai-platform' ),
					'reinstallSuccess' => __( 'Default assistants reinstalled successfully!', 'nvoos-content-graph-ai-platform' ),
					'reinstallError'   => __( 'Failed to reinstall assistants.', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);

		// Monolith-only: the embedded test-chat modal relies on the base
		// chat bundle + test-assistant assets (documented degradation —
		// standalone renders the dashboard without the modal).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->enqueue_chat_assets();
		}
	}

	/**
	 * Enqueue chat interface assets (monolith-only — see the class docblock).
	 *
	 * @return void
	 */
	protected function enqueue_chat_assets(): void {
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

		\wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			$cron_status_style_path,
			array(),
			$cron_status_style_version
		);

		\wp_enqueue_style(
			'wp-mcp-ai-chat',
			$style_path,
			array( 'wp-mcp-ai-cron-status' ),
			$style_version
		);

		\wp_enqueue_script(
			'wp-mcp-ai-chat',
			$script_path,
			array(),
			$script_version,
			true
		);

		// Test assistant CSS/JS (modal styling + functionality).
		$test_css_path    = WP_MCP_AI_PATH . 'assets/css/admin-test-assistant.css';
		$test_css_version = \file_exists( $test_css_path ) ? \filemtime( $test_css_path ) : WP_MCP_AI_VERSION;

		\wp_enqueue_style(
			'wp-mcp-ai-admin-test-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-test-assistant.css',
			array( 'wp-mcp-ai-chat' ),
			$test_css_version
		);

		$test_js_path    = WP_MCP_AI_PATH . 'assets/js/admin-test-assistant.js';
		$test_js_version = \file_exists( $test_js_path ) ? \filemtime( $test_js_path ) : WP_MCP_AI_VERSION;

		\wp_enqueue_script(
			'wp-mcp-ai-admin-test-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-test-assistant.js',
			array( 'wp-mcp-ai-chat' ),
			$test_js_version,
			true
		);

		// The base's `defined( 'WP_MCP_AI_REST::REST_NAMESPACE' )` probe
		// collapses to the literal (both branches resolve to the same
		// handle — byte-identical behavior).
		$rest_namespace = 'mcp-ai/v1';

		// Use the shortcode helper method for consistent async tool timeout calculation.
		$async_timeout_ms = \class_exists( 'WP_MCP_AI_Shortcode' )
			? \WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

		// Get plugin settings for cost display and capability flags configuration.
		$settings         = \WP_MCP_AI_Admin_Settings::get_settings();
		$show_usage_costs = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;

		// Allow filtering of cost display setting.
		$show_usage_costs = \apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, \get_current_user_id() );

		// Get capability flags display setting.
		$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;

		// Allow filtering of capability flags display setting.
		$show_capability_flags = \apply_filters( 'wp_mcp_ai_show_capability_flags', $show_capability_flags, \get_current_user_id() );

		\wp_localize_script(
			'wp-mcp-ai-chat',
			'wpMcpAiChat',
			array(
				'restUrl'             => \esc_url_raw( \trailingslashit( \rest_url( $rest_namespace ) ) ),
				'uploadEndpoint'      => \esc_url_raw( \rest_url( 'wp/v2/media' ) ),
				'prepareEndpoint'     => \esc_url_raw( \rest_url( $rest_namespace . '/attachments/prepare' ) ),
				'messagesEndpoint'    => \esc_url_raw( \rest_url( $rest_namespace . '/chat-client' ) ),
				'filesEndpoint'       => \esc_url_raw( \trailingslashit( \rest_url( $rest_namespace . '/files' ) ) ),
				'toolsEndpoint'       => \esc_url_raw( \rest_url( $rest_namespace . '/tools' ) ),
				'transcriptsEndpoint' => \esc_url_raw( \rest_url( $rest_namespace . '/chat-transcripts' ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => \get_current_user_id(),
				'nonce'               => \wp_create_nonce( 'wp_rest' ),
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
			'placeholder'     => __( 'Ask something…', 'nvoos-content-graph-ai-platform' ),
			'send'            => __( 'Send', 'nvoos-content-graph-ai-platform' ),
			'attachFile'      => __( 'Attach file', 'nvoos-content-graph-ai-platform' ),
			'transcribeAudio' => __( 'Transcribe audio', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Get asset version based on file modification time (monolith base assets).
	 *
	 * @param string $relative_path Asset path relative to plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$relative_path = \ltrim( $relative_path, '/' );
		$absolute_path = WP_MCP_AI_PATH . $relative_path;

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );

			if ( $modified ) {
				return WP_MCP_AI_VERSION . '.' . $modified;
			}
		}

		return WP_MCP_AI_VERSION;
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-mode seam).
	 *
	 * @return string
	 */
	protected static function asset_version() {
		$css_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/css/admin-multi-agent-dashboard.css';

		if ( \file_exists( $css_path ) ) {
			$modified = \filemtime( $css_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Get statistics.
		$stats = $this->get_agent_statistics();

		?>
		<div class="wrap wp-mcp-ai-multi-agent-dashboard">
			<h1>
				<?php \esc_html_e( 'Multi-Agent Orchestration System', 'nvoos-content-graph-ai-platform' ); ?>
			</h1>

			<p class="description">
				<?php
				\printf(
					/* translators: %d: Number of agents */
					\esc_html__( 'Manage your intelligent content and data orchestration grid with %d specialized AI assistants using various workflow patterns for optimal task execution.', 'nvoos-content-graph-ai-platform' ),
					\esc_html( $stats['total_agents'] )
				);
				?>
			</p>

			<!-- Auto-Refresh Controls -->
			<div class="auto-refresh-controls">
				<label>
					<input type="checkbox" id="toggle-auto-refresh" />
					<?php \esc_html_e( 'Auto-refresh', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<button type="button" class="button button-secondary" id="manual-refresh-btn">
					<span class="dashicons dashicons-update"></span>
					<?php \esc_html_e( 'Refresh Now', 'nvoos-content-graph-ai-platform' ); ?>
				</button>
				<span class="last-refresh-time">
					<?php \esc_html_e( 'Last updated:', 'nvoos-content-graph-ai-platform' ); ?>
					<strong id="last-refresh-time"><?php echo \esc_html( \current_time( 'H:i:s' ) ); ?></strong>
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
					<h2><?php \esc_html_e( 'Agent System Overview', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<div class="section-actions">
						<button type="button" class="button" id="reinstall-agents-btn">
							<span class="dashicons dashicons-update-alt"></span>
							<?php \esc_html_e( 'Reinstall Agents', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
						<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button">
							<span class="dashicons dashicons-list-view"></span>
							<?php \esc_html_e( 'View All Assistants', 'nvoos-content-graph-ai-platform' ); ?>
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
				<h2><?php \esc_html_e( 'Documentation & Resources', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<?php $this->render_documentation(); ?>
			</div>

			<?php if ( defined( 'WP_MCP_AI_PATH' ) ) : ?>
				<!-- Modal container for chat interface (monolith-only). -->
				<div id="wp-mcp-ai-test-modal" class="wp-mcp-ai-test-modal" style="display: none;">
					<div class="wp-mcp-ai-test-modal__backdrop"></div>
					<div class="wp-mcp-ai-test-modal__panel">
						<div class="wp-mcp-ai-test-modal__header">
							<h2 id="wp-mcp-ai-test-modal__title"><?php echo \esc_html__( 'Test Assistant', 'nvoos-content-graph-ai-platform' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo \esc_attr_x( 'Close', 'modal button', 'nvoos-content-graph-ai-platform' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<!-- Chat interface will be initialized here -->
							<div id="wp-mcp-ai-test-chat-container"></div>
						</div>
					</div>
				</div>
			<?php endif; ?>
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
			'installed'     => self::default_assistants_installed(),
			'installation'  => self::get_installation_info(),
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
		$default_configs = \WP_MCP_AI_Default_Assistants::get_default_assistants();

		// Also get Architect Agent config if method exists.
		if ( \method_exists( 'WP_MCP_AI_Default_Assistants', 'get_architect_agent_assistant_config' ) ) {
			$architect_config   = \WP_MCP_AI_Default_Assistants::get_architect_agent_assistant_config();
			$default_configs[] = $architect_config;
		}

		$stats['total_agents'] = \count( $default_configs );

		// Build slugs to configs map.
		$config_map = array();
		foreach ( $default_configs as $config ) {
			$config_map[ $config['slug'] ] = $config;
		}

		$meta_keys = self::assistant_meta_keys();

		// Get actual assistant posts.
		$assistant_ids = isset( $stats['installation']['assistant_ids'] ) ? $stats['installation']['assistant_ids'] : array();

		// Also check for Architect Agent separately since it may be created independently.
		$architect_agent = \get_page_by_path( 'architect-agent', OBJECT, 'mcp_ai_assistant' );
		if ( $architect_agent && ! \in_array( $architect_agent->ID, $assistant_ids, true ) ) {
			$assistant_ids[] = $architect_agent->ID;
		}

		foreach ( $assistant_ids as $post_id ) {
			$post = \get_post( $post_id );
			if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
				continue;
			}

			// Find matching config by slug.
			$config = isset( $config_map[ $post->post_name ] ) ? $config_map[ $post->post_name ] : null;

			// Get metadata.
			$provider      = \get_post_meta( $post_id, $meta_keys['provider'], true );
			$model         = \get_post_meta( $post_id, $meta_keys['model'], true );
			$temperature   = \get_post_meta( $post_id, $meta_keys['temperature'], true );
			$tools         = \get_post_meta( $post_id, $meta_keys['tools'], true );
			$primary_roles = \get_post_meta( $post_id, $meta_keys['primary_roles'], true );

			// Get usage stats from chat transcripts (if available).
			$last_used = $this->get_last_used_time( $post_id );

			// Determine workflow pattern based on slug and roles.
			$workflow_pattern = $this->detect_workflow_pattern( $post->post_name, \is_array( $primary_roles ) ? $primary_roles : array() );

			$agent_data = array(
				'id'               => $post_id,
				'title'            => $post->post_title,
				'slug'             => $post->post_name,
				'status'           => $post->post_status,
				'description'      => $post->post_content,
				'provider'         => $provider,
				'model'            => $model,
				'temperature'      => $temperature,
				'tools'            => \is_array( $tools ) ? $tools : array(),
				'tool_count'       => \is_array( $tools ) ? \count( $tools ) : 0,
				'primary_roles'    => \is_array( $primary_roles ) ? $primary_roles : array(),
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
	 * @param string $slug          Agent slug.
	 * @param array  $primary_roles Agent primary roles.
	 * @return string Workflow pattern (sequential, loop, router, hierarchical).
	 */
	protected function detect_workflow_pattern( $slug, $primary_roles ) {
		// Architect Agent uses Loop/Reflection pattern.
		if ( 'architect-agent' === $slug || \in_array( 'architect', $primary_roles, true ) ) {
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

		if ( \in_array( $slug, $sequential_slugs, true ) ) {
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
		// Check if JetEngine CCT is available for chat transcripts
		// (per-mode seam — the base probe does not exist standalone).
		if ( ! self::jetengine_available() ) {
			return null;
		}

		// Query most recent chat transcript for this assistant.
		global $wpdb;
		$table_name = $wpdb->prefix . 'jet_cct_ai_chat_transcripts';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			return null;
		}

		// Get most recent transcript.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is defined above; data is properly prepared.
		$last_transcript = $wpdb->get_row(
			$wpdb->prepare( "SELECT cct_created FROM {$table_name} WHERE assistant_id = %d ORDER BY cct_created DESC LIMIT 1", $assistant_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name interpolated from $wpdb->prefix-derived constant or validated list; not user input.
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
					<strong><?php \esc_html_e( 'Not Installed', 'nvoos-content-graph-ai-platform' ); ?></strong>
					<p><?php \esc_html_e( 'The multi-agent system has not been installed. Please activate the plugin to install default agents.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		$status_class = $stats['active_agents'] === $stats['total_agents'] ? 'status-success' : 'status-warning';
		?>
		<div class="status-banner <?php echo \esc_attr( $status_class ); ?>">
			<span class="dashicons dashicons-yes-alt"></span>
			<div class="status-content">
				<strong><?php \esc_html_e( 'System Operational', 'nvoos-content-graph-ai-platform' ); ?></strong>
				<p>
					<?php
					\printf(
						/* translators: 1: Active agents count, 2: Total agents count */
						\esc_html__( '%1$d of %2$d agents active and ready for orchestration.', 'nvoos-content-graph-ai-platform' ),
						\esc_html( $stats['active_agents'] ),
						\esc_html( $stats['total_agents'] )
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
				<div class="stat-value"><?php echo \esc_html( $stats['total_agents'] ); ?></div>
				<div class="stat-label"><?php \esc_html_e( 'Total Agents', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">✅</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo \esc_html( $stats['active_agents'] ); ?></div>
				<div class="stat-label"><?php \esc_html_e( 'Active Agents', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">🔧</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo \esc_html( $stats['total_tools'] ); ?></div>
				<div class="stat-label"><?php \esc_html_e( 'Total Tools', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div class="stat-card">
			<div class="stat-icon">⚡</div>
			<div class="stat-content">
				<div class="stat-value"><?php echo \esc_html( $stats['is_pro_active'] ? 'Pro' : 'Base' ); ?></div>
				<div class="stat-label"><?php \esc_html_e( 'Version', 'nvoos-content-graph-ai-platform' ); ?></div>
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
				<p><?php \esc_html_e( 'No agents installed. Please reinstall the default agents.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>
			<?php
			return;
		}

		// Render agents grouped by workflow pattern.
		$pattern_labels = array(
			'sequential'   => __( 'Sequential Workflow Agents', 'nvoos-content-graph-ai-platform' ),
			'loop'         => __( 'Loop/Reflection Pattern Agents', 'nvoos-content-graph-ai-platform' ),
			'router'       => __( 'Router Pattern Agents', 'nvoos-content-graph-ai-platform' ),
			'hierarchical' => __( 'Hierarchical Pattern Agents', 'nvoos-content-graph-ai-platform' ),
		);

		$pattern_descriptions = array(
			'sequential'   => __( 'Agents arranged in a sequential pipeline, passing output from one to the next in a defined order.', 'nvoos-content-graph-ai-platform' ),
			'loop'         => __( 'Agents that iteratively revisit and refine their outputs through feedback loops and self-reflection.', 'nvoos-content-graph-ai-platform' ),
			'router'       => __( 'Agents that dynamically route tasks to specialized agents based on context and requirements.', 'nvoos-content-graph-ai-platform' ),
			'hierarchical' => __( 'Agents arranged in supervisor-subordinate relationships for task decomposition and delegation.', 'nvoos-content-graph-ai-platform' ),
		);

		foreach ( $stats['patterns'] as $pattern => $pattern_agents ) {
			if ( empty( $pattern_agents ) ) {
				continue;
			}

			$pattern_label = isset( $pattern_labels[ $pattern ] ) ? $pattern_labels[ $pattern ] : \ucfirst( $pattern ) . ' ' . __( 'Agents', 'nvoos-content-graph-ai-platform' );
			$pattern_desc  = isset( $pattern_descriptions[ $pattern ] ) ? $pattern_descriptions[ $pattern ] : '';
			?>
			<div class="agent-pattern-group" data-pattern="<?php echo \esc_attr( $pattern ); ?>">
				<div class="pattern-header">
					<h3 class="pattern-title">
						<?php echo \esc_html( $pattern_label ); ?>
						<span class="pattern-count"><?php echo \esc_html( \count( $pattern_agents ) ); ?></span>
					</h3>
					<?php if ( $pattern_desc ) : ?>
						<p class="pattern-description"><?php echo \esc_html( $pattern_desc ); ?></p>
					<?php endif; ?>
				</div>

				<div class="agent-grid">
					<?php foreach ( $pattern_agents as $agent ) : ?>
						<div class="agent-card" data-agent-id="<?php echo \esc_attr( $agent['id'] ); ?>" data-pattern="<?php echo \esc_attr( $pattern ); ?>">
							<div class="agent-header">
								<h3 class="agent-title"><?php echo \esc_html( $agent['title'] ); ?></h3>
								<span class="agent-status <?php echo \esc_attr( 'publish' === $agent['status'] ? 'status-active' : 'status-inactive' ); ?>">
									<?php echo \esc_html( 'publish' === $agent['status'] ? __( 'Active', 'nvoos-content-graph-ai-platform' ) : __( 'Inactive', 'nvoos-content-graph-ai-platform' ) ); ?>
								</span>
							</div>

							<div class="agent-roles">
								<?php if ( ! empty( $agent['primary_roles'] ) ) : ?>
									<?php foreach ( $agent['primary_roles'] as $role ) : ?>
										<span class="role-badge"><?php echo \esc_html( $role ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<p class="agent-description"><?php echo \esc_html( \wp_trim_words( $agent['description'], 20 ) ); ?></p>

							<div class="agent-meta">
								<div class="meta-row">
									<span class="meta-label"><?php \esc_html_e( 'Model:', 'nvoos-content-graph-ai-platform' ); ?></span>
									<span class="meta-value"><?php echo \esc_html( $agent['model'] ); ?></span>
								</div>
								<div class="meta-row">
									<span class="meta-label"><?php \esc_html_e( 'Temperature:', 'nvoos-content-graph-ai-platform' ); ?></span>
									<span class="meta-value"><?php echo \esc_html( $agent['temperature'] ); ?></span>
								</div>
								<div class="meta-row">
									<span class="meta-label"><?php \esc_html_e( 'Tools:', 'nvoos-content-graph-ai-platform' ); ?></span>
									<span class="meta-value"><?php echo \esc_html( $agent['tool_count'] ); ?></span>
								</div>
								<?php if ( $agent['last_used'] ) : ?>
									<div class="meta-row">
										<span class="meta-label"><?php \esc_html_e( 'Last Used:', 'nvoos-content-graph-ai-platform' ); ?></span>
										<span class="meta-value"><?php echo \esc_html( \human_time_diff( \strtotime( $agent['last_used'] ), \time() ) . ' ago' ); ?></span>
									</div>
								<?php endif; ?>
							</div>

							<div class="agent-actions">
								<a href="<?php echo \esc_url( \admin_url( 'post.php?post=' . $agent['id'] . '&action=edit' ) ); ?>" class="button button-small" title="<?php \esc_attr_e( 'Edit', 'nvoos-content-graph-ai-platform' ); ?>">
									<span class="dashicons dashicons-edit" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php \esc_html_e( 'Edit', 'nvoos-content-graph-ai-platform' ); ?></span>
								</a>
								<?php if ( defined( 'WP_MCP_AI_PATH' ) ) : ?>
									<button
										type="button"
										class="button button-small wp-mcp-ai-test-assistant-btn"
										data-assistant-id="<?php echo \esc_attr( $agent['id'] ); ?>"
										data-assistant-title="<?php echo \esc_attr( $agent['title'] ); ?>"
										data-tool-shortcuts="<?php echo \esc_attr( \wp_json_encode( $this->get_assistant_tool_shortcuts( $agent['id'] ) ) ); ?>"
										data-provider="<?php echo \esc_attr( $agent['provider'] ); ?>"
										data-model="<?php echo \esc_attr( $agent['model'] ); ?>"
										title="<?php \esc_attr_e( 'Test', 'nvoos-content-graph-ai-platform' ); ?>"
									>
										<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php \esc_html_e( 'Test', 'nvoos-content-graph-ai-platform' ); ?></span>
									</button>
								<?php endif; ?>
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
				<h2><?php \esc_html_e( 'Sequential Workflow Pattern', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<?php $this->render_sequential_workflow_diagram(); ?>
			</div>
			<?php
		}

		// Render Loop/Reflection Workflow if present.
		if ( ! empty( $stats['patterns']['loop'] ) ) {
			?>
			<div class="workflow-section">
				<h2><?php \esc_html_e( 'Loop/Reflection Pattern (OODA Cycle)', 'nvoos-content-graph-ai-platform' ); ?></h2>
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
				<div class="step-label"><?php \esc_html_e( 'User Request', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step workflow-supervisor">
				<div class="step-icon">🎯</div>
				<div class="step-label"><?php \esc_html_e( 'Orchestrator', 'nvoos-content-graph-ai-platform' ); ?></div>
				<div class="step-description"><?php \esc_html_e( 'Routes & Coordinates', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">↓</div>
			<div class="workflow-step">
				<div class="step-icon">🔍</div>
				<div class="step-label"><?php \esc_html_e( 'Research', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">📊</div>
				<div class="step-label"><?php \esc_html_e( 'Parser', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✍️</div>
				<div class="step-label"><?php \esc_html_e( 'Drafter', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✅</div>
				<div class="step-label"><?php \esc_html_e( 'Auditor', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">🚀</div>
				<div class="step-label"><?php \esc_html_e( 'Publisher', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>
		<p class="workflow-note">
			<?php \esc_html_e( 'Sequential workflow with Orchestrator managing delegation and coordination between specialized agents.', 'nvoos-content-graph-ai-platform' ); ?>
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
				<div class="step-label"><?php \esc_html_e( 'Task Request', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-loop-cycle">
				<div class="cycle-label"><?php \esc_html_e( 'OODA Loop (Iterative)', 'nvoos-content-graph-ai-platform' ); ?></div>
				<div class="cycle-steps">
					<div class="workflow-step workflow-observe">
						<div class="step-icon">👁️</div>
						<div class="step-label"><?php \esc_html_e( 'OBSERVE', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="step-description"><?php \esc_html_e( 'Gather Data', 'nvoos-content-graph-ai-platform' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-orient">
						<div class="step-icon">🧭</div>
						<div class="step-label"><?php \esc_html_e( 'ORIENT', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="step-description"><?php \esc_html_e( 'Analyze Context', 'nvoos-content-graph-ai-platform' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-decide">
						<div class="step-icon">🤔</div>
						<div class="step-label"><?php \esc_html_e( 'DECIDE', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="step-description"><?php \esc_html_e( 'Choose Action', 'nvoos-content-graph-ai-platform' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow">↓</div>
					<div class="workflow-step workflow-act">
						<div class="step-icon">⚡</div>
						<div class="step-label"><?php \esc_html_e( 'ACT', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="step-description"><?php \esc_html_e( 'Execute & Validate', 'nvoos-content-graph-ai-platform' ); ?></div>
					</div>
					<div class="workflow-arrow cycle-arrow-back">↻</div>
					<div class="cycle-back-note"><?php \esc_html_e( 'Iterate until goal achieved', 'nvoos-content-graph-ai-platform' ); ?></div>
				</div>
			</div>
			<div class="workflow-arrow">→</div>
			<div class="workflow-step">
				<div class="step-icon">✅</div>
				<div class="step-label"><?php \esc_html_e( 'Task Complete', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>
		<p class="workflow-note">
			<?php \esc_html_e( 'Loop/Reflection pattern using OODA cycle (Observe-Orient-Decide-Act) for iterative refinement and self-correction. Rapid cycling enables adaptive, context-aware development.', 'nvoos-content-graph-ai-platform' ); ?>
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
		$doc_url = defined( 'WP_MCP_AI_URL' )
			? WP_MCP_AI_URL . 'docs/MULTI_AGENT_ORCHESTRATION_IMPLEMENTATION.md'
			: '';
		?>
		<div class="documentation-grid">
			<div class="doc-card">
				<h3><span class="dashicons dashicons-book"></span> <?php \esc_html_e( 'Implementation Guide', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php \esc_html_e( 'Learn about the multi-agent architecture, industry best practices, and workflow patterns.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<?php if ( '' !== $doc_url ) : ?>
					<a href="<?php echo \esc_url( $doc_url ); ?>" class="button" target="_blank">
						<?php \esc_html_e( 'View Documentation', 'nvoos-content-graph-ai-platform' ); ?>
					</a>
				<?php else : ?>
					<span class="button disabled"><?php \esc_html_e( 'Documentation unavailable', 'nvoos-content-graph-ai-platform' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="doc-card">
				<h3><span class="dashicons dashicons-admin-tools"></span> <?php \esc_html_e( 'Tool Reference', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php \esc_html_e( 'Explore the 141+ base tools and 52 Pro tools available to your agents.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button">
					<?php \esc_html_e( 'Browse Tools', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
			</div>

			<div class="doc-card">
				<h3><span class="dashicons dashicons-admin-users"></span> <?php \esc_html_e( 'All Assistants', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php \esc_html_e( 'View and manage all assistants including custom ones beyond the default 6.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button">
					<?php \esc_html_e( 'Manage Assistants', 'nvoos-content-graph-ai-platform' ); ?>
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
	public function ajax_get_stats(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$stats = $this->get_agent_statistics();
		\wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler to reinstall default agents.
	 *
	 * @return void
	 */
	public function ajax_reinstall_agents(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$result = self::reinstall_default_assistants();

		if ( \is_wp_error( $result ) ) {
			\wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		\wp_send_json_success( array( 'message' => __( 'Default agents reinstalled successfully.', 'nvoos-content-graph-ai-platform' ) ) );
	}

	/**
	 * Get assistant tool shortcuts.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of tool shortcuts.
	 */
	protected function get_assistant_tool_shortcuts( $assistant_id ) {
		$assistant_id = \absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		// Safety check: Ensure class exists.
		if ( ! \class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return array();
		}

		// Use the shortcode class method if it exists.
		if ( \method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
			return \WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );
		}

		return array();
	}

	// -------------------------------------------------------------------------
	// Per-mode collaborator seams (see the class docblock)
	// -------------------------------------------------------------------------

	/**
	 * Whether the default-assistant seeder is installed per install mode.
	 *
	 * @return bool
	 */
	protected static function default_assistants_installed() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			return \WP_MCP_AI_Default_Assistants::is_installed();
		}

		// Standalone: no default-assistant seeder ships with the addons —
		// byte-identical absent-seeder degradation ("Not Installed" banner).
		return false;
	}

	/**
	 * Get the seeder installation info per install mode.
	 *
	 * @return array
	 */
	protected static function get_installation_info() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			return \WP_MCP_AI_Default_Assistants::get_installation_info();
		}

		return array();
	}

	/**
	 * Reinstall the default assistants per install mode.
	 *
	 * @return true|\WP_Error
	 */
	protected static function reinstall_default_assistants() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			return \WP_MCP_AI_Default_Assistants::reinstall();
		}

		return new \WP_Error(
			'wp_mcp_ai_default_assistants_unavailable',
			__( 'The default-assistant seeder is unavailable in this install mode.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Resolve the assistant meta-key map per install mode.
	 *
	 * @return array{provider: string, model: string, temperature: string, tools: string, primary_roles: string}
	 */
	protected static function assistant_meta_keys() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array(
				'provider'      => \WP_MCP_AI_Assistant_CPT::META_PROVIDER,
				'model'         => \WP_MCP_AI_Assistant_CPT::META_MODEL,
				'temperature'   => \WP_MCP_AI_Assistant_CPT::META_TEMPERATURE,
				'tools'         => \WP_MCP_AI_Assistant_CPT::META_TOOLS,
				'primary_roles' => \WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES,
			);
		}

		return array(
			'provider'      => \NvoosContentGraphAi\Admin\AssistantPostType::META_PROVIDER,
			'model'         => \NvoosContentGraphAi\Admin\AssistantPostType::META_MODEL,
			'temperature'   => \NvoosContentGraphAi\Admin\AssistantPostType::META_TEMPERATURE,
			'tools'         => \NvoosContentGraphAi\Admin\AssistantPostType::META_TOOLS,
			'primary_roles' => \NvoosContentGraphAi\Admin\AssistantPostType::META_PRIMARY_ROLES,
		);
	}

	/**
	 * Whether JetEngine chat transcripts are available per install mode.
	 *
	 * @return bool
	 */
	protected static function jetengine_available() {
		if ( \function_exists( 'wp_mcp_ai_is_jetengine_available' ) ) {
			return \wp_mcp_ai_is_jetengine_available();
		}

		return false;
	}
}
