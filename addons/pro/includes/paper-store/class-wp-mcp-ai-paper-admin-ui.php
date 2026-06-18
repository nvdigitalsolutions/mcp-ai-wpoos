<?php
/**
 * Paper Store Admin UI — Pro WordPress admin page for managing collections.
 *
 * Registers a submenu under NV oOS Assistants menu with full CRUD capabilities:
 * create/delete collections, add/edit/delete records, inline forms, and
 * a stats overview bar. Follows WordPress admin conventions (WP_List_Table
 * styling, form-table forms, admin_post handlers, nonce verification).
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_Paper_Admin_UI
 *
 * Singleton. Call init() to register the admin page under NV oOS Assistants
 * and wire up admin_post handlers for all write operations.
 */
class WP_MCP_AI_Paper_Admin_UI {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private string $page_slug = 'nv-oos-paper-store';

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize admin hooks.
	 *
	 * Registers the submenu page and admin_post handlers for write operations.
	 * Priority 30 — matches Skill Manager convention.
	 */
	public static function init(): void {
		$instance = self::get_instance();
		add_action( 'admin_menu', array( $instance, 'register_admin_page' ), 30 );

		// POST handlers for write operations.
		add_action( 'admin_post_wp_mcp_ai_paper_save_record', array( $instance, 'handle_save_record' ) );
		add_action( 'admin_post_wp_mcp_ai_paper_delete_record', array( $instance, 'handle_delete_record' ) );
		add_action( 'admin_post_wp_mcp_ai_paper_create_collection', array( $instance, 'handle_create_collection' ) );
		add_action( 'admin_post_wp_mcp_ai_paper_delete_collection', array( $instance, 'handle_delete_collection' ) );
	}

	/**
	 * Register the admin submenu page under NV oOS Assistants.
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Paper Store', 'mcp-ai-wpoos-pro' ),
			__( 'Paper Store', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	// ──────────────────────────────────────────────
	// Admin Notice Display
	// ──────────────────────────────────────────────

	/**
	 * Display an admin notice based on query parameters.
	 *
	 * Reads ?message=saved|deleted|created|error|collection_deleted
	 * and renders the appropriate notice.
	 */
	private function display_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flash-message reading; no state changes.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		// phpcs:enable

		if ( empty( $message ) ) {
			return;
		}

