<?php
/**
 * Privacy integration for imported conversations (Wave E4,
 * sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Privacy`: byte-identical exporter
 * and eraser registration under the
 * `wp-mcp-ai-imported-conversations` key, the privacy policy content
 * suggestion (admin-only, WP 6.9+ safe), the per-user
 * `query_imported_rows()` lookup against the `ai_chat_transcripts`
 * CCT scoped to `import-` session keys, the export field mapping
 * (platform, title, source ID, model, imported-at, trimmed messages),
 * and the deleter-backed erasure flow.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The base's file-level self-bootstrap becomes an explicit
 *    `bootstrap()` call, wired standalone-only via
 *    `Plugin::registerConversationImport()` — the base loader owns the
 *    same hooks monolith.
 *  - `query_imported_rows()` resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the transcript CCT
 *    only exists monolith, so standalone installs report no rows
 *    instead of touching classmapped base classes whose runtime would
 *    fatal.
 *  - The eraser instantiates this package's `Deleter` (which carries
 *    the same per-mode JetEngine seam).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * GDPR exporter/eraser for imported conversation data.
 *
 * @since 2.1.0
 */
class Privacy {

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
			'exporter_friendly_name' => __( 'NV oOS Imported AI Conversations', 'nvoos-content-graph-ai-platform' ),
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
			'eraser_friendly_name' => __( 'NV oOS Imported AI Conversations', 'nvoos-content-graph-ai-platform' ),
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

		// WP 6.9+ flags registrations outside wp-admin as incorrect usage
		// (and the PHPUnit context is never wp-admin). Privacy guide content
		// only ever renders in the admin, so skip gracefully elsewhere.
		if ( ! is_admin() ) {
			return;
		}

		$content = __(
			'<h3>Imported AI Conversations</h3>
<p>When you import conversation history from external AI services (for example OpenAI ChatGPT or Google Gemini) into this website, the imported messages, conversation titles, source identifiers, and timestamps are stored on our server. Imported conversations are visible to site administrators only and are deleted when you request erasure of your personal data or when the site\'s transcript retention policy expires.</p>',
			'nvoos-content-graph-ai-platform'
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
				'group_label' => __( 'Imported AI Conversations', 'nvoos-content-graph-ai-platform' ),
				'item_id'     => 'imported-conversation-' . ( isset( $row['_ID'] ) ? $row['_ID'] : uniqid() ),
				'data'        => array(
					array(
						'name'  => __( 'Platform', 'nvoos-content-graph-ai-platform' ),
						'value' => $platform,
					),
					array(
						'name'  => __( 'Title', 'nvoos-content-graph-ai-platform' ),
						'value' => $title,
					),
					array(
						'name'  => __( 'Source Conversation ID', 'nvoos-content-graph-ai-platform' ),
						'value' => $source,
					),
					array(
						'name'  => __( 'Model', 'nvoos-content-graph-ai-platform' ),
						'value' => $model,
					),
					array(
						'name'  => __( 'Imported At', 'nvoos-content-graph-ai-platform' ),
						'value' => isset( $row['cct_created'] ) ? $row['cct_created'] : '',
					),
					array(
						'name'  => __( 'Messages', 'nvoos-content-graph-ai-platform' ),
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

		$deleter = new Deleter();
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
			$messages[] = sprintf( __( 'Deleted %d imported AI conversations.', 'nvoos-content-graph-ai-platform' ), $deleted );
		}
		if ( $failed > 0 ) {
			/* translators: %d: number of imported conversations retained. */
			$messages[] = sprintf( __( '%d imported AI conversations could not be deleted and were retained.', 'nvoos-content-graph-ai-platform' ), $failed );
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
	 * Monolith-only: the transcript CCT does not exist standalone, so the
	 * exporter/eraser report no rows there (documented degradation — the
	 * rows simply cannot exist on such an install).
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Max rows.
	 * @return array[] Rows as associative arrays.
	 */
	protected static function query_imported_rows( $user_id, $limit ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return array();
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) || ( ! function_exists( 'jet_engine' ) && ! class_exists( 'Jet_Engine' ) ) ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . \WP_MCP_AI_JetEngine_CCT::SLUG;
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
