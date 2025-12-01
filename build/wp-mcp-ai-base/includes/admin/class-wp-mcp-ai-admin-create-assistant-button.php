<?php
/**
 * Admin UI for Create Assistant button.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Build AI Assistant" button to the assistant post type page that links to the Build Assistant page.
 * Also handles AJAX requests for creating assistants.
 */
class WP_MCP_AI_Admin_Create_Assistant_Button {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'views_edit-mcp_ai_assistant', array( __CLASS__, 'add_create_button' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_assistant_from_modal', array( __CLASS__, 'handle_ajax_create' ) );
		add_action( 'wp_ajax_wp_mcp_ai_build_assistant_from_conversation', array( __CLASS__, 'handle_ajax_build_from_conversation' ) );
		add_action( 'wp_ajax_wp_mcp_ai_upload_assistant_attachment', array( __CLASS__, 'handle_ajax_upload_attachment' ) );
	}

	/**
	 * Add create assistant button to the post type page.
	 *
	 * @param array $views Views array.
	 * @return array Modified views.
	 */
	public static function add_create_button( $views ) {
		$build_assistant_url = admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-build-assistant' );
		?>
		<style>
			.wp-mcp-ai-create-assistant-btn {
				margin-left: 10px;
				vertical-align: middle;
			}
		</style>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Add link button after the page title that navigates to the Build Assistant page
				var button = '<a href="<?php echo esc_url( $build_assistant_url ); ?>" class="page-title-action wp-mcp-ai-create-assistant-btn"><?php echo esc_js( __( 'Build AI Assistant', 'wp-mcp-ai' ) ); ?></a>';
				$('.wrap h1.wp-heading-inline').after(button);
			});
		</script>
		<?php
		return $views;
	}

	/**
	 * Handle AJAX request to create assistant.
	 */
	public static function handle_ajax_create() {
		check_ajax_referer( 'wp_mcp_ai_create_assistant', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get form data.
		$title          = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$professions    = isset( $_POST['professions'] ) && is_array( $_POST['professions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['professions'] ) ) : array();
		$regions        = isset( $_POST['regions'] ) && is_array( $_POST['regions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['regions'] ) ) : array();
		$industry_focus = isset( $_POST['industry_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['industry_focus'] ) ) : '';
		$provider       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'openai';
		$model          = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4';
		$temperature    = isset( $_POST['temperature'] ) ? floatval( $_POST['temperature'] ) : 0.7;
		$async          = isset( $_POST['async'] ) && '1' === $_POST['async'];

		// Validate required fields.
		if ( empty( $title ) || empty( $professions ) || empty( $regions ) ) {
			wp_send_json_error( array( 'message' => __( 'Title, professions, and regions are required.', 'wp-mcp-ai' ) ) );
		}

		// Use the create_assistant tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		if ( ! $tool ) {
			wp_send_json_error( array( 'message' => __( 'Create assistant tool not available.', 'wp-mcp-ai' ) ) );
		}

		$arguments = array(
			'title'          => $title,
			'professions'    => $professions,
			'regions'        => $regions,
			'industry_focus' => $industry_focus,
			'provider'       => $provider,
			'model'          => $model,
			'temperature'    => $temperature,
			'async'          => $async,
		);

		$context = array(
			'user_id' => get_current_user_id(),
		);

		$result = $tool->execute( $arguments, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX request to build assistant from conversation.
	 */
	public static function handle_ajax_build_from_conversation() {
		check_ajax_referer( 'wp_mcp_ai_create_assistant', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get conversation data.
		$conversation_json = isset( $_POST['conversation'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation'] ) ) : '';
		$attachment_ids    = isset( $_POST['attachment_ids'] ) && is_array( $_POST['attachment_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['attachment_ids'] ) ) : array();

		if ( empty( $conversation_json ) ) {
			wp_send_json_error( array( 'message' => __( 'No conversation data provided.', 'wp-mcp-ai' ) ) );
		}

		$conversation = json_decode( $conversation_json, true );

		if ( ! is_array( $conversation ) || empty( $conversation['messages'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid conversation data.', 'wp-mcp-ai' ) ) );
		}

		// Extract assistant details from conversation.
		$assistant_config = self::extract_assistant_config_from_conversation( $conversation['messages'] );

		if ( empty( $assistant_config['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine a title for the assistant. Please describe what you want the assistant to be called.', 'wp-mcp-ai' ) ) );
		}

		// Use the create_assistant tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		if ( ! $tool ) {
			wp_send_json_error( array( 'message' => __( 'Create assistant tool not available.', 'wp-mcp-ai' ) ) );
		}

		$arguments = array(
			'title'          => $assistant_config['title'],
			'description'    => $assistant_config['description'],
			'system_prompt'  => isset( $assistant_config['system_prompt'] ) ? $assistant_config['system_prompt'] : '',
			'attachment_ids' => $attachment_ids,
		);

		// Add inferred professions/regions if available.
		if ( ! empty( $assistant_config['professions'] ) ) {
			$arguments['professions'] = $assistant_config['professions'];
		}
		if ( ! empty( $assistant_config['regions'] ) ) {
			$arguments['regions'] = $assistant_config['regions'];
		}
		if ( ! empty( $assistant_config['industry_focus'] ) ) {
			$arguments['industry_focus'] = $assistant_config['industry_focus'];
		}

		$context = array(
			'user_id' => get_current_user_id(),
		);

		$result = $tool->execute( $arguments, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Extract assistant configuration from conversation messages.
	 *
	 * @param array $messages Conversation messages.
	 * @return array Assistant configuration.
	 */
	protected static function extract_assistant_config_from_conversation( $messages ) {
		$config = array(
			'title'          => '',
			'description'    => '',
			'system_prompt'  => '',
			'professions'    => array(),
			'regions'        => array(),
			'industry_focus' => '',
		);

		// Collect all user messages to build the description.
		$user_messages      = array();
		$assistant_messages = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}

			$content = sanitize_textarea_field( $message['content'] );

			if ( 'user' === $message['role'] ) {
				$user_messages[] = $content;
			} elseif ( 'assistant' === $message['role'] ) {
				$assistant_messages[] = $content;
			}
		}

		// Build description from user messages.
		$config['description'] = implode( "\n\n", $user_messages );

		// Try to extract a title from the conversation.
		// Look for patterns like "create an assistant called X" or "named X".
		$full_text = implode( ' ', $user_messages );

		// Pattern matching for title extraction.
		$title_patterns = array(
			'/(?:create|build|make)\s+(?:an?\s+)?(?:ai\s+)?assistant\s+(?:called|named)\s+["\']?([^"\']+)["\']?/i',
			'/(?:called|named)\s+["\']?([^"\'\.]+)["\']?/i',
			'/(?:name|title)(?:\s+it)?\s+["\']?([^"\'\.]+)["\']?/i',
		);

		foreach ( $title_patterns as $pattern ) {
			if ( preg_match( $pattern, $full_text, $matches ) ) {
				$extracted_title = trim( $matches[1] );
				// Sanitize the extracted title to ensure it's suitable for a post title.
				// Remove any remaining special characters, limit length, and sanitize.
				$extracted_title     = sanitize_text_field( $extracted_title );
				$extracted_title     = preg_replace( '/[^\w\s\-]/', '', $extracted_title );
				$extracted_title     = trim( $extracted_title );
				$config['title']     = mb_substr( $extracted_title, 0, 200 ); // Limit to 200 chars.
				break;
			}
		}

		// Fallback: Generate a title from the description.
		if ( empty( $config['title'] ) && ! empty( $config['description'] ) ) {
			$description_words = explode( ' ', $config['description'] );
			$title_words       = array_slice( $description_words, 0, 5 );
			$config['title']   = sanitize_text_field( ucwords( implode( ' ', $title_words ) ) . ' Assistant' );
		}

		// If we have assistant responses, the last one might contain a system prompt suggestion.
		if ( ! empty( $assistant_messages ) ) {
			$last_assistant_message = end( $assistant_messages );

			// Check if it looks like a system prompt (contains role definitions).
			if ( preg_match( '/you are|your role|you will|your purpose/i', $last_assistant_message ) ) {
				$config['system_prompt'] = $last_assistant_message;
			}
		}

		return $config;
	}

	/**
	 * Handle AJAX request to upload an attachment for the assistant.
	 */
	public static function handle_ajax_upload_attachment() {
		check_ajax_referer( 'wp_mcp_ai_create_assistant', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to upload files.', 'wp-mcp-ai' ) ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided.', 'wp-mcp-ai' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Validate file type.
		$allowed_types = array( 'txt', 'md', 'pdf', 'doc', 'docx' );
		$file_name     = isset( $_FILES['file']['name'] ) ? sanitize_file_name( $_FILES['file']['name'] ) : '';
		$file_ext      = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type. Allowed types: txt, md, pdf, doc, docx.', 'wp-mcp-ai' ) ) );
		}

		// Upload the file.
		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'attachment_id' => $attachment_id,
				'filename'      => $file_name,
			)
		);
	}
}
