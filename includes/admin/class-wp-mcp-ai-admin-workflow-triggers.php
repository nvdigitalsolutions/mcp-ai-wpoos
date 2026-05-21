<?php
/**
 * Admin Page: Workflow Triggers.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page controller for workflow triggers.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Admin_Workflow_Triggers {

	/**
	 * Admin menu page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor — wires up hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 28 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Workflow Triggers', 'mcp-ai-wpoos' ),
			__( 'Workflow Triggers', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-workflow-triggers',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'wp-mcp-ai-workflow-triggers' ) ) {
			return;
		}
		$js_path    = WP_MCP_AI_PATH . 'assets/js/admin-workflow-triggers.js';
		$js_version = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WP_MCP_AI_VERSION;
		wp_enqueue_script(
			'wp-mcp-ai-workflow-triggers',
			WP_MCP_AI_URL . 'assets/js/admin-workflow-triggers.js',
			array(),
			$js_version,
			true
		);
		wp_localize_script(
			'wp-mcp-ai-workflow-triggers',
			'wpMcpAiTriggers',
			array(
				'apiBase' => esc_url_raw( rest_url( 'mcp-ai/v1/orchestration/triggers' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Are you sure you want to delete this trigger?', 'mcp-ai-wpoos' ),
					'errorGeneric'  => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos' ),
					'labelEnabled'  => __( 'Enabled', 'mcp-ai-wpoos' ),
					'labelDisabled' => __( 'Disabled', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos' ) );
		}

		$registry      = WP_MCP_AI_Workflow_Trigger_Registry::get_instance();
		$trigger_types = $registry->get_triggers();

		$workflows = get_posts(
			array(
				'post_type'      => 'mcp_ai_workflow',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$trigger_posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Workflow_Trigger_CPT::CPT,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Workflow Triggers', 'mcp-ai-wpoos' ); ?></h1>
			<p><?php esc_html_e( 'Define triggers that automatically start a workflow when a WordPress event occurs.', 'mcp-ai-wpoos' ); ?></p>

			<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-triggers-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Workflow', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Last Fired', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody id="wp-mcp-ai-triggers-body">
				<?php if ( empty( $trigger_posts ) ) : ?>
					<tr id="wp-mcp-ai-no-triggers-row">
						<td colspan="6"><?php esc_html_e( 'No triggers found. Add one below.', 'mcp-ai-wpoos' ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $trigger_posts as $trigger ) :
						$type       = (string) get_post_meta( $trigger->ID, '_wp_mcp_ai_trigger_type', true );
						$enabled    = (bool) get_post_meta( $trigger->ID, '_wp_mcp_ai_trigger_enabled', true );
						$wf_id      = (int) get_post_meta( $trigger->ID, '_wp_mcp_ai_trigger_workflow_id', true );
						$last_fired = (int) get_post_meta( $trigger->ID, '_wp_mcp_ai_trigger_last_fired_at', true );
						$wf_post    = $wf_id ? get_post( $wf_id ) : null;
						$type_def   = $registry->get_trigger( $type );
						$type_label = $type_def ? esc_html( $type_def['label'] ) : esc_html( $type );
						?>
					<tr data-trigger-id="<?php echo esc_attr( $trigger->ID ); ?>">
						<td><?php echo $type_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $type_label is already esc_html()'d on the preceding line; PHPCS false positive. ?></td>
						<td class="trigger-label"><?php echo esc_html( $trigger->post_title ); ?></td>
						<td><?php echo $wf_post ? esc_html( $wf_post->post_title ) : esc_html( (string) $wf_id ); ?></td>
						<td class="trigger-status"><?php echo $enabled ? esc_html__( 'Enabled', 'mcp-ai-wpoos' ) : esc_html__( 'Disabled', 'mcp-ai-wpoos' ); ?></td>
						<td><?php echo $last_fired ? esc_html( gmdate( 'Y-m-d H:i', $last_fired ) ) : '&mdash;'; ?></td>
						<td>
							<button class="button button-small wp-mcp-ai-toggle-trigger" data-id="<?php echo esc_attr( $trigger->ID ); ?>" data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
								<?php echo $enabled ? esc_html__( 'Disable', 'mcp-ai-wpoos' ) : esc_html__( 'Enable', 'mcp-ai-wpoos' ); ?>
							</button>
							<button class="button button-small button-link-delete wp-mcp-ai-delete-trigger" data-id="<?php echo esc_attr( $trigger->ID ); ?>">
								<?php esc_html_e( 'Delete', 'mcp-ai-wpoos' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add Trigger', 'mcp-ai-wpoos' ); ?></h2>
			<form id="wp-mcp-ai-add-trigger-form" style="max-width:600px;">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="trigger-name"><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></label></th>
						<td><input type="text" id="trigger-name" name="name" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="trigger-type"><?php esc_html_e( 'Trigger Type', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select id="trigger-type" name="type" required>
								<option value=""><?php esc_html_e( '— Select type —', 'mcp-ai-wpoos' ); ?></option>
								<?php foreach ( $trigger_types as $slug => $def ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $def['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trigger-workflow"><?php esc_html_e( 'Target Workflow', 'mcp-ai-wpoos' ); ?></label></th>
						<td>
							<select id="trigger-workflow" name="workflow_id" required>
								<option value=""><?php esc_html_e( '— Select workflow —', 'mcp-ai-wpoos' ); ?></option>
								<?php foreach ( $workflows as $wf ) : ?>
									<option value="<?php echo esc_attr( $wf->ID ); ?>"><?php echo esc_html( $wf->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Trigger', 'mcp-ai-wpoos' ); ?></button>
					<span id="wp-mcp-ai-trigger-form-msg" style="margin-left:10px;"></span>
				</p>
			</form>
		</div>
		<?php
	}
}
