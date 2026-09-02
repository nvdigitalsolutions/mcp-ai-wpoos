<?php
/**
 * Test Assistant admin page for the Content Graph AI addon (Wave D-UI-4).
 *
 * Aligned port of the base plugin's Test Assistant page
 * (`includes/admin/class-wp-mcp-ai-admin-test-assistant.php`): the same
 * published-assistant table (name / provider / model / professionals /
 * tools / actions), the same per-assistant configuration vocabulary,
 * and the same chat-test modal shell. Class names use the ecosystem's
 * `nvoos-cg-*` prefix and the page slug is `nvoos-cg-test-assistant` so
 * monolith installs can run both pages side by side (documented
 * deviation).
 *
 * Documented deviation (additive decoupling): instead of the base's
 * client-side chat.js initialisation per clicked row, the ecosystem
 * modal embeds the server-rendered `[nvoos_content_graph_chat]` widget
 * (assistant selected via the `test_assistant` query parameter — the
 * Test buttons are links) so the widget's own config pipeline stays the
 * single source of truth. A small script handles close/Escape/backdrop.
 *
 * @package NvoosContentGraphAi\Admin\AssistantPages
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\AssistantPages;

use NvoosContentGraphAi\Admin\AssistantPostType;
use NvoosContentGraphAi\Frontend\ChatShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test Assistant admin page handler.
 *
 * @since 1.1.0
 */
class TestAssistantPage extends TestPageBase {

	/**
	 * Page slug (ecosystem-specific; never collides with the base).
	 */
	const PAGE_SLUG = 'nvoos-cg-test-assistant';

	/**
	 * Query parameter carrying the assistant to test.
	 */
	const TEST_PARAM = 'test_assistant';

