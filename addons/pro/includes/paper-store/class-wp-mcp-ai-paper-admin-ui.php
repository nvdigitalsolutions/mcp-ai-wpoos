<?php
/**
 * Paper Store Admin UI — Pro WordPress admin page for browsing collections.
 *
 * Registers a submenu under NV oOS admin area with collection list and record browser.
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
 * Singleton. Call init() to register the admin page.
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
	 */
	public static function init(): void {
		$instance = self::get_instance();
		add_action( 'admin_menu', array( $instance, 'register_admin_page' ), 30 );
	}

	/**
	 * Register the admin submenu page.
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

	/**
	 * Render the Paper Store admin page.
	 */
	public function render_page(): void {
		$manager     = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$collections = $manager->list_collections();

		$selected_collection = isset( $_GET['collection'] ) ? sanitize_key( wp_unslash( $_GET['collection'] ) ) : '';
		$selected_record     = isset( $_GET['record'] ) ? sanitize_key( wp_unslash( $_GET['record'] ) ) : '';
		$action              = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'NVoOS Paper Store', 'mcp-ai-wpoos-pro' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Browse and manage flat-file records stored in the Paper Store.', 'mcp-ai-wpoos-pro' ) . '</p>';

		if ( 'view' === $action && ! empty( $selected_collection ) && ! empty( $selected_record ) ) {
			$this->render_record_view( $selected_collection, $selected_record );
		} elseif ( ! empty( $selected_collection ) ) {
			$this->render_collection_view( $selected_collection );
		} else {
			$this->render_collections_list( $collections );
		}

		echo '</div>';
	}

	/**
	 * Render the collections list.
	 *
	 * @param string[] $collections Collection names.
	 */
	private function render_collections_list( array $collections ): void {
		if ( empty( $collections ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No collections exist yet. Use the Paper Store tools to create records, and collections will appear here automatically.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Collection', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Records', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $collections as $collection ) {
			$repo  = $manager->get_repository( $collection );
			$count = $repo->count();

			$view_url = add_query_arg(
				array(
					'page'       => $this->page_slug,
					'collection' => $collection,
				),
				admin_url( 'edit.php?post_type=mcp_ai_assistant' )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $collection ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $count ) . '</td>';
			echo '<td><a href="' . esc_url( $view_url ) . '" class="button button-small">' . esc_html__( 'Browse', 'mcp-ai-wpoos-pro' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a single collection's records.
	 *
	 * @param string $collection Collection name.
	 */
	private function render_collection_view( string $collection ): void {
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$records = $repo->all();

		$back_url = add_query_arg(
			array( 'page' => $this->page_slug ),
			admin_url( 'edit.php?post_type=mcp_ai_assistant' )
		);

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collections', 'mcp-ai-wpoos-pro' ) . '</a></p>';
		echo '<h2>' . esc_html( sprintf( __( 'Collection: %s', 'mcp-ai-wpoos-pro' ), $collection ) ) . '</h2>';

		if ( empty( $records ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No records in this collection.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'mcp-ai-wpoos-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$view_url = add_query_arg(
				array(
					'page'       => $this->page_slug,
					'collection' => $collection,
					'record'     => $record['id'],
					'action'     => 'view',
				),
				admin_url( 'edit.php?post_type=mcp_ai_assistant' )
			);

			$updated = isset( $record['updated_at'] ) ? $record['updated_at'] : '';

			echo '<tr>';
			echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html( $record['id'] ) . '</a></td>';
			echo '<td>' . esc_html( $record['title'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $record['status'] ?? 'published' ) . '</td>';
			echo '<td>' . esc_html( $updated ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a single record view.
	 *
	 * @param string $collection Collection name.
	 * @param string $record_id  Record ID.
	 */
	private function render_record_view( string $collection, string $record_id ): void {
		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$record  = $repo->find( $record_id );

		$back_url = add_query_arg(
			array(
				'page'       => $this->page_slug,
				'collection' => $collection,
			),
			admin_url( 'edit.php?post_type=mcp_ai_assistant' )
		);

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to collection', 'mcp-ai-wpoos-pro' ) . '</a></p>';

		if ( null === $record ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Record not found.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
			return;
		}

		echo '<h2>' . esc_html( $record['title'] ?? $record_id ) . '</h2>';

		echo '<table class="form-table">';
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
		if ( isset( $record['body'] ) ) {
			echo '<tr><th>' . esc_html__( 'Body', 'mcp-ai-wpoos-pro' ) . '</th><td>';
			if ( isset( $record['body']['markdown'] ) ) {
				echo '<pre style="max-height:400px;overflow:auto;">' . esc_html( $record['body']['markdown'] ) . '</pre>';
			} else {
				echo '<pre style="max-height:400px;overflow:auto;">' . esc_html( wp_json_encode( $record['body'], JSON_PRETTY_PRINT ) ) . '</pre>';
			}
			echo '</td></tr>';
		}

		echo '</table>';
	}
}
