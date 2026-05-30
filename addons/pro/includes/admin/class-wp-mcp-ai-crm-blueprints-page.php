<?php
/**
 * CRM Blueprints Admin Page
 *
 * Browse and install curated CRM assistant blueprints directly from
 * the NV CRM admin section. Lists all available blueprint JSON files
 * in the crm/examples directory with descriptions, tags, and
 * one-click install capability.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Blueprints Page Class
 */
class WP_MCP_AI_CRM_Blueprints_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-crm-blueprints';

	/**
	 * Blueprints directory.
	 *
	 * @var string
	 */
	const BLUEPRINTS_DIR = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples';

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Initialize.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 27 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_crm_install_blueprint', array( __CLASS__, 'ajax_install_blueprint' ) );
		add_action( 'wp_ajax_wp_mcp_ai_crm_get_blueprint_details', array( __CLASS__, 'ajax_get_blueprint_details' ) );
	}

	/**
	 * Register submenu page under NV CRM.
	 */
	public static function register_page() {
		self::$page_hook = add_submenu_page(
			WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG,
			__( 'CRM Blueprints', 'mcp-ai-wpoos-pro' ),
			__( 'Blueprints', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$page_hook !== $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
		}

		// Inline styles.
		add_action(
			'admin_head',
			function () {
				?>
				<style>
				.crm-bp-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
					gap: 20px;
					margin-top: 20px;
				}
				.crm-bp-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 6px;
					padding: 24px;
					display: flex;
					flex-direction: column;
					transition: box-shadow 0.2s;
				}
				.crm-bp-card:hover {
					box-shadow: 0 2px 12px rgba(0,0,0,0.08);
					border-color: #2271b1;
				}
				.crm-bp-card h3 {
					margin: 0 0 8px;
					font-size: 16px;
				}
				.crm-bp-card .crm-bp-desc {
					color: #646970;
					font-size: 13px;
					line-height: 1.5;
					margin-bottom: 12px;
					flex: 1;
				}
				.crm-bp-card .crm-bp-meta {
					display: flex;
					flex-wrap: wrap;
					gap: 6px;
					margin-bottom: 12px;
				}
				.crm-bp-tag {
					background: #f0f0f1;
					color: #50575e;
					font-size: 11px;
					padding: 2px 8px;
					border-radius: 3px;
					white-space: nowrap;
				}
				.crm-bp-tag.installed {
					background: #d4edda;
					color: #155724;
				}
				.crm-bp-actions {
					display: flex;
					gap: 8px;
				}
				.crm-bp-spinner {
					display: none;
					margin: 0;
					float: none;
				}
				.crm-bp-notice {
					margin: 16px 0 0;
				}
				</style>
				<?php
			}
		);

		wp_enqueue_script(
			'wp-mcp-ai-crm-blueprints',
			WP_MCP_AI_PRO_URL . 'assets/js/crm-blueprints.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);
		wp_localize_script(
			'wp-mcp-ai-crm-blueprints',
			'wpMcpAiCrmBlueprints',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'editUrl' => admin_url( 'post.php?action=edit&post=0' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_crm_bp' ),
				'i18n'    => array(
					'installing'    => __( 'Installing...', 'mcp-ai-wpoos-pro' ),
					'installed'     => __( 'Installed!', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'Error installing blueprint.', 'mcp-ai-wpoos-pro' ),
					'duplicate'     => __( 'Blueprint already exists.', 'mcp-ai-wpoos-pro' ),
					'overwrite'     => __( 'Overwrite existing?', 'mcp-ai-wpoos-pro' ),
					'installLabel'  => __( 'Install Blueprint', 'mcp-ai-wpoos-pro' ),
					'viewDetails'   => __( 'View Details', 'mcp-ai-wpoos-pro' ),
					'hideDetails'   => __( 'Hide Details', 'mcp-ai-wpoos-pro' ),
					'loading'       => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'instructions'  => __( 'Instructions', 'mcp-ai-wpoos-pro' ),
					'tools'         => __( 'Tools', 'mcp-ai-wpoos-pro' ),
					'defaults'      => __( 'Defaults', 'mcp-ai-wpoos-pro' ),
					'model'         => __( 'Model', 'mcp-ai-wpoos-pro' ),
					'temperature'   => __( 'Temperature', 'mcp-ai-wpoos-pro' ),
					'maxTokens'     => __( 'Max Tokens', 'mcp-ai-wpoos-pro' ),
					'noDetails'     => __( 'No additional details available.', 'mcp-ai-wpoos-pro' ),
					'viewAssistant' => __( 'View Assistant', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the blueprints page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$blueprints = self::get_all_blueprints();
		$installed  = self::get_installed_blueprints();

		// Handle non-AJAX install (fallback).
		if ( isset( $_POST['wp_mcp_ai_crm_install_blueprint'] ) ) {
			check_admin_referer( 'wp_mcp_ai_crm_bp' );
			$bp_slug = isset( $_POST['blueprint_slug'] ) ? sanitize_key( wp_unslash( $_POST['blueprint_slug'] ) ) : '';
			$result  = self::install_blueprint(
				$bp_slug,
				! empty( $_POST['overwrite'] )
			);
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . '</p></div>';
			}
		}
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-layout" style="font-size: 28px; width: 28px; height: 28px; vertical-align: middle;"></span>
				<?php esc_html_e( 'CRM Blueprints', 'mcp-ai-wpoos-pro' ); ?>
			</h1>
			<p class="description" style="max-width: 700px;">
				<?php esc_html_e( 'Curated AI assistant blueprints pre-configured for specific CRM roles and industries. Each blueprint includes tailored instructions, tool sets, and channel configurations. Click "Install" to create a new assistant from a blueprint.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( empty( $blueprints ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No CRM blueprints found in the examples directory.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php else : ?>
				<div class="crm-bp-grid">
					<?php foreach ( $blueprints as $slug => $bp ) : ?>
						<?php $is_installed = in_array( $bp['name'], $installed, true ); ?>
						<div class="crm-bp-card" data-blueprint="<?php echo esc_attr( $slug ); ?>">
							<h3><?php echo esc_html( $bp['name'] ); ?></h3>
							<div class="crm-bp-desc"><?php echo esc_html( $bp['description'] ); ?></div>
							<div class="crm-bp-meta">
								<?php if ( ! empty( $bp['meta']['profession'] ) ) : ?>
									<span class="crm-bp-tag"><?php echo esc_html( ucfirst( $bp['meta']['profession'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $bp['meta']['framework'] ) ) : ?>
									<span class="crm-bp-tag"><?php echo esc_html( strtoupper( $bp['meta']['framework'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $bp['meta']['channels'] ) ) : ?>
									<?php foreach ( $bp['meta']['channels'] as $channel ) : ?>
										<span class="crm-bp-tag"><?php echo esc_html( $channel ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
								<?php if ( $is_installed ) : ?>
									<span class="crm-bp-tag installed"><?php esc_html_e( 'Installed', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="crm-bp-actions">
								<?php if ( $is_installed ) : ?>
									<button type="button" class="button" disabled>
										<?php esc_html_e( 'Installed', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								<?php else : ?>
									<button type="button"
											class="button button-primary crm-bp-install-btn"
											data-slug="<?php echo esc_attr( $slug ); ?>">
										<?php esc_html_e( 'Install Blueprint', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								<?php endif; ?>
								<button type="button"
										class="button crm-bp-view-btn"
										data-slug="<?php echo esc_attr( $slug ); ?>">
									<?php esc_html_e( 'View Details', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<div class="crm-bp-spinner"></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get all available CRM blueprints with parsed metadata.
	 *
	 * @return array
	 */
	private static function get_all_blueprints() {
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			return array();
		}

		$slugs  = WP_MCP_AI_Blueprint_Installer::list_blueprints( self::BLUEPRINTS_DIR );
		$result = array();

		foreach ( $slugs as $slug ) {
			$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $slug );
			if ( is_wp_error( $data ) ) {
				continue;
			}
			$result[ $slug ] = $data;
		}

		return $result;
	}

	/**
	 * Get list of already-installed blueprint names.
	 *
	 * @return array
	 */
	private static function get_installed_blueprints() {
		$posts = get_posts(
			array(
				'post_type'        => 'mcp_ai_assistant',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => '_blueprint_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => 'EXISTS',
			)
		);

		$names = array();
		foreach ( $posts as $post_id ) {
			$names[] = get_the_title( $post_id );
		}

		return $names;
	}

	/**
	 * Install a blueprint by slug.
	 *
	 * @param string $slug      Blueprint slug.
	 * @param bool   $overwrite Whether to overwrite existing.
	 * @return array|WP_Error
	 */
	public static function install_blueprint( $slug, $overwrite = false ) {
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			return new WP_Error(
				'installer_missing',
				__( 'Blueprint installer is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $slug );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return WP_MCP_AI_Blueprint_Installer::install( $data, $slug, $overwrite );
	}

	/**
	 * AJAX handler: install a blueprint.
	 */
	public static function ajax_install_blueprint() {
		check_ajax_referer( 'wp_mcp_ai_crm_bp', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$slug      = isset( $_POST['blueprint_slug'] ) ? sanitize_key( wp_unslash( $_POST['blueprint_slug'] ) ) : '';
		$overwrite = ! empty( $_POST['overwrite'] );

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No blueprint specified.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = self::install_blueprint( $slug, $overwrite );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: get blueprint details.
	 */
	public static function ajax_get_blueprint_details() {
		check_ajax_referer( 'wp_mcp_ai_crm_bp', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$slug = isset( $_POST['blueprint_slug'] ) ? sanitize_key( wp_unslash( $_POST['blueprint_slug'] ) ) : '';

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No blueprint specified.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			wp_send_json_error( array( 'message' => __( 'Blueprint installer not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( self::BLUEPRINTS_DIR, $slug );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

			// Blueprint fields may be at the top level (healthcare-style)
			// or nested inside `meta` (CRM-style). Normalise to a flat
			// structure before building the response.
			$meta = $data['meta'] ?? $data['meta_input'] ?? array();

			// CRM-style instructions are in meta.instructions;
			// healthcare-style uses post_content / meta_input._wp_mcp_ai_system_prompt.
			$instructions = $meta['instructions']
				?? $meta['_wp_mcp_ai_system_prompt']
				?? $data['post_content']
				?? $data['instructions']
				?? '';

			// CRM-style tools are in meta.available_tools;
			// healthcare-style uses meta_input._wp_mcp_ai_tools.
			$tools = $meta['available_tools']
				?? $meta['_wp_mcp_ai_tools']
				?? $data['tools']
				?? array();

			// Build a sensible defaults block for the preview panel.
			$defaults = array();
		if ( ! empty( $meta['_wp_mcp_ai_model'] ) ) {
			$defaults['model'] = $meta['_wp_mcp_ai_model'];
		}
		if ( isset( $meta['_wp_mcp_ai_temperature'] ) ) {
			$defaults['temperature'] = $meta['_wp_mcp_ai_temperature'];
		}
		if ( ! empty( $meta['_wp_mcp_ai_provider'] ) ) {
			$defaults['provider'] = $meta['_wp_mcp_ai_provider'];
		}
		if ( ! empty( $meta['profession'] ) ) {
			$defaults['profession'] = $meta['profession'];
		}
		if ( ! empty( $meta['framework'] ) ) {
			$defaults['framework'] = $meta['framework'];
		}
		if ( ! empty( $meta['channels'] ) ) {
			$defaults['channels'] = $meta['channels'];
		}

			// Return sanitized subset for frontend display.
			$result = array(
				'name'         => $data['name'] ?? $data['post_title'] ?? '',
				'description'  => $data['description'] ?? $data['post_content'] ?? '',
				'instructions' => $instructions,
				'tools'        => is_array( $tools ) ? $tools : array(),
				'defaults'     => ! empty( $defaults ) ? $defaults : null,
			);

			wp_send_json_success( $result );
	}
}