		$notices = array(
			'saved'              => array( 'success', __( 'Record saved successfully.', 'mcp-ai-wpoos-pro' ) ),
			'deleted'            => array( 'success', __( 'Record deleted.', 'mcp-ai-wpoos-pro' ) ),
			'created'            => array( 'success', __( 'Collection created successfully.', 'mcp-ai-wpoos-pro' ) ),
			'collection_deleted' => array( 'success', __( 'Collection deleted.', 'mcp-ai-wpoos-pro' ) ),
			'error'              => array( 'error', __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ) ),
			'invalid_json'       => array( 'error', __( 'Invalid JSON in body or meta field. Record not saved.', 'mcp-ai-wpoos-pro' ) ),
			'missing_id'         => array( 'error', __( 'Record ID is required.', 'mcp-ai-wpoos-pro' ) ),
			'not_found'          => array( 'error', __( 'Record or collection not found.', 'mcp-ai-wpoos-pro' ) ),
		);

		if ( ! isset( $notices[ $message ] ) ) {
			return;
		}

		list( $type, $text ) = $notices[ $message ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	// ──────────────────────────────────────────────
	// Main Page Renderer & Routing
	// ──────────────────────────────────────────────

	/**
	 * Render the Paper Store admin page.
	 *
	 * Routes to the correct sub-view based on query parameters:
	 * - No collection selected → collections list
	 * - Collection selected, no action → record list (browse)
	 * - action=add → add record form
	 * - action=edit + record → edit record form
	 * - action=view + record → record detail view
	 * - action=delete + record → delete confirmation
	 */
	public function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing; state-change requests are handled via admin_post.
		$selected_collection = isset( $_GET['collection'] ) ? sanitize_key( wp_unslash( $_GET['collection'] ) ) : '';
		$selected_record     = isset( $_GET['record'] ) ? sanitize_key( wp_unslash( $_GET['record'] ) ) : '';
		$action              = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		// phpcs:enable

		echo '<div class="wrap nv-oos-paper-store-wrap">';
		echo '<h1>' . esc_html__( 'NVoOS Paper Store', 'mcp-ai-wpoos-pro' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Browse and manage flat-file records stored in the Paper Store.', 'mcp-ai-wpoos-pro' ) . '</p>';

		// Display any flash messages from previous operations.
		$this->display_notice();

		if ( empty( $selected_collection ) ) {
			$this->render_collections_list();
		} elseif ( 'add' === $action ) {
			$this->render_record_form( $selected_collection );
		} elseif ( 'edit' === $action && ! empty( $selected_record ) ) {
			$this->render_record_form( $selected_collection, $selected_record );
		} elseif ( 'delete' === $action && ! empty( $selected_record ) ) {
			$this->render_delete_confirmation( $selected_collection, $selected_record );
		} elseif ( 'view' === $action && ! empty( $selected_record ) ) {
			$this->render_record_view( $selected_collection, $selected_record );
		} else {
			$this->render_collection_view( $selected_collection );
		}

		echo '</div>';
	}

	/**
	 * Build an admin URL for the Paper Store page.
	 *
	 * @param array $args Query arguments.
	 * @return string Full admin URL.
	 */
	private function admin_page_url( array $args = array() ): string {
		$args['page']      = $this->page_slug;
		$args['post_type'] = 'mcp_ai_assistant';
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Build an admin_post.php action URL with a nonce.
	 *
	 * @param string $action  The admin_post action name.
	 * @param array  $args    Extra query arguments.
	 * @param string $nonce_action Nonce action name.
	 * @return string Full admin_post.php URL.
	 */
	private function admin_post_url( string $action, array $args = array(), string $nonce_action = '' ): string {
		$args['action'] = $action;
		if ( ! empty( $nonce_action ) ) {
			$args['_wpnonce'] = wp_create_nonce( $nonce_action );
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	// ──────────────────────────────────────────────
	// Collections List (with stats + CRUD)
	// ──────────────────────────────────────────────

	/**
	 * Render the collections list with stats bar and action buttons.
	 */
	private function render_collections_list(): void {
		$manager     = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$collections = $manager->list_collections();

		// Stats bar.
		$this->render_stats_bar( $collections, $manager );

		// "Add Collection" form (collapsible).
		$this->render_collection_create_form();

		if ( empty( $collections ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No collections exist yet. Create one above, or use the Paper Store tools to create records — collections are created automatically.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th class="column-primary">' . esc_html__( 'Collection', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Records', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $collections as $collection ) {
			$repo  = $manager->get_repository( $collection );
			$count = $repo->count();

			$browse_url = $this->admin_page_url( array( 'collection' => $collection ) );
			$delete_url = $this->admin_post_url(
				'wp_mcp_ai_paper_delete_collection',
				array( 'collection' => $collection ),
				'paper_delete_collection_' . $collection
			);

			echo '<tr>';
			echo '<td class="column-primary"><strong>' . esc_html( $collection ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $count ) . '</td>';
			echo '<td class="nv-oos-paper-store__actions">';
			echo '<a href="' . esc_url( $browse_url ) . '" class="button button-small">' . esc_html__( 'Browse', 'mcp-ai-wpoos-pro' ) . '</a> ';
			echo '<a href="' . esc_url( $delete_url ) . '" class="button button-small button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this collection and all its records? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '\')">' . esc_html__( 'Delete', 'mcp-ai-wpoos-pro' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the stats overview bar.
	 *
	 * @param string[]                      $collections Collection names.
	 * @param WP_MCP_AI_Paper_Store_Manager $manager     The Paper Store manager.
	 */
	private function render_stats_bar( array $collections, WP_MCP_AI_Paper_Store_Manager $manager ): void {
		$total_records   = 0;
		$published_count = 0;
		$draft_count     = 0;

		foreach ( $collections as $collection ) {
			$repo           = $manager->get_repository( $collection );
			$records        = $repo->all();
			$total_records += count( $records );

			foreach ( $records as $record ) {
				$status = $record['status'] ?? 'published';
				if ( 'published' === $status ) {
					++$published_count;
				} elseif ( 'draft' === $status ) {
					++$draft_count;
				}
			}
		}

		echo '<div class="nv-oos-paper-store__stats">';
		echo '<div class="nv-oos-paper-store__stat">';
		echo '<div class="nv-oos-paper-store__stat-label">' . esc_html__( 'Collections', 'mcp-ai-wpoos-pro' ) . '</div>';
		echo '<div class="nv-oos-paper-store__stat-value">' . esc_html( (string) count( $collections ) ) . '</div>';
		echo '</div>';
		echo '<div class="nv-oos-paper-store__stat">';
		echo '<div class="nv-oos-paper-store__stat-label">' . esc_html__( 'Total Records', 'mcp-ai-wpoos-pro' ) . '</div>';
		echo '<div class="nv-oos-paper-store__stat-value">' . esc_html( (string) $total_records ) . '</div>';
		echo '</div>';
		echo '<div class="nv-oos-paper-store__stat">';
		echo '<div class="nv-oos-paper-store__stat-label">' . esc_html__( 'Published', 'mcp-ai-wpoos-pro' ) . '</div>';
		echo '<div class="nv-oos-paper-store__stat-value nv-oos-paper-store__stat-value--success">' . esc_html( (string) $published_count ) . '</div>';
		echo '</div>';
		echo '<div class="nv-oos-paper-store__stat">';
		echo '<div class="nv-oos-paper-store__stat-label">' . esc_html__( 'Drafts', 'mcp-ai-wpoos-pro' ) . '</div>';
		echo '<div class="nv-oos-paper-store__stat-value nv-oos-paper-store__stat-value--warning">' . esc_html( (string) $draft_count ) . '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a collapsible "Create Collection" form.
	 */
	private function render_collection_create_form(): void {
		$create_url = $this->admin_post_url(
			'wp_mcp_ai_paper_create_collection',
			array(),
			'paper_create_collection'
		);

		?>
		<details class="nv-oos-paper-store__create-collection" style="margin-bottom: 1rem;">
			<summary style="cursor:pointer; font-weight:600; padding:0.5rem 0;">
				<?php esc_html_e( '+ Create New Collection', 'mcp-ai-wpoos-pro' ); ?>
			</summary>
			<form method="post" action="<?php echo esc_url( $create_url ); ?>" style="margin-top:0.5rem; padding:1rem; background:#f8f9ff; border:1px solid #dcdcde; border-radius:4px;">
				<?php wp_nonce_field( 'paper_create_collection', '_wpnonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="paper-collection-name"><?php esc_html_e( 'Collection Name', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="paper-collection-name" name="collection_name" class="regular-text" required
								placeholder="<?php esc_attr_e( 'e.g. knowledge, prompts, workflows', 'mcp-ai-wpoos-pro' ); ?>"
								pattern="[a-z0-9_-]+" title="<?php esc_attr_e( 'Lowercase letters, numbers, hyphens, and underscores only.', 'mcp-ai-wpoos-pro' ); ?>" />
							<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, hyphens, and underscores only. A directory will be created under the Paper Store root.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Collection', 'mcp-ai-wpoos-pro' ); ?></button>
				</p>
			</form>
		</details>
		<?php
	}

	// ──────────────────────────────────────────────
	// Record List (browse collection)
	// ──────────────────────────────────────────────

	/**
	 * Render a single collection's records with row actions.
	 *
	 * @param string $collection Collection name.
	 */
	private function render_collection_view( string $collection ): void {
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$records = $repo->all();

		$back_url = $this->admin_page_url();
		$add_url  = $this->admin_page_url(
			array(
				'collection' => $collection,
				'action'     => 'add',
			)
		);

		echo '<p>';
		echo '<a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collections', 'mcp-ai-wpoos-pro' ) . '</a>';
		echo '</p>';

		echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">';
		/* translators: %s: collection name */
		echo '<h2 style="margin:0;">' . esc_html( sprintf( __( 'Collection: %s', 'mcp-ai-wpoos-pro' ), $collection ) ) . '</h2>';
		echo '<a href="' . esc_url( $add_url ) . '" class="button button-primary">' . esc_html__( '+ Add Record', 'mcp-ai-wpoos-pro' ) . '</a>';
		echo '</div>';

		if ( empty( $records ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No records in this collection. Click "Add Record" to create one.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th class="column-primary">' . esc_html__( 'ID', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$record_id = $record['id'] ?? '';

			$view_url   = $this->admin_page_url(
				array(
					'collection' => $collection,
					'record'     => $record_id,
					'action'     => 'view',
				)
			);
			$edit_url   = $this->admin_page_url(
				array(
					'collection' => $collection,
					'record'     => $record_id,
					'action'     => 'edit',
				)
			);
			$delete_url = $this->admin_page_url(
				array(
					'collection' => $collection,
					'record'     => $record_id,
					'action'     => 'delete',
					'_wpnonce'   => wp_create_nonce( 'paper_delete_record_' . $collection . '_' . $record_id ),
				)
			);

			$status       = $record['status'] ?? 'published';
			$status_class = 'published' === $status ? 'nv-oos-paper-store__status--published' : 'nv-oos-paper-store__status--draft';
			$updated      = isset( $record['updated_at'] ) ? $record['updated_at'] : '';

			echo '<tr>';
			echo '<td class="column-primary"><a href="' . esc_url( $view_url ) . '"><code>' . esc_html( $record_id ) . '</code></a></td>';
			echo '<td>' . esc_html( $record['title'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $record['type'] ?? $collection ) . '</td>';
			echo '<td><span class="nv-oos-paper-store__status ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span></td>';
			echo '<td>' . esc_html( $updated ) . '</td>';
			echo '<td class="nv-oos-paper-store__actions">';
			echo '<a href="' . esc_url( $view_url ) . '" class="button button-small">' . esc_html__( 'View', 'mcp-ai-wpoos-pro' ) . '</a> ';
			echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'mcp-ai-wpoos-pro' ) . '</a> ';
			echo '<a href="' . esc_url( $delete_url ) . '" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'mcp-ai-wpoos-pro' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// ──────────────────────────────────────────────
	// Record Add / Edit Form
	// ──────────────────────────────────────────────

	/**
	 * Render the add/edit record form.
	 *
	 * @param string      $collection Collection name.
	 * @param string|null $record_id  Record ID for editing, or null for new record.
	 */
	private function render_record_form( string $collection, ?string $record_id = null ): void {
		$is_edit = null !== $record_id;

		$back_url = $this->admin_page_url( array( 'collection' => $collection ) );

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collection', 'mcp-ai-wpoos-pro' ) . '</a></p>';

		if ( $is_edit ) {
			$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
			$repo    = $manager->get_repository( $collection );
			$record  = $repo->find( $record_id );

			if ( null === $record ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Record not found.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
				return;
			}

			/* translators: %s: record title */
			echo '<h2>' . esc_html( sprintf( __( 'Edit Record: %s', 'mcp-ai-wpoos-pro' ), $record['title'] ?? $record_id ) ) . '</h2>';
		} else {
			$record = array();
			echo '<h2>' . esc_html__( 'Add New Record', 'mcp-ai-wpoos-pro' ) . '</h2>';
		}

		$save_url = $this->admin_post_url(
			'wp_mcp_ai_paper_save_record',
			array( 'collection' => $collection ),
			'paper_save_record_' . $collection
		);

		// Pre-fill values for the form, with safe defaults.
		$form_id          = $record['id'] ?? '';
		$form_title       = $record['title'] ?? '';
		$form_type        = $record['type'] ?? $collection;
		$form_description = $record['description'] ?? '';
		$form_status      = $record['status'] ?? 'published';
		$form_tags        = isset( $record['tags'] ) && is_array( $record['tags'] ) ? implode( ', ', $record['tags'] ) : '';
		$form_body        = isset( $record['body'] ) ? wp_json_encode( $record['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';
		$form_meta        = isset( $record['meta'] ) ? wp_json_encode( $record['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';

		?>
		<form method="post" action="<?php echo esc_url( $save_url ); ?>" class="nv-oos-paper-store__record-form">
			<?php wp_nonce_field( 'paper_save_record_' . $collection, '_wpnonce' ); ?>
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="original_id" value="<?php echo esc_attr( $form_id ); ?>" />
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="paper-record-id"><?php esc_html_e( 'ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit ) : ?>
							<input type="text" id="paper-record-id" class="regular-text" value="<?php echo esc_attr( $form_id ); ?>" disabled />
							<input type="hidden" name="id" value="<?php echo esc_attr( $form_id ); ?>" />
							<p class="description"><?php esc_html_e( 'Record ID cannot be changed after creation.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" id="paper-record-id" name="id" class="regular-text" required
								placeholder="<?php esc_attr_e( 'e.g. dior-sauvage', 'mcp-ai-wpoos-pro' ); ?>"
								pattern="[a-z0-9_-]+" title="<?php esc_attr_e( 'Lowercase letters, numbers, hyphens, and underscores only.', 'mcp-ai-wpoos-pro' ); ?>" />
							<p class="description"><?php esc_html_e( 'Unique identifier. Lowercase letters, numbers, hyphens, and underscores only.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-title"><?php esc_html_e( 'Title', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" id="paper-record-title" name="title" class="regular-text" required
							value="<?php echo esc_attr( $form_title ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-type"><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" id="paper-record-type" name="type" class="regular-text"
							value="<?php echo esc_attr( $form_type ); ?>" />
						<p class="description"><?php esc_html_e( 'Content type. Defaults to the collection name if left empty.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea id="paper-record-description" name="description" class="large-text" rows="2"><?php echo esc_textarea( $form_description ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-status"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select id="paper-record-status" name="status">
							<option value="published" <?php selected( $form_status, 'published' ); ?>><?php esc_html_e( 'Published', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="draft" <?php selected( $form_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="archived" <?php selected( $form_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-tags"><?php esc_html_e( 'Tags', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" id="paper-record-tags" name="tags" class="large-text"
							value="<?php echo esc_attr( $form_tags ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. perfume, dior, men', 'mcp-ai-wpoos-pro' ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated list of tags.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-body"><?php esc_html_e( 'Body (JSON)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea id="paper-record-body" name="body_json" class="large-text code" rows="12"
							placeholder="<?php esc_attr_e( '{"notes": {"top": ["..."]}, ...}', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $form_body ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Valid JSON object for the record body. Leave empty if not needed.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="paper-record-meta"><?php esc_html_e( 'Meta (JSON)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea id="paper-record-meta" name="meta_json" class="large-text code" rows="8"
							placeholder="<?php esc_attr_e( '{"brand": "...", "release_year": 2024}', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $form_meta ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Valid JSON object for custom metadata. Leave empty if not needed.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Update Record', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Create Record', 'mcp-ai-wpoos-pro' ); ?></button>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</form>
		<?php
	}

	// ──────────────────────────────────────────────
	// Record Detail View
	// ──────────────────────────────────────────────

	/**
	 * Render a single record detail view with action buttons.
	 *
	 * @param string $collection Collection name.
	 * @param string $record_id  Record ID.
	 */
	private function render_record_view( string $collection, string $record_id ): void {
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$record  = $repo->find( $record_id );

		$back_url   = $this->admin_page_url( array( 'collection' => $collection ) );
		$edit_url   = $this->admin_page_url(
			array(
				'collection' => $collection,
				'record'     => $record_id,
				'action'     => 'edit',
			)
		);
		$delete_url = $this->admin_page_url(
			array(
				'collection' => $collection,
				'record'     => $record_id,
				'action'     => 'delete',
				'_wpnonce'   => wp_create_nonce( 'paper_delete_record_' . $collection . '_' . $record_id ),
			)
		);

		echo '<p>';
		echo '<a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collection', 'mcp-ai-wpoos-pro' ) . '</a>';
		echo '</p>';

		if ( null === $record ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Record not found.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">';
		echo '<h2 style="margin:0;">' . esc_html( $record['title'] ?? $record_id ) . '</h2>';
		echo '<div>';
		echo '<a href="' . esc_url( $edit_url ) . '" class="button">' . esc_html__( 'Edit', 'mcp-ai-wpoos-pro' ) . '</a> ';
		echo '<a href="' . esc_url( $delete_url ) . '" class="button button-link-delete">' . esc_html__( 'Delete', 'mcp-ai-wpoos-pro' ) . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<table class="form-table">';

		// Render scalar and array fields (skip body and meta — render those specially).
		foreach ( $record as $key => $value ) {
			if ( 'body' === $key || 'meta' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			echo '<tr>';
			echo '<th>' . esc_html( $key ) . '</th>';
			echo '<td>' . esc_html( (string) $value ) . '</td>';
			echo '</tr>';
		}

		// Render body if present.
		if ( isset( $record['body'] ) && ! empty( $record['body'] ) ) {
			echo '<tr><th>' . esc_html__( 'Body', 'mcp-ai-wpoos-pro' ) . '</th><td>';
			echo '<pre class="nv-oos-paper-store__json-preview">' . esc_html( wp_json_encode( $record['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			echo '</td></tr>';
		}

		// Render meta if present.
		if ( isset( $record['meta'] ) && ! empty( $record['meta'] ) ) {
			echo '<tr><th>' . esc_html__( 'Meta', 'mcp-ai-wpoos-pro' ) . '</th><td>';
			echo '<pre class="nv-oos-paper-store__json-preview">' . esc_html( wp_json_encode( $record['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			echo '</td></tr>';
		}

		echo '</table>';
	}

	// ──────────────────────────────────────────────
	// Delete Confirmation Screen
	// ──────────────────────────────────────────────

	/**
	 * Render a delete confirmation screen for a record.
	 *
	 * @param string $collection Collection name.
	 * @param string $record_id  Record ID.
	 */
	private function render_delete_confirmation( string $collection, string $record_id ): void {
		// Verify nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Delete confirmation is a GET with nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'paper_delete_record_' . $collection . '_' . $record_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid or expired security token. Please try again.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$record  = $repo->find( $record_id );

		$back_url = $this->admin_page_url( array( 'collection' => $collection ) );

		if ( null === $record ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Record not found. It may have already been deleted.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			echo '<p><a href="' . esc_url( $back_url ) . '" class="button">&larr; ' . esc_html__( 'Back to collection', 'mcp-ai-wpoos-pro' ) . '</a></p>';
			return;
		}

		$confirm_url = $this->admin_post_url(
			'wp_mcp_ai_paper_delete_record',
			array(
				'collection' => $collection,
				'record'     => $record_id,
			),
			'paper_delete_record_' . $collection . '_' . $record_id
		);

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collection', 'mcp-ai-wpoos-pro' ) . '</a></p>';
		echo '<div class="notice notice-warning" style="padding:1rem;">';
		echo '<p><strong>' . esc_html__( 'Are you sure you want to delete this record?', 'mcp-ai-wpoos-pro' ) . '</strong></p>';
		/* translators: %s: record ID */
		echo '<p>' . esc_html( sprintf( __( 'Record ID: %s', 'mcp-ai-wpoos-pro' ), $record_id ) ) . '</p>';
		/* translators: %s: record title */
		echo '<p>' . esc_html( sprintf( __( 'Title: %s', 'mcp-ai-wpoos-pro' ), $record['title'] ?? '(no title)' ) ) . '</p>';
		echo '<p style="color:#b32d2e;">' . esc_html__( 'This action cannot be undone.', 'mcp-ai-wpoos-pro' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $confirm_url ) . '" style="margin-top:1rem;">';
			wp_nonce_field( 'paper_delete_record_' . $collection . '_' . $record_id, '_wpnonce' );
			echo '<input type="hidden" name="collection" value="' . esc_attr( $collection ) . '" />';
			echo '<input type="hidden" name="record" value="' . esc_attr( $record_id ) . '" />';
			echo '<button type="submit" class="button button-primary" style="background:#b32d2e;border-color:#b32d2e;color:#fff;">' . esc_html__( 'Confirm Delete', 'mcp-ai-wpoos-pro' ) . '</button> ';
		echo '<a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( 'Cancel', 'mcp-ai-wpoos-pro' ) . '</a>';
		echo '</form>';
		echo '</div>';
	}

	// ──────────────────────────────────────────────
	// POST Handlers (admin_post_*)
	// ──────────────────────────────────────────────

	/**
	 * Handle save record (create or update).
	 *
	 * Processes the form submission, validates JSON fields, and saves
	 * the record through the repository. Redirects back with a message.
	 */
	public function handle_save_record(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Paper Store.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- admin_post handler; nonce verified below.
		$collection  = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$original_id = isset( $_POST['original_id'] ) ? sanitize_key( wp_unslash( $_POST['original_id'] ) ) : '';
		// phpcs:enable

		if ( empty( $collection ) ) {
			wp_die( esc_html__( 'Missing collection.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'paper_save_record_' . $collection );

		$is_edit = ! empty( $original_id );

		// Build record data from form fields.
		$record = array(
			'id'          => $is_edit ? $original_id : ( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' ),
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'type'        => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : $collection,
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'status'      => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'published',
		);

		// Validate that published/draft/archived are the only allowed statuses.
		if ( ! in_array( $record['status'], array( 'published', 'draft', 'archived' ), true ) ) {
			$record['status'] = 'published';
		}

		// Parse tags from comma-separated string to array.
		$tags_raw = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		if ( ! empty( $tags_raw ) ) {
			$tags           = array_map( 'trim', explode( ',', $tags_raw ) );
			$tags           = array_filter( $tags );
			$tags           = array_map( 'sanitize_text_field', $tags );
			$record['tags'] = $tags;
		}

		// Parse body JSON.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON decoded below; sanitisation would corrupt the payload.
		$body_json_raw = isset( $_POST['body_json'] ) ? wp_unslash( $_POST['body_json'] ) : '';
		if ( ! empty( trim( $body_json_raw ) ) ) {
			$body = json_decode( $body_json_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$redirect = $this->admin_page_url(
					array(
						'collection' => $collection,
						'message'    => 'invalid_json',
					)
				);
				wp_safe_redirect( $redirect );
				exit;
			}
			$record['body'] = $body;
		}

		// Parse meta JSON.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON decoded below; sanitisation would corrupt the payload.
		$meta_json_raw = isset( $_POST['meta_json'] ) ? wp_unslash( $_POST['meta_json'] ) : '';
		if ( ! empty( trim( $meta_json_raw ) ) ) {
			$meta = json_decode( $meta_json_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$redirect = $this->admin_page_url(
					array(
						'collection' => $collection,
						'message'    => 'invalid_json',
					)
				);
				wp_safe_redirect( $redirect );
				exit;
			}
			$record['meta'] = $meta;
		}

		// Validate required ID field.
		if ( empty( $record['id'] ) ) {
			$redirect = $this->admin_page_url(
				array(
					'collection' => $collection,
					'message'    => 'missing_id',
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Save through repository.
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		if ( $is_edit ) {
			$result = $repo->update( $original_id, $record );
		} else {
			$result = $repo->save( $record );
		}

		$message = is_wp_error( $result ) ? 'error' : 'saved';

		$redirect = $this->admin_page_url(
			array(
				'collection' => $collection,
				'record'     => $record['id'],
				'action'     => 'view',
				'message'    => $message,
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle delete record.
	 *
	 * Verifies the nonce, deletes the record, and redirects back.
	 */
	public function handle_delete_record(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Paper Store.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- admin_post handler; nonce verified below.
		$collection = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : '';
		$record_id  = isset( $_POST['record'] ) ? sanitize_key( wp_unslash( $_POST['record'] ) ) : '';
		// phpcs:enable

		if ( empty( $collection ) || empty( $record_id ) ) {
			wp_die( esc_html__( 'Missing collection or record identifier.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'paper_delete_record_' . $collection . '_' . $record_id );

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$result  = $repo->delete( $record_id );

		$message = is_wp_error( $result ) ? 'not_found' : 'deleted';

		$redirect = $this->admin_page_url(
			array(
				'collection' => $collection,
				'message'    => $message,
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle create collection.
	 *
	 * Creates a new collection directory via get_repository() (which
	 * auto-creates the directory and index). Validates the name.
	 */
	public function handle_create_collection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Paper Store.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'paper_create_collection' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- admin_post handler; nonce verified above.
		$collection_name = isset( $_POST['collection_name'] ) ? sanitize_key( wp_unslash( $_POST['collection_name'] ) ) : '';
		// phpcs:enable

		$message = 'created';

		if ( empty( $collection_name ) ) {
			$message = 'error';
		} else {
			// Creating a repository auto-creates the directory and index.
			$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
			$manager->get_repository( $collection_name );
		}

		$redirect = $this->admin_page_url(
			array(
				'message'    => $message,
				'collection' => $collection_name,
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle delete collection.
	 *
	 * Removes the entire collection directory and its index. This is
	 * destructive — all records in the collection are permanently deleted.
	 */
	public function handle_delete_collection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Paper Store.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin_post handler; nonce verified below.
		$collection = isset( $_REQUEST['collection'] ) ? sanitize_key( wp_unslash( $_REQUEST['collection'] ) ) : '';
		// phpcs:enable

		if ( empty( $collection ) ) {
			wp_die( esc_html__( 'Missing collection name.', 'mcp-ai-wpoos-pro' ) );
		}

		check_admin_referer( 'paper_delete_collection_' . $collection );

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		// Truncate all records, then remove the directory.
		$repo->truncate();

		$root_path      = $manager->get_root_path();
		$collection_dir = trailingslashit( $root_path ) . $collection;

		if ( is_dir( $collection_dir ) ) {
			// Remove all files in the collection directory.
			$files = glob( trailingslashit( $collection_dir ) . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Managed flat-file store directory removal.
			rmdir( $collection_dir );
		}

		// Remove the index file.
		$index_path = trailingslashit( trailingslashit( $root_path ) . '_indexes' ) . $collection . '.idx.json';
		if ( file_exists( $index_path ) ) {
			wp_delete_file( $index_path );
		}

		$redirect = $this->admin_page_url(
			array( 'message' => 'collection_deleted' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}

// ──────────────────────────────────────────────
// Inline Styles (same pattern as Token Manager)
// ──────────────────────────────────────────────

/**
 * Enqueue inline styles for the Paper Store admin page.
 *
 * Hooked at admin_enqueue_scripts, only loads on the Paper Store page.
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		// The hook suffix for submenu pages added via add_submenu_page with
		// a parent of edit.php?post_type=mcp_ai_assistant is
		// mcp_ai_assistant_page_nv-oos-paper-store.
		if ( false === strpos( $hook, 'nv-oos-paper-store' ) ) {
			return;
		}

		$inline_css = '
			.nv-oos-paper-store__stats {
				display: flex;
				gap: 1.5rem;
				margin: 1.5rem 0;
				flex-wrap: wrap;
			}
			.nv-oos-paper-store__stat {
				padding: 1rem;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				flex: 1;
				min-width: 120px;
			}
			.nv-oos-paper-store__stat-label {
				font-size: 0.875rem;
				color: #646970;
				margin-bottom: 0.25rem;
			}
			.nv-oos-paper-store__stat-value {
				font-size: 1.75rem;
				font-weight: 600;
				color: #1d2327;
			}
			.nv-oos-paper-store__stat-value--success {
				color: #0a5f1a;
			}
			.nv-oos-paper-store__stat-value--warning {
				color: #8b6c00;
			}
			.nv-oos-paper-store__actions {
				white-space: nowrap;
			}
			.nv-oos-paper-store__actions .button {
				margin-right: 0.25rem;
			}
			.nv-oos-paper-store__actions .button:last-child {
				margin-right: 0;
			}
			.nv-oos-paper-store__status {
				display: inline-block;
				padding: 0.25rem 0.5rem;
				border-radius: 3px;
				font-size: 0.75rem;
				font-weight: 600;
				text-transform: capitalize;
			}
			.nv-oos-paper-store__status--published {
				background: #d5f0db;
				color: #0a5f1a;
			}
			.nv-oos-paper-store__status--draft {
				background: #fef7e0;
				color: #8b6c00;
			}
			.nv-oos-paper-store__json-preview {
				max-height: 400px;
				overflow: auto;
				background: #f6f7f7;
				padding: 1rem;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				font-size: 0.8125rem;
				line-height: 1.5;
				margin: 0;
			}
			.nv-oos-paper-store__record-form .form-table th {
				width: 140px;
			}
			.nv-oos-paper-store__create-collection summary {
				color: #2271b1;
			}
			.nv-oos-paper-store__create-collection summary:hover {
				color: #135e96;
			}
			@media screen and (max-width: 782px) {
				.nv-oos-paper-store__stats {
					flex-direction: column;
					gap: 1rem;
				}
				.nv-oos-paper-store__stat {
					min-width: auto;
				}
				.nv-oos-paper-store__actions .button {
					margin-bottom: 0.25rem;
				}
			}
		';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline-only style registration.
		wp_register_style( 'nv-oos-paper-store-inline', false );
		wp_enqueue_style( 'nv-oos-paper-store-inline' );
		wp_add_inline_style( 'nv-oos-paper-store-inline', $inline_css );
	}
);
