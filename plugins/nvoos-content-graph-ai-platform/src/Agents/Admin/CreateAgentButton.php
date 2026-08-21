<?php
/**
 * Create Agent Button — admin UI for the Build AI Agent button.
 *
 * Extracted from `includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php`.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents\Admin;

use NvoosContentGraphAiPlatform\Agents\Agents;

/**
 * Adds a "Build AI Agent" button to the agent post type page.
 * Also handles AJAX requests for creating agents.
 */
final class CreateAgentButton {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_filter( 'views_edit-' . Agents::POST_TYPE, array( $this, 'addCreateButton' ) );
		add_action( 'wp_ajax_nvoos_content_graph_platform_create_agent_modal', array( $this, 'handleAjaxCreate' ) );
		add_action( 'wp_ajax_nvoos_content_graph_platform_build_agent_conversation', array( $this, 'handleAjaxBuildFromConversation' ) );
		add_action( 'wp_ajax_nvoos_content_graph_platform_upload_agent_attachment', array( $this, 'handleAjaxUploadAttachment' ) );
	}

	/**
	 * Enqueue scripts and styles for the create agent button.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		// Only load on the agent list page.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'edit.php' !== $hook || ! isset( $_GET['post_type'] ) || Agents::POST_TYPE !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_enqueue_style(
			'nvoos-content-graph-ai-platform-agents-create-button',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/css/agents-create-button.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION
		);

		wp_enqueue_script(
			'nvoos-content-graph-ai-platform-agents-create-button',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/js/agents-create-button.js',
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-content-graph-ai-platform-agents-create-button',
			'nvoosContentGraphPlatformCreateAgentButton',
			array(
				'buildUrl'   => admin_url( 'edit.php?post_type=' . Agents::POST_TYPE . '&page=wp-mcp-ai-build-assistant' ),
				'buttonText' => __( 'Build AI Agent', 'nvoos-content-graph-ai-platform' ),
			)
		);
	}

	/**
	 * Add create agent button to the post type page.
	 *
	 * @param array<string, string> $views Views array.
	 * @return array<string, string> Modified views.
	 */
	public function addCreateButton( array $views ): array {
		return $views;
	}

	/**
	 * Handle AJAX request to create agent.
	 *
	 * @return void
	 */
	public function handleAjaxCreate(): void {
		check_ajax_referer( 'nvoos_content_graph_platform_create_agent', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$title          = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$professions    = isset( $_POST['professions'] ) && is_array( $_POST['professions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['professions'] ) ) : array();
		$regions        = isset( $_POST['regions'] ) && is_array( $_POST['regions'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['regions'] ) ) : array();
		$industry_focus = isset( $_POST['industry_focus'] ) ? sanitize_text_field( wp_unslash( $_POST['industry_focus'] ) ) : '';
		$provider       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'openai';
		$model          = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4';
		$temperature    = isset( $_POST['temperature'] ) ? floatval( wp_unslash( $_POST['temperature'] ) ) : 0.7;
		$async          = isset( $_POST['async'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['async'] ) );

		if ( empty( $title ) || empty( $professions ) || empty( $regions ) ) {
			wp_send_json_error( array( 'message' => __( 'Title, professions, and regions are required.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// Use the create_assistant tool from the base plugin's tool registry.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tool registry not available.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		if ( ! $tool ) {
			wp_send_json_error( array( 'message' => __( 'Create agent tool not available.', 'nvoos-content-graph-ai-platform' ) ) );
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
	 * Handle AJAX request to build agent from conversation.
	 *
	 * @return void
	 */
	public function handleAjaxBuildFromConversation(): void {
		check_ajax_referer( 'nvoos_content_graph_platform_create_agent', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$conversation_json = isset( $_POST['conversation'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation'] ) ) : '';
		$attachment_ids    = isset( $_POST['attachment_ids'] ) && is_array( $_POST['attachment_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['attachment_ids'] ) ) : array();

		if ( empty( $conversation_json ) ) {
			wp_send_json_error( array( 'message' => __( 'No conversation data provided.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$conversation = json_decode( $conversation_json, true );

		if ( ! is_array( $conversation ) || empty( $conversation['messages'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid conversation data.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$assistant_config = self::extractConfigFromConversation( $conversation['messages'] );

		if ( empty( $assistant_config['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine a title for the agent. Please describe what you want the agent to be called.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			wp_send_json_error( array( 'message' => __( 'Tool registry not available.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		if ( ! $tool ) {
			wp_send_json_error( array( 'message' => __( 'Create agent tool not available.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$arguments = array(
			'title'          => $assistant_config['title'],
			'description'    => $assistant_config['description'],
			'system_prompt'  => isset( $assistant_config['system_prompt'] ) ? $assistant_config['system_prompt'] : '',
			'attachment_ids' => $attachment_ids,
		);

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
	 * Extract agent configuration from conversation messages.
	 *
	 * @param array<int, array<string, string>> $messages Conversation messages.
	 * @return array<string, mixed> Agent configuration.
	 */
	private static function extractConfigFromConversation( array $messages ): array {
		$config = array(
			'title'          => '',
			'description'    => '',
			'system_prompt'  => '',
			'professions'    => array(),
			'regions'        => array(),
			'industry_focus' => '',
		);

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

		$config['description'] = implode( "\n\n", $user_messages );

		$full_text = implode( ' ', $user_messages );

		$title_patterns = array(
			'/(?:create|build|make)\s+(?:an?\s+)?(?:ai\s+)?agent\s+(?:called|named)\s+["\']?([^"\']+)["\']?/i',
			'/(?:create|build|make)\s+(?:an?\s+)?(?:ai\s+)?assistant\s+(?:called|named)\s+["\']?([^"\']+)["\']?/i',
			'/(?:called|named)\s+["\']?([^"\'\.]+)["\']?/i',
			'/(?:name|title)(?:\s+it)?\s+["\']?([^"\'\.]+)["\']?/i',
		);

		foreach ( $title_patterns as $pattern ) {
			if ( preg_match( $pattern, $full_text, $matches ) ) {
				$extracted_title = trim( $matches[1] );
				$extracted_title = sanitize_text_field( $extracted_title );
				$extracted_title = (string) preg_replace( '/[^\w\s\-]/', '', $extracted_title );
				$extracted_title = trim( $extracted_title );
				$config['title'] = mb_substr( $extracted_title, 0, 200 );
				break;
			}
		}

		if ( empty( $config['title'] ) && ! empty( $config['description'] ) ) {
			$description_words = explode( ' ', $config['description'] );
			$title_words       = array_slice( $description_words, 0, 5 );
			$config['title']   = sanitize_text_field( ucwords( implode( ' ', $title_words ) ) . ' Agent' );
		}

		if ( ! empty( $assistant_messages ) ) {
			$last_assistant_message = end( $assistant_messages );

			if ( preg_match( '/you are|your role|you will|your purpose/i', $last_assistant_message ) ) {
				$config['system_prompt'] = $last_assistant_message;
			}
		}

		return $config;
	}

	/**
	 * Handle AJAX request to upload an attachment for the agent.
	 *
	 * @return void
	 */
	public function handleAjaxUploadAttachment(): void {
		check_ajax_referer( 'nvoos_content_graph_platform_create_agent', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to upload files.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$allowed_types = array( 'txt', 'md', 'pdf', 'doc', 'docx' );
		$file_name     = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '';
		$file_ext      = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file type. Allowed types: txt, md, pdf, doc, docx.', 'nvoos-content-graph-ai-platform' ) ) );
		}

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