	/**
	 * Get the post type for this test page.
	 *
	 * @return string
	 */
	protected function get_post_type() {
		return AssistantPostType::POST_TYPE;
	}

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	protected function get_page_slug() {
		return self::PAGE_SLUG;
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 */
	protected function get_page_title() {
		return __( 'Test Assistant', 'nvoos-content-graph-ai' );
	}

	/**
	 * Get the menu title.
	 *
	 * @return string
	 */
	protected function get_menu_title() {
		return __( 'Test Assistant', 'nvoos-content-graph-ai' );
	}

	/**
	 * Enqueue page-specific assets.
	 *
	 * @return void
	 */
	protected function enqueue_page_assets(): void {
		wp_enqueue_style(
			'nvoos-cg-admin-test-assistant',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/admin-test-assistant.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION
		);

		wp_enqueue_script(
			'nvoos-cg-admin-test-assistant',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/admin-test-assistant.js',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);
	}

	/**
	 * Render the test assistant page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$this->check_permission();

		$post_type = $this->get_post_type();

		// Get all published assistants.
		$assistants = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$test_assistant_id = $this->get_active_test_assistant_id();

		?>
		<div class="wrap nvoos-cg-test-assistant-page">
			<h1><?php esc_html_e( 'Test AI Assistants', 'nvoos-content-graph-ai' ); ?></h1>
			<p><?php esc_html_e( 'Test your AI assistants directly from the admin dashboard. Click "Test" next to any assistant to open a chat interface and validate its behavior.', 'nvoos-content-graph-ai' ); ?></p>

			<?php if ( empty( $assistants ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create new assistant */
							esc_html__( 'No assistants found. %s to get started.', 'nvoos-content-graph-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first assistant', 'nvoos-content-graph-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Assistant Name', 'nvoos-content-graph-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Provider', 'nvoos-content-graph-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Model', 'nvoos-content-graph-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Professionals', 'nvoos-content-graph-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tools', 'nvoos-content-graph-ai' ); ?></th>
							<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'nvoos-content-graph-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $assistants as $assistant ) : ?>
							<?php
							$config = $this->get_assistant_configuration( $assistant->ID );

							$provider      = ! empty( $config['provider'] ) ? $config['provider'] : __( 'Default', 'nvoos-content-graph-ai' );
							$model         = ! empty( $config['model'] ) ? $config['model'] : __( 'Default', 'nvoos-content-graph-ai' );
							$tool_count    = isset( $config['tools'] ) && is_array( $config['tools'] ) ? count( $config['tools'] ) : 0;
							$edit_url      = get_edit_post_link( $assistant->ID );
							$professionals = $this->get_assistant_professionals( $assistant->ID );
							$test_url      = add_query_arg(
								array(
									'post_type'          => $post_type,
									'page'               => self::PAGE_SLUG,
									self::TEST_PARAM     => $assistant->ID,
								),
								admin_url( 'edit.php' )
							);
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $assistant->post_title ); ?></strong>
									<div class="row-actions">
										<span class="edit">
											<a href="<?php echo esc_url( $edit_url ); ?>">
												<?php esc_html_e( 'Edit', 'nvoos-content-graph-ai' ); ?>
											</a>
										</span>
									</div>
								</td>
								<td><?php echo esc_html( ucfirst( (string) $provider ) ); ?></td>
								<td><code><?php echo esc_html( (string) $model ); ?></code></td>
								<td>
									<?php
									if ( empty( $professionals ) ) {
										echo '<em>' . esc_html__( 'None', 'nvoos-content-graph-ai' ) . '</em>';
									} else {
										echo esc_html( implode( ', ', $professionals ) );
									}
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: number of tools enabled for the assistant */
											_n( '%s tool', '%s tools', $tool_count, 'nvoos-content-graph-ai' ),
											number_format_i18n( $tool_count )
										)
									);
									?>
								</td>
								<td>
									<a
										class="button button-primary nvoos-cg-test-assistant-btn"
										href="<?php echo esc_url( $test_url ); ?>"
										data-assistant-id="<?php echo esc_attr( (string) $assistant->ID ); ?>"
									>
										<?php esc_html_e( 'Test', 'nvoos-content-graph-ai' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Modal container for chat interface -->
			<div id="nvoos-cg-test-modal" class="nvoos-cg-test-modal" style="display: <?php echo $test_assistant_id ? 'block' : 'none'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static display value. ?>;">
				<div class="nvoos-cg-test-modal__backdrop"></div>
				<div class="nvoos-cg-test-modal__panel">
					<div class="nvoos-cg-test-modal__header">
						<h2 id="nvoos-cg-test-modal__title"><?php esc_html_e( 'Test Assistant', 'nvoos-content-graph-ai' ); ?></h2>
						<a class="button button-secondary nvoos-cg-test-modal__close" href="<?php echo esc_url( remove_query_arg( self::TEST_PARAM ) ); ?>">
							<?php esc_html_e( 'Close', 'nvoos-content-graph-ai' ); ?>
						</a>
					</div>
					<div class="nvoos-cg-test-modal__body">
						<?php
						if ( $test_assistant_id ) {
							$shortcode = '[nvoos_content_graph_chat assistant="' . $test_assistant_id . '" height="520px"]';
							echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
						} else {
							echo '<p>' . esc_html__( 'Select an assistant from the list above to start testing.', 'nvoos-content-graph-ai' ) . '</p>';
						}
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Resolve the assistant selected for testing (query parameter).
	 *
	 * @return int Assistant post ID or 0.
	 */
	public function get_active_test_assistant_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
		$assistant_id = isset( $_GET[ self::TEST_PARAM ] ) ? absint( wp_unslash( $_GET[ self::TEST_PARAM ] ) ) : 0;

		if ( ! $assistant_id ) {
			return 0;
		}

		$post = get_post( $assistant_id );
		if ( ! $post || AssistantPostType::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0;
		}

		return $assistant_id;
	}

	/**
	 * Read the assistant configuration (per-install-mode seam).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function get_assistant_configuration( $assistant_id ): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
			$config = \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			return is_array( $config ) ? $config : array();
		}

		$config = array(
			'provider' => get_post_meta( $assistant_id, AssistantPostType::META_PROVIDER, true ),
			'model'    => get_post_meta( $assistant_id, AssistantPostType::META_MODEL, true ),
			'tools'    => get_post_meta( $assistant_id, AssistantPostType::META_TOOLS, true ),
		);

		if ( ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		return $config;
	}

	/**
	 * Get professionals associated with an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of profession names.
	 */
	private function get_assistant_professionals( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		$primary_roles = get_post_meta( $assistant_id, AssistantPostType::META_PRIMARY_ROLES, true );

		if ( ! is_array( $primary_roles ) || empty( $primary_roles ) ) {
			return array();
		}

		$profession_names = array();

		foreach ( $primary_roles as $profession_id ) {
			$profession_id = absint( $profession_id );

			if ( ! $profession_id ) {
				continue;
			}

			$profession = get_post( $profession_id );

			if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type ) {
				continue;
			}

			$profession_names[] = $profession->post_title;
		}

		return $profession_names;
	}
}
