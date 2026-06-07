<?php
/**
 * Test Agent Page — chat-based agent testing interface.
 *
 * Extracted from `includes/admin/class-wp-mcp-ai-admin-test-assistant.php`
 * and its base class `class-wp-mcp-ai-admin-test-page-base.php`.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyPlatform\Agents\Admin;

use NvoosGraphifyPlatform\Agents\Agents;

/**
 * Admin page for testing AI agents via a chat modal interface.
 */
final class TestAgentPage {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function registerPage(): void {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . Agents::POST_TYPE,
			__( 'Test Agent', 'nvoos-graphify-platform' ),
			__( 'Test Agent', 'nvoos-graphify-platform' ),
			'manage_options',
			'nvoos-graphify-platform-test-agent',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue assets for the test page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		$this->enqueueChatAssets();

		wp_enqueue_style(
			'nvoos-graphify-platform-agents-test',
			NVOOS_GRAPHIFY_PLATFORM_URL . 'assets/css/agents-test.css',
			array( 'wp-mcp-ai-chat' ),
			NVOOS_GRAPHIFY_PLATFORM_VERSION
		);

		wp_enqueue_script(
			'nvoos-graphify-platform-agents-test',
			NVOOS_GRAPHIFY_PLATFORM_URL . 'assets/js/agents-test.js',
			array( 'wp-mcp-ai-chat' ),
			NVOOS_GRAPHIFY_PLATFORM_VERSION,
			true
		);
	}

	/**
	 * Enqueue chat interface assets from the base plugin.
	 *
	 * @return void
	 */
	private function enqueueChatAssets(): void {
		wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			WP_MCP_AI_URL . 'assets/css/cron-status.css',
			array(),
			$this->getAssetVersion( 'assets/css/cron-status.css' )
		);

		wp_enqueue_style(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/css/chat.css',
			array( 'wp-mcp-ai-cron-status' ),
			$this->getAssetVersion( 'assets/css/chat.css' )
		);

		wp_enqueue_script(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/js/chat-bundle.min.js',
			array(),
			$this->getAssetVersion( 'assets/js/chat-bundle.min.js' ),
			true
		);

		$rest_namespace  = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? \WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';
		$async_timeout_ms = class_exists( 'WP_MCP_AI_Shortcode' )
			? \WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

