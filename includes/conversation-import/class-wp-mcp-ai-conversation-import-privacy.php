<?php
/**
 * Privacy integration for imported conversations.
 *
 * Registers a WordPress personal-data exporter and eraser covering
 * conversation rows written by the import pipeline (`session_key` prefixed
 * with `import-`). Complements the generic transcript exporter/eraser in
 * `WP_MCP_AI_Privacy` with source-specific fields (platform, source title,
 * source IDs, import provenance).
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR exporter/eraser for imported conversation data.
 */
class WP_MCP_AI_Conversation_Import_Privacy {

	/**
	 * Register privacy filters.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the imported-conversations exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['wp-mcp-ai-imported-conversations'] = array(
			'exporter_friendly_name' => __( 'NV oOS Imported AI Conversations', 'mcp-ai-wpoos' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the imported-conversations eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['wp-mcp-ai-imported-conversations'] = array(
			'eraser_friendly_name' => __( 'NV oOS Imported AI Conversations', 'mcp-ai-wpoos' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Suggest privacy policy content for imported conversations.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = __(
			'<h3>Imported AI Conversations</h3>
<p>When you import conversation history from external AI services (for example OpenAI ChatGPT or Google Gemini) into this website, the imported messages, conversation titles, source identifiers, and timestamps are stored on our server. Imported conversations are visible to site administrators only and are deleted when you request erasure of your personal data or when the site\'s transcript retention policy expires.</p>',
			'mcp-ai-wpoos'
		);

		wp_add_privacy_policy_content( 'NV Digital Open Operator System (NV oOS)', wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Export imported conversations for a user.
	 *
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function export( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress privacy callback signature.
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = self::query_imported_rows( $user->ID, 100 );
		$data = array();

		foreach ( $rows as $row ) {
			$metadata = json_decode( isset( $row['metadata'] ) ? $row['metadata'] : '', true );
			$platform = '';
			$title    = '';
			$source   = '';
			$model    = '';

			if ( is_array( $metadata ) && isset( $metadata['import'] ) && is_array( $metadata['import'] ) ) {
				$platform = isset( $metadata['import']['platform'] ) ? sanitize_text_field( (string) $metadata['import']['platform'] ) : '';
				$title    = isset( $metadata['import']['source_title'] ) ? sanitize_text_field( (string) $metadata['import']['source_title'] ) : '';
				$source   = isset( $metadata['import']['source_id'] ) ? sanitize_text_field( (string) $metadata['import']['source_id'] ) : '';
				$model    = isset( $metadata['import']['model'] ) ? sanitize_text_field( (string) $metadata['import']['model'] ) : '';
			}

			$messages = isset( $row['request_payload'] ) ? $row['request_payload'] : '';

			$data[] = array(
				'group_id'    => 'wp-mcp-ai-imported-conversations',
				'group_label' => __( 'Imported AI Conversations', 'mcp-ai-wpoos' ),
				'item_id'     => 'imported-conversation-' . ( isset( $row['_ID'] ) ? $row['_ID'] : uniqid() ),
				'data'        => array(
					array(
						'name'  => __( 'Platform', 'mcp-ai-wpoos' ),
						'value' => $platform,
					),
					array(
						'name'  => __( 'Title', 'mcp-ai-wpoos' ),
						'value' => $title,
					),
					array(
						'name'  => __( 'Source Conversation ID', 'mcp-ai-wpoos' ),
						'value' => $source,
					),
					array(
						'name'  => __( 'Model', 'mcp-ai-wpoos' ),
						'value' => $model,
					),
					array(
						'name'  => __( 'Imported At', 'mcp-ai-wpoos' ),
						'value' => isset( $row['cct_created'] ) ? $row['cct_created'] : '',
					),
					array(
						'name'  => __( 'Messages', 'mcp-ai-wpoos' ),
						'value' => wp_trim_words( wp_strip_all_tags( $messages ), 100 ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase imported conversations for a user.
	 *
	 * @param string $email_address User email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress privacy callback signature.
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$rows = self::query_imported_rows( $user->ID, 1000 );
		if ( empty( $rows ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();
		$deleted = 0;
		$failed  = 0;

		foreach ( $rows as $row ) {
			if ( empty( $row['session_key'] ) ) {
				continue;
			}
			if ( $deleter->delete_by_session_key( $row['session_key'] ) ) {
				++$deleted;
			} else {
				++$failed;
			}
		}

		$messages = array();
		if ( $deleted > 0 ) {
			/* translators: %d: number of imported conversations deleted. */
			$messages[] = sprintf( __( 'Deleted %d imported AI conversations.', 'mcp-ai-wpoos' ), $deleted );
		}
		if ( $failed > 0 ) {
			/* translators: %d: number of imported conversations retained. */
			$messages[] = sprintf( __( '%d imported AI conversations could not be deleted and were retained.', 'mcp-ai-wpoos' ), $failed );
		}

		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => $failed > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Query imported conversation rows for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Max rows.
	 * @return array[] Rows as associative arrays.
	 */
	protected static function query_imported_rows( $user_id, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ( ! function_exists( 'jet_engine' ) && ! class_exists( 'Jet_Engine' ) ) ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . WP_MCP_AI_JetEngine_CCT::SLUG;
		$like  = $wpdb->esc_like( 'import-' ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name derives from the plugin-owned CCT slug; values fully prepared. CCT rows have no WP query-cache group.
		$sql  = $wpdb->prepare(
			"SELECT `_ID`, `session_key`, `metadata`, `request_payload`, `cct_created` FROM {$table} WHERE `session_key` LIKE %s AND `cct_author_id` = %d ORDER BY `_ID` DESC LIMIT %d",
			$like,
			absint( $user_id ),
			absint( $limit )
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return is_array( $rows ) ? $rows : array();
	}
}

WP_MCP_AI_Conversation_Import_Privacy::bootstrap();
