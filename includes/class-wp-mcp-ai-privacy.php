<?php
/**
 * Privacy API Integration for GDPR Compliance
 *
 * Implements WordPress Privacy API for personal data export and erasure.
 * Handles chat transcripts, assistant credentials, user settings, and usage data.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy API Integration Class
 *
 * Integrates with WordPress Privacy Tools to provide GDPR-compliant
 * data export and erasure functionality for NV oOS plugin data.
 */
class WP_MCP_AI_Privacy {

	/**
	 * Initialize privacy integration
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function register_hooks() {
		// Register privacy policy content.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// Register personal data exporters.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );

		// Register personal data erasers.
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Add privacy policy content suggestion
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = $this->get_privacy_policy_content();

		wp_add_privacy_policy_content(
			'NV Digital Open Operator System (NV oOS)',
			wp_kses_post( wpautop( $content, false ) )
		);
	}

	/**
	 * Get privacy policy content
	 *
	 * @return string Privacy policy content
	 */
	private function get_privacy_policy_content() {
		return __(
			'<h2>What Personal Data We Collect and Why</h2>
			
			<h3>AI Assistant Interactions</h3>
			<p>When you use our AI Assistant features, we collect:</p>
			<ul>
				<li><strong>Chat Transcripts:</strong> Messages you send to AI assistants are stored locally in your browser for 24 hours and optionally on our server if enabled.</li>
				<li><strong>Usage Analytics:</strong> We track which assistants and tools you use to improve performance and user experience.</li>
				<li><strong>Assistant Credentials:</strong> If you create custom AI assistants, we store associated API credentials securely.</li>
			</ul>
			
			<h3>User Settings and Preferences</h3>
			<p>We store your AI assistant preferences, including:</p>
			<ul>
				<li>Selected AI models and providers</li>
				<li>Custom system prompts and configurations</li>
				<li>Tool selections and permissions</li>
			</ul>
			
			<h3>How Long We Retain Your Data</h3>
			<ul>
				<li><strong>Chat Transcripts:</strong> Browser storage for 24 hours; server storage (if enabled) until manually deleted or account deletion.</li>
				<li><strong>Usage Analytics:</strong> Aggregated data retained for 90 days; individual records for 30 days.</li>
				<li><strong>API Credentials:</strong> Retained until you delete them or your account is deleted.</li>
				<li><strong>User Settings:</strong> Retained until you delete them or your account is deleted.</li>
			</ul>
			
			<h3>Where We Send Your Data</h3>
			<p>Your chat messages and data may be sent to third-party AI providers you select:</p>
			<ul>
				<li><strong>OpenAI:</strong> If using OpenAI GPT models</li>
				<li><strong>Google:</strong> If using Google Gemini models</li>
				<li><strong>Anthropic:</strong> If using Claude models</li>
				<li><strong>Local AI (Ollama):</strong> Data stays on your server if using local models</li>
			</ul>
			<p>Please review the privacy policies of these providers for information on how they handle your data.</p>
			
			<h3>Your Data Rights</h3>
			<p>You have the right to:</p>
			<ul>
				<li>Export all personal data we have collected</li>
				<li>Request erasure of your personal data</li>
				<li>Object to processing of your personal data</li>
				<li>Restrict processing of your personal data</li>
			</ul>
			<p>You can exercise these rights using the Privacy Tools in your WordPress admin area or by contacting the site administrator.</p>',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Register personal data exporters
	 *
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters array
	 */
	public function register_exporters( $exporters ) {
		$exporters['wp-mcp-ai-chat-transcripts'] = array(
			'exporter_friendly_name' => __( 'NV oOS Chat Transcripts', 'mcp-ai-wpoos' ),
			'callback'               => array( $this, 'export_chat_transcripts' ),
		);

		$exporters['wp-mcp-ai-assistant-credentials'] = array(
			'exporter_friendly_name' => __( 'NV oOS Assistant Credentials', 'mcp-ai-wpoos' ),
			'callback'               => array( $this, 'export_assistant_credentials' ),
		);

		$exporters['wp-mcp-ai-user-settings'] = array(
			'exporter_friendly_name' => __( 'NV oOS User Settings', 'mcp-ai-wpoos' ),
			'callback'               => array( $this, 'export_user_settings' ),
		);

		$exporters['wp-mcp-ai-usage-analytics'] = array(
			'exporter_friendly_name' => __( 'NV oOS Usage Analytics', 'mcp-ai-wpoos' ),
			'callback'               => array( $this, 'export_usage_analytics' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data erasers
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers array
	 */
	public function register_erasers( $erasers ) {
		$erasers['wp-mcp-ai-chat-transcripts'] = array(
			'eraser_friendly_name' => __( 'NV oOS Chat Transcripts', 'mcp-ai-wpoos' ),
			'callback'             => array( $this, 'erase_chat_transcripts' ),
		);

		$erasers['wp-mcp-ai-assistant-credentials'] = array(
			'eraser_friendly_name' => __( 'NV oOS Assistant Credentials', 'mcp-ai-wpoos' ),
			'callback'             => array( $this, 'erase_assistant_credentials' ),
		);

		$erasers['wp-mcp-ai-user-settings'] = array(
			'eraser_friendly_name' => __( 'NV oOS User Settings', 'mcp-ai-wpoos' ),
			'callback'             => array( $this, 'erase_user_settings' ),
		);

		$erasers['wp-mcp-ai-usage-analytics'] = array(
			'eraser_friendly_name' => __( 'NV oOS Usage Analytics', 'mcp-ai-wpoos' ),
			'callback'             => array( $this, 'erase_usage_analytics' ),
		);

		return $erasers;
	}

	/**
	 * Export chat transcripts for user
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Export data response
	 */
	public function export_chat_transcripts( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data_to_export = array();

		// Check if JetEngine CCT integration is active for chat transcripts.
		if ( class_exists( 'Jet_Engine' ) && function_exists( 'jet_engine' ) ) {
			$transcripts = $this->get_jet_engine_chat_transcripts( $user->ID, $page );

			foreach ( $transcripts as $transcript ) {
				$data_to_export[] = array(
					'group_id'    => 'wp-mcp-ai-chat-transcripts',
					'group_label' => __( 'Chat Transcripts', 'mcp-ai-wpoos' ),
					'item_id'     => 'chat-transcript-' . $transcript['id'],
					'data'        => array(
						array(
							'name'  => __( 'Transcript ID', 'mcp-ai-wpoos' ),
							'value' => $transcript['id'],
						),
						array(
							'name'  => __( 'Assistant', 'mcp-ai-wpoos' ),
							'value' => $transcript['assistant_name'],
						),
						array(
							'name'  => __( 'Date', 'mcp-ai-wpoos' ),
							'value' => $transcript['date'],
						),
						array(
							'name'  => __( 'Messages', 'mcp-ai-wpoos' ),
							'value' => $transcript['messages'],
						),
					),
				);
			}
		}

		// Note: Browser localStorage transcripts cannot be exported server-side.
		// Users would need to use browser developer tools to access local storage data.

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Export assistant credentials for user
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Export data response
	 */
	public function export_assistant_credentials( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data_to_export = array();

		// Get assistants created by user.
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'author'         => $user->ID,
			'posts_per_page' => 50,
			'paged'          => $page,
		);

		$assistants = get_posts( $args );

		foreach ( $assistants as $assistant ) {
			$credentials = get_post_meta( $assistant->ID, '_wp_mcp_ai_credentials', true );

			$data_to_export[] = array(
				'group_id'    => 'wp-mcp-ai-assistant-credentials',
				'group_label' => __( 'Assistant Credentials', 'mcp-ai-wpoos' ),
				'item_id'     => 'assistant-cred-' . $assistant->ID,
				'data'        => array(
					array(
						'name'  => __( 'Assistant Name', 'mcp-ai-wpoos' ),
						'value' => $assistant->post_title,
					),
					array(
						'name'  => __( 'Created Date', 'mcp-ai-wpoos' ),
						'value' => $assistant->post_date,
					),
					array(
						'name'  => __( 'Credential Type', 'mcp-ai-wpoos' ),
						'value' => ! empty( $credentials ) ? __( 'Active', 'mcp-ai-wpoos' ) : __( 'None', 'mcp-ai-wpoos' ),
					),
					array(
						'name'  => __( 'Note', 'mcp-ai-wpoos' ),
						'value' => __( 'Credential tokens are hashed and cannot be exported in plain text for security reasons.', 'mcp-ai-wpoos' ),
					),
				),
			);
		}

		return array(
			'data' => $data_to_export,
			'done' => count( $assistants ) < 50,
		);
	}

	/**
	 * Export user settings
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Export data response
	 */
	public function export_user_settings( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data_to_export = array();

		// Get user meta related to MCP AI.
		$user_meta_keys = array(
			'wp_mcp_ai_default_assistant',
			'wp_mcp_ai_preferred_model',
			'wp_mcp_ai_ui_preferences',
			'wp_mcp_ai_tool_permissions',
		);

		$user_settings = array();
		foreach ( $user_meta_keys as $key ) {
			$value = get_user_meta( $user->ID, $key, true );
			if ( ! empty( $value ) ) {
				$user_settings[] = array(
					'name'  => $key,
					'value' => is_array( $value ) ? wp_json_encode( $value ) : $value,
				);
			}
		}

		if ( ! empty( $user_settings ) ) {
			$data_to_export[] = array(
				'group_id'    => 'wp-mcp-ai-user-settings',
				'group_label' => __( 'User Settings', 'mcp-ai-wpoos' ),
				'item_id'     => 'user-settings-' . $user->ID,
				'data'        => $user_settings,
			);
		}

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Export usage analytics
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Export data response
	 */
	public function export_usage_analytics( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data_to_export = array();

		// Get usage analytics if JetEngine CCT is available.
		if ( class_exists( 'Jet_Engine' ) && function_exists( 'jet_engine' ) ) {
			$analytics = $this->get_jet_engine_usage_analytics( $user->ID, $page );

			if ( ! empty( $analytics ) ) {
				$data_to_export[] = array(
					'group_id'    => 'wp-mcp-ai-usage-analytics',
					'group_label' => __( 'Usage Analytics', 'mcp-ai-wpoos' ),
					'item_id'     => 'usage-analytics-' . $user->ID,
					'data'        => $analytics,
				);
			}
		}

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Erase chat transcripts for user
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Erasure response
	 */
	public function erase_chat_transcripts( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		// Delete JetEngine CCT chat transcripts if available.
		if ( class_exists( 'Jet_Engine' ) && function_exists( 'jet_engine' ) ) {
			$deleted = $this->delete_jet_engine_chat_transcripts( $user->ID );
			if ( $deleted > 0 ) {
				$items_removed = true;
				/* translators: %d: number of transcripts deleted */
				$messages[] = sprintf( __( 'Deleted %d chat transcripts.', 'mcp-ai-wpoos' ), $deleted );
			}
		}

		// Note about browser localStorage.
		$messages[] = __( 'Note: Chat transcripts stored in browser localStorage must be cleared by the user manually.', 'mcp-ai-wpoos' );

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Erase assistant credentials for user
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Erasure response
	 */
	public function erase_assistant_credentials( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		// Get assistants created by user.
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'author'         => $user->ID,
			'posts_per_page' => 50,
			'paged'          => $page,
			'fields'         => 'ids',
		);

		$assistant_ids = get_posts( $args );

		if ( ! empty( $assistant_ids ) ) {
			foreach ( $assistant_ids as $assistant_id ) {
				// Delete credential meta.
				delete_post_meta( $assistant_id, '_wp_mcp_ai_credentials' );
				delete_post_meta( $assistant_id, '_wp_mcp_ai_credential_hash' );
				$items_removed = true;
			}

			/* translators: %d: number of assistants updated */
			$messages[] = sprintf( __( 'Removed credentials from %d assistants.', 'mcp-ai-wpoos' ), count( $assistant_ids ) );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => count( $assistant_ids ) < 50,
		);
	}

	/**
	 * Erase user settings
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Erasure response
	 */
	public function erase_user_settings( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed = false;
		$messages      = array();

		// Delete user meta related to MCP AI.
		$user_meta_keys = array(
			'wp_mcp_ai_default_assistant',
			'wp_mcp_ai_preferred_model',
			'wp_mcp_ai_ui_preferences',
			'wp_mcp_ai_tool_permissions',
		);

		$deleted_count = 0;
		foreach ( $user_meta_keys as $key ) {
			if ( delete_user_meta( $user->ID, $key ) ) {
				++$deleted_count;
				$items_removed = true;
			}
		}

		if ( $deleted_count > 0 ) {
			/* translators: %d: number of settings deleted */
			$messages[] = sprintf( __( 'Deleted %d user settings.', 'mcp-ai-wpoos' ), $deleted_count );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Erase usage analytics
	 *
	 * @param string $email_address User email address.
	 * @param int    $page Page number for pagination.
	 * @return array Erasure response
	 */
	public function erase_usage_analytics( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$items_removed = false;
		$messages      = array();

		// Delete usage analytics if JetEngine CCT is available.
		if ( class_exists( 'Jet_Engine' ) && function_exists( 'jet_engine' ) ) {
			$deleted = $this->delete_jet_engine_usage_analytics( $user->ID );
			if ( $deleted > 0 ) {
				$items_removed = true;
				/* translators: %d: number of analytics records deleted */
				$messages[] = sprintf( __( 'Deleted %d usage analytics records.', 'mcp-ai-wpoos' ), $deleted );
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Get chat transcripts from JetEngine CCT
	 *
	 * @param int $user_id User ID.
	 * @param int $page Page number.
	 * @return array Chat transcripts
	 */
	private function get_jet_engine_chat_transcripts( $user_id, $page = 1 ) {
		// Placeholder for JetEngine CCT integration.
		// Implementation depends on CCT structure.
		return array();
	}

	/**
	 * Get usage analytics from JetEngine CCT
	 *
	 * @param int $user_id User ID.
	 * @param int $page Page number.
	 * @return array Usage analytics
	 */
	private function get_jet_engine_usage_analytics( $user_id, $page = 1 ) {
		// Placeholder for JetEngine CCT integration.
		// Implementation depends on CCT structure.
		return array();
	}

	/**
	 * Delete chat transcripts from JetEngine CCT
	 *
	 * @param int $user_id User ID.
	 * @return int Number of items deleted
	 */
	private function delete_jet_engine_chat_transcripts( $user_id ) {
		// Placeholder for JetEngine CCT integration.
		// Implementation depends on CCT structure.
		return 0;
	}

	/**
	 * Delete usage analytics from JetEngine CCT
	 *
	 * @param int $user_id User ID.
	 * @return int Number of items deleted
	 */
	private function delete_jet_engine_usage_analytics( $user_id ) {
		// Placeholder for JetEngine CCT integration.
		// Implementation depends on CCT structure.
		return 0;
	}
}