		$show_usage_costs     = false;
		$show_capability_flags = false;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings              = \WP_MCP_AI_Admin_Settings::get_settings();
			$show_usage_costs      = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;
			$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;
		}

		wp_localize_script(
			'wp-mcp-ai-chat',
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( $this->normaliseRestUrl( rest_url( $rest_namespace ) ) ) ),
				'uploadEndpoint'      => esc_url_raw( $this->normaliseRestUrl( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'     => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/attachments/prepare' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( $this->normaliseRestUrl( rest_url( $rest_namespace . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'showCapabilityFlags' => $show_capability_flags,
				'asyncToolTimeout'    => $async_timeout_ms,
				'strings'             => $this->getChatStrings(),
			)
		);
	}

	/**
	 * Normalize REST URL.
	 *
	 * @param string $url REST URL.
	 * @return string
	 */
	private function normaliseRestUrl( string $url ): string {
		if ( class_exists( 'WP_MCP_AI_Request_Context' ) && method_exists( 'WP_MCP_AI_Request_Context', 'normalise_rest_url' ) ) {
			return \WP_MCP_AI_Request_Context::normalise_rest_url( $url );
		}
		return $url;
	}

	/**
	 * Get asset version based on file modification time.
	 *
	 * @param string $relative_path Asset path relative to plugin root.
	 * @return string
	 */
	private function getAssetVersion( string $relative_path ): string {
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
	 * Get chat interface strings.
	 *
	 * @return array<string, mixed>
	 */
	private function getChatStrings(): array {
		return array(
			'placeholder'               => __( 'Ask something…', 'nvoos-graphify-platform' ),
			'send'                      => __( 'Send', 'nvoos-graphify-platform' ),
			'sending'                   => __( 'Sending message…', 'nvoos-graphify-platform' ),
			'waiting'                   => __( 'Waiting for the agent…', 'nvoos-graphify-platform' ),
			'error'                     => __( 'Something went wrong.', 'nvoos-graphify-platform' ),
			'missingAssistant'          => __( 'Configuration was not found.', 'nvoos-graphify-platform' ),
			'notAuthorized'             => __( 'You do not have permission to chat.', 'nvoos-graphify-platform' ),
			'toolExecuting'             => __( 'Running tool: %s', 'nvoos-graphify-platform' ),
			'toolSuccess'               => __( 'Tool completed successfully.', 'nvoos-graphify-platform' ),
			'toolError'                 => __( 'The tool request failed.', 'nvoos-graphify-platform' ),
			'toolQueued'                => __( 'Tool queued.', 'nvoos-graphify-platform' ),
			'toolPolling'               => __( 'Tool is processing…', 'nvoos-graphify-platform' ),
			'toolTimeout'               => __( 'Tool timed out.', 'nvoos-graphify-platform' ),
			'toolFailed'                => __( 'Tool failed: %s', 'nvoos-graphify-platform' ),
			'emptyMessage'              => __( 'Enter a message before sending.', 'nvoos-graphify-platform' ),
			'attachFile'                => __( 'Attach file', 'nvoos-graphify-platform' ),
			'uploadingFile'             => __( 'Uploading…', 'nvoos-graphify-platform' ),
			'uploadError'               => __( 'Upload failed.', 'nvoos-graphify-platform' ),
			'uploadInProgress'          => __( 'Upload in progress.', 'nvoos-graphify-platform' ),
			'unsupportedFileType'       => __( 'Unsupported file type.', 'nvoos-graphify-platform' ),
			'newConversation'           => __( 'New conversation', 'nvoos-graphify-platform' ),
			'loadConversation'          => __( 'Load conversation', 'nvoos-graphify-platform' ),
			'historyLoading'            => __( 'Loading…', 'nvoos-graphify-platform' ),
			'historyEmpty'              => __( 'No previous conversations.', 'nvoos-graphify-platform' ),
			'historyError'              => __( 'Unable to load history.', 'nvoos-graphify-platform' ),
			'deleteConversation'        => __( 'Delete conversation', 'nvoos-graphify-platform' ),
			'confirmDeleteConversation' => __( 'Delete this conversation?', 'nvoos-graphify-platform' ),
			'roleLabels'                => array(
				'assistant' => __( 'Agent', 'nvoos-graphify-platform' ),
				'user'      => __( 'You', 'nvoos-graphify-platform' ),
				'system'    => __( 'System', 'nvoos-graphify-platform' ),
				'tool'      => __( 'Tool', 'nvoos-graphify-platform' ),
			),
		);
	}

	/**
	 * Render the test agent page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-graphify-platform' ) );
		}

		$assistants = get_posts(
			array(
				'post_type'      => Agents::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Test AI Agents', 'nvoos-graphify-platform' ); ?></h1>
			<p><?php esc_html_e( 'Test your AI agents directly from the admin dashboard. Click "Test" next to any agent to open a chat interface and validate its behavior.', 'nvoos-graphify-platform' ); ?></p>

			<?php if ( empty( $assistants ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create new agent */
							esc_html__( 'No agents found. %s to get started.', 'nvoos-graphify-platform' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ) . '">' . esc_html__( 'Create your first agent', 'nvoos-graphify-platform' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Agent Name', 'nvoos-graphify-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Provider', 'nvoos-graphify-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Model', 'nvoos-graphify-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Professionals', 'nvoos-graphify-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tools', 'nvoos-graphify-platform' ); ?></th>
							<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'nvoos-graphify-platform' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $assistants as $agent ) : ?>
							<?php
							$config = array();
							if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
								$config = \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $agent->ID );
							}

							$provider       = ! empty( $config['provider'] ) ? $config['provider'] : __( 'Default', 'nvoos-graphify-platform' );
							$model          = ! empty( $config['model'] ) ? $config['model'] : __( 'Default', 'nvoos-graphify-platform' );
							$tool_count     = isset( $config['tools'] ) && is_array( $config['tools'] ) ? count( $config['tools'] ) : 0;
							$edit_url       = get_edit_post_link( $agent->ID );
							$tool_shortcuts = $this->getAgentToolShortcuts( $agent->ID );
							$professionals  = $this->getAgentProfessionals( $agent->ID );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $agent->post_title ); ?></strong>
									<div class="row-actions">
										<span class="edit">
											<a href="<?php echo esc_url( $edit_url ); ?>">
												<?php esc_html_e( 'Edit', 'nvoos-graphify-platform' ); ?>
											</a>
										</span>
									</div>
								</td>
								<td><?php echo esc_html( ucfirst( $provider ) ); ?></td>
								<td><code><?php echo esc_html( $model ); ?></code></td>
								<td>
									<?php
									if ( empty( $professionals ) ) {
										echo '<em>' . esc_html__( 'None', 'nvoos-graphify-platform' ) . '</em>';
									} else {
										echo esc_html( implode( ', ', $professionals ) );
									}
									?>
								</td>
								<td>
									<?php
									/* translators: %d: number of tools */
									echo esc_html( sprintf( _n( '%d tool', '%d tools', $tool_count, 'nvoos-graphify-platform' ), $tool_count ) );
									?>
								</td>
								<td>
									<button
										type="button"
										class="button button-primary wp-mcp-ai-test-assistant-btn"
										data-assistant-id="<?php echo esc_attr( (string) $agent->ID ); ?>"
										data-assistant-title="<?php echo esc_attr( $agent->post_title ); ?>"
										data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $tool_shortcuts ) ); ?>"
										data-provider="<?php echo esc_attr( ! empty( $config['provider'] ) ? $config['provider'] : '' ); ?>"
										data-model="<?php echo esc_attr( ! empty( $config['model'] ) ? $config['model'] : '' ); ?>"
									>
										<?php esc_html_e( 'Test', 'nvoos-graphify-platform' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Modal container for chat interface -->
			<div id="wp-mcp-ai-test-modal" class="wp-mcp-ai-test-modal" style="display: none;">
				<div class="wp-mcp-ai-test-modal__backdrop"></div>
				<div class="wp-mcp-ai-test-modal__panel">
					<div class="wp-mcp-ai-test-modal__header">
						<h2 id="wp-mcp-ai-test-modal__title"><?php esc_html_e( 'Test Agent', 'nvoos-graphify-platform' ); ?></h2>
						<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'nvoos-graphify-platform' ); ?>">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="wp-mcp-ai-test-modal__body">
						<div id="wp-mcp-ai-test-chat-container"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get agent tool shortcuts.
	 *
	 * @param int $agent_id Agent post ID.
	 * @return array<int, array<string, string>>
	 */
	private function getAgentToolShortcuts( int $agent_id ): array {
		if ( ! $agent_id || ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return array();
		}

		if ( method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
			return \WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $agent_id );
		}

		return array();
	}

	/**
	 * Get professionals associated with an agent.
	 *
	 * @param int $agent_id Agent post ID.
	 * @return array<int, string>
	 */
	private function getAgentProfessionals( int $agent_id ): array {
		if ( ! $agent_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array();
		}

		$primary_roles = get_post_meta( $agent_id, \WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, true );

		if ( ! is_array( $primary_roles ) || empty( $primary_roles ) ) {
			return array();
		}

		$names = array();
		foreach ( $primary_roles as $profession_id ) {
			$profession_id = absint( $profession_id );
			if ( ! $profession_id ) {
				continue;
			}

			$profession = get_post( $profession_id );
			if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type ) {
				continue;
			}

			$names[] = $profession->post_title;
		}

		return $names;
	}
}
