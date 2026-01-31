<?php
/**
 * Admin page for Security Training management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Training Admin Page class.
 *
 * Provides admin UI for managing ISO 27001 security training.
 */
class WP_MCP_AI_Security_Training_Admin {
	/**
	 * Initialize admin page.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'save_post_mcp_ai_training', array( $this, 'save_training_meta' ), 10, 2 );
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Security Training', 'mcp-ai-wpoos' ),
			__( 'Security Training', 'mcp-ai-wpoos' ),
			'read',
			'nvoos-security-training',
			array( $this, 'render_page' )
		);

		// Add admin stats page for administrators.
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Training Statistics', 'mcp-ai-wpoos' ),
			__( 'Training Stats', 'mcp-ai-wpoos' ),
			'manage_options',
			'nvoos-training-statistics',
			array( $this, 'render_stats_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'nvoos-pro_page_nvoos-security-training' !== $hook && 'nvoos-pro_page_nvoos-training-statistics' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-security-training',
			WP_MCP_AI_URL . 'assets/css/security-training.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-security-training',
			WP_MCP_AI_URL . 'assets/js/security-training.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-security-training',
			'wpMcpAiTraining',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'apiUrl'  => rest_url( 'mcp-ai/v1/training' ),
				'strings' => array(
					'completeSuccess' => __( 'Training completed successfully!', 'mcp-ai-wpoos' ),
					'completeError'   => __( 'Failed to record completion. Please try again.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Save training module meta data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function save_training_meta( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['mcp_ai_training_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_training_details_nonce'] ) ), 'mcp_ai_training_details' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save meta fields.
		if ( isset( $_POST['training_role'] ) ) {
			update_post_meta( $post_id, '_training_role', sanitize_text_field( wp_unslash( $_POST['training_role'] ) ) );
		}

		if ( isset( $_POST['training_type'] ) ) {
			update_post_meta( $post_id, '_training_type', sanitize_text_field( wp_unslash( $_POST['training_type'] ) ) );
		}

		if ( isset( $_POST['training_duration'] ) ) {
			update_post_meta( $post_id, '_training_duration', absint( $_POST['training_duration'] ) );
		}

		$mandatory = isset( $_POST['training_mandatory'] ) ? '1' : '0';
		update_post_meta( $post_id, '_training_mandatory', $mandatory );
	}

	/**
	 * Render user training page.
	 */
	public function render_page() {
		$user_id     = get_current_user_id();
		$training    = WP_MCP_AI_Security_Training::get_instance();
		$completions = $training->get_user_completions( $user_id );

		// Get available modules.
		$modules = get_posts(
			array(
				'post_type'      => 'mcp_ai_training',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		?>
		<div class="wrap wp-mcp-ai-security-training">
			<h1><?php echo esc_html__( 'Security Training', 'mcp-ai-wpoos' ); ?></h1>

			<div class="wp-mcp-ai-training-notice" style="display: none;"></div>

			<div class="wp-mcp-ai-training-progress">
				<h2><?php echo esc_html__( 'Your Progress', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<?php
					/* translators: %1$d: completed modules, %2$d: total modules */
					printf(
						esc_html__( 'Completed %1$d of %2$d training modules', 'mcp-ai-wpoos' ),
						count( $completions ),
						count( $modules )
					);
					?>
				</p>
				<div class="wp-mcp-ai-progress-bar">
					<div class="wp-mcp-ai-progress-fill" style="width: <?php echo esc_attr( count( $modules ) > 0 ? ( count( $completions ) / count( $modules ) ) * 100 : 0 ); ?>%;"></div>
				</div>
			</div>

			<div class="wp-mcp-ai-training-modules">
				<h2><?php echo esc_html__( 'Available Training Modules', 'mcp-ai-wpoos' ); ?></h2>

				<?php foreach ( $modules as $module ) : ?>
					<?php
					$is_completed = $training->is_training_completed( $user_id, $module->ID );
					$role         = get_post_meta( $module->ID, '_training_role', true );
					$type         = get_post_meta( $module->ID, '_training_type', true );
					$duration     = get_post_meta( $module->ID, '_training_duration', true );
					$mandatory    = get_post_meta( $module->ID, '_training_mandatory', true ) === '1';
					?>

					<div class="wp-mcp-ai-training-module <?php echo $is_completed ? 'completed' : ''; ?>" data-module-id="<?php echo esc_attr( $module->ID ); ?>">
						<div class="wp-mcp-ai-module-header">
							<h3>
								<?php echo esc_html( $module->post_title ); ?>
								<?php if ( $mandatory ) : ?>
									<span class="wp-mcp-ai-badge wp-mcp-ai-badge-mandatory"><?php echo esc_html__( 'Mandatory', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
								<?php if ( $is_completed ) : ?>
									<span class="wp-mcp-ai-badge wp-mcp-ai-badge-completed"><?php echo esc_html__( 'Completed', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</h3>
							<div class="wp-mcp-ai-module-meta">
								<span class="wp-mcp-ai-module-type"><?php echo esc_html( WP_MCP_AI_Security_Training::MODULE_TYPES[ $type ] ?? $type ); ?></span>
								<span class="wp-mcp-ai-module-duration"><?php echo esc_html( $duration ); ?> <?php echo esc_html__( 'minutes', 'mcp-ai-wpoos' ); ?></span>
								<span class="wp-mcp-ai-module-role"><?php echo esc_html( WP_MCP_AI_Security_Training::TRAINING_ROLES[ $role ] ?? $role ); ?></span>
							</div>
						</div>

						<div class="wp-mcp-ai-module-content">
							<?php if ( $module->post_excerpt ) : ?>
								<p><?php echo esc_html( $module->post_excerpt ); ?></p>
							<?php endif; ?>

							<button type="button" class="button wp-mcp-ai-view-module" data-module-id="<?php echo esc_attr( $module->ID ); ?>">
								<?php echo esc_html__( 'View Module', 'mcp-ai-wpoos' ); ?>
							</button>

							<?php if ( ! $is_completed ) : ?>
								<button type="button" class="button button-primary wp-mcp-ai-complete-module" data-module-id="<?php echo esc_attr( $module->ID ); ?>">
									<?php echo esc_html__( 'Mark as Complete', 'mcp-ai-wpoos' ); ?>
								</button>
							<?php endif; ?>
						</div>

						<div class="wp-mcp-ai-module-full-content" style="display: none;">
							<?php echo wp_kses_post( $module->post_content ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $modules ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No training modules available yet.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render statistics page (admin only).
	 */
	public function render_stats_page() {
		$stats = WP_MCP_AI_Security_Training::get_instance()->get_training_statistics();

		?>
		<div class="wrap wp-mcp-ai-training-stats">
			<h1><?php echo esc_html__( 'Training Statistics', 'mcp-ai-wpoos' ); ?></h1>

			<div class="wp-mcp-ai-stats-grid">
				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['total_modules'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php echo esc_html__( 'Training Modules', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['total_users'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php echo esc_html__( 'Total Users', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['total_completions'] ); ?></div>
					<div class="wp-mcp-ai-stat-label"><?php echo esc_html__( 'Total Completions', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card">
					<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['completion_rate'] ); ?>%</div>
					<div class="wp-mcp-ai-stat-label"><?php echo esc_html__( 'Completion Rate', 'mcp-ai-wpoos' ); ?></div>
				</div>
			</div>

			<div class="wp-mcp-ai-training-info">
				<h2><?php echo esc_html__( 'About Security Training', 'mcp-ai-wpoos' ); ?></h2>
				<p><?php echo esc_html__( 'This security training system implements ISO 27001:2022 Control A.6.3 - Information Security Awareness, Education and Training.', 'mcp-ai-wpoos' ); ?></p>
				<p><?php echo esc_html__( 'All users should complete mandatory training modules annually to maintain security awareness and compliance.', 'mcp-ai-wpoos' ); ?></p>
			</div>
		</div>
		<?php
	}
}

// Initialize admin page.
// NOTE: This is now handled by WP_MCP_AI_Pro_Dashboard to ensure
// proper coordination of ISO 27001 admin pages.
// if ( is_admin() ) {
// new WP_MCP_AI_Security_Training_Admin();
// }.
