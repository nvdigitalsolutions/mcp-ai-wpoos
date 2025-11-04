<?php
/**
 * Elementor widget for browsing a user's chat transcript history.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget definition for the user chat transcript list.
 */
class WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	const SCRIPT_HANDLE = 'wp-mcp-ai-user-chats';
	const STYLE_HANDLE  = 'wp-mcp-ai-user-chats';

	/**
	 * Track whether the front-end assets have been registered.
	 *
	 * @var bool
	 */
	protected static $assets_registered = false;

	/**
	 * Track whether the script localisation has already occurred.
	 *
	 * @var bool
	 */
	protected static $script_localized = false;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_user_chats';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS User Chat History', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-time-line';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'mcp', 'chat', 'history', 'transcripts', 'user', 'assistant' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Chat History', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Conversation history', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Review the stored chat transcripts for this operator. Select a session to inspect the full conversation.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'user_mode',
			array(
				'label'   => __( 'User Source', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current'  => __( 'Current user', 'wp-mcp-ai' ),
					'specific' => __( 'Specific user ID', 'wp-mcp-ai' ),
				),
			)
		);

		$this->add_control(
			'user_id',
			array(
				'label'       => __( 'User ID', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'label_block' => true,
				'condition'   => array(
					'user_mode' => 'specific',
				),
			)
		);

		$this->add_control(
			'max_sessions',
			array(
				'label'       => __( 'Maximum chats to show', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'default'     => 20,
				'description' => __( 'Limit the number of chat sessions displayed. Leave empty to show every available session.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'       => __( 'Empty state message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No chat transcripts are stored for this user yet.', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'no_user_message',
			array(
				'label'       => __( 'Missing user message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select a user to view their chat transcripts.', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'empty_session_message',
			array(
				'label'       => __( 'Empty session message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'This chat does not contain any messages yet.', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'select_prompt_message',
			array(
				'label'       => __( 'Selection prompt', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select a chat session to review the conversation.', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_user_chats',
				'selectors'  => array(
					'container'  => '{{WRAPPER}} .wp-mcp-ai-user-chats',
					'heading'    => '{{WRAPPER}} .wp-mcp-ai-user-chats__title',
					'text'       => array(
						'{{WRAPPER}} .wp-mcp-ai-user-chats__description',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__status',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__preview',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__assistant',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__timestamp',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__message-content',
					),
					'meta'       => array(
						'{{WRAPPER}} .wp-mcp-ai-user-chats__meta',
						'{{WRAPPER}} .wp-mcp-ai-user-chats__message-meta',
					),
					'link'       => '{{WRAPPER}} .wp-mcp-ai-user-chats__session-button',
					'link_hover' => '{{WRAPPER}} .wp-mcp-ai-user-chats__session-button:focus',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title                 = isset( $settings['title'] ) ? $settings['title'] : '';
		$description           = isset( $settings['description'] ) ? $settings['description'] : '';
		$user_mode             = isset( $settings['user_mode'] ) ? $settings['user_mode'] : 'current';
		$user_id_setting       = isset( $settings['user_id'] ) ? (int) $settings['user_id'] : 0;
		$max_sessions_setting  = isset( $settings['max_sessions'] ) ? (int) $settings['max_sessions'] : 0;
		$empty_message         = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
		$no_user_message       = isset( $settings['no_user_message'] ) ? $settings['no_user_message'] : '';
		$empty_session_message = isset( $settings['empty_session_message'] ) ? $settings['empty_session_message'] : '';
		$select_prompt_message = isset( $settings['select_prompt_message'] ) ? $settings['select_prompt_message'] : '';

		if ( 'specific' === $user_mode ) {
			$user_id = absint( $user_id_setting );
		} else {
			$user_id = get_current_user_id();
		}

		echo '<div class="wp-mcp-ai-user-chats">';

		if ( '' !== $title ) {
			echo '<h3 class="wp-mcp-ai-user-chats__title">' . esc_html( $title ) . '</h3>';
		}

		if ( '' !== $description ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				echo '<div class="wp-mcp-ai-user-chats__description">' . $description_output . '</div>';
			}
		}

		if ( ! $user_id ) {
			if ( '' !== $no_user_message ) {
				echo '<p class="wp-mcp-ai-user-chats__status">' . esc_html( $no_user_message ) . '</p>';
			}

			echo '</div>';
			return;
		}

		$max_sessions = $max_sessions_setting > 0 ? $max_sessions_setting : 0;

		$this->enqueue_assets();

		$config = array(
			'userId'      => $user_id,
			'maxSessions' => $max_sessions,
			'strings'     => array(
				'emptyList'     => $empty_message,
				'emptySession'  => $empty_session_message,
				'selectPrompt'  => $select_prompt_message,
				'noUserMessage' => $no_user_message,
			),
		);

		$config_json = wp_json_encode( $config );

		echo '<div class="wp-mcp-ai-user-chats__wrapper" data-wp-mcp-ai-user-chats="' . esc_attr( $config_json ) . '">';
		echo '<div class="wp-mcp-ai-user-chats__status" aria-live="polite"></div>';
		echo '<div class="wp-mcp-ai-user-chats__list" hidden>';
		echo '<ul class="wp-mcp-ai-user-chats__sessions"></ul>';
		echo '</div>';
		echo '<div class="wp-mcp-ai-user-chats__conversation" hidden>';
		echo '<button type="button" class="wp-mcp-ai-user-chats__back">' . esc_html__( 'Back to chats', 'wp-mcp-ai' ) . '</button>';
		echo '<div class="wp-mcp-ai-user-chats__conversation-header">';
		echo '<h4 class="wp-mcp-ai-user-chats__conversation-title"></h4>';
		echo '<div class="wp-mcp-ai-user-chats__conversation-meta"></div>';
		echo '</div>';
		echo '<ol class="wp-mcp-ai-user-chats__messages"></ol>';
		echo '</div>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Ensure the required scripts and styles are loaded.
	 */
	protected function enqueue_assets() {
		if ( ! self::$assets_registered ) {
			$script_relative = 'assets/js/user-chats.js';
			$style_relative  = 'assets/css/user-chats.css';

			wp_register_script(
				self::SCRIPT_HANDLE,
				WP_MCP_AI_URL . $script_relative,
				array(),
				$this->get_asset_version( $script_relative ),
				true
			);

			wp_register_style(
				self::STYLE_HANDLE,
				WP_MCP_AI_URL . $style_relative,
				array(),
				$this->get_asset_version( $style_relative )
			);

			self::$assets_registered = true;
		}

		if ( ! self::$script_localized ) {
			$localised_strings = array(
				'loadingList'         => __( 'Loading chats…', 'wp-mcp-ai' ),
				'loadingConversation' => __( 'Loading chat…', 'wp-mcp-ai' ),
				'errorLoadingList'    => __( 'Unable to load chats right now.', 'wp-mcp-ai' ),
				'errorLoadingSession' => __( 'Unable to load the selected chat.', 'wp-mcp-ai' ),
				'back'                => __( 'Back to chats', 'wp-mcp-ai' ),
				'sessionLabel'        => __( 'Chat session %s', 'wp-mcp-ai' ),
				'assistantLabel'      => __( 'Assistant', 'wp-mcp-ai' ),
				'startedLabel'        => __( 'Started', 'wp-mcp-ai' ),
				'updatedLabel'        => __( 'Last activity', 'wp-mcp-ai' ),
				'turnCountLabel'      => __( '%d messages', 'wp-mcp-ai' ),
				'roleLabels'          => array(
					'system'    => __( 'System', 'wp-mcp-ai' ),
					'user'      => __( 'User', 'wp-mcp-ai' ),
					'assistant' => __( 'Assistant', 'wp-mcp-ai' ),
					'tool'      => __( 'Tool', 'wp-mcp-ai' ),
				),
			);

			if ( ! isset( $localised_strings['roleLabels']['assistant'] ) ) {
				$localised_strings['roleLabels']['assistant'] = __( 'Assistant', 'wp-mcp-ai' );
			}

			wp_localize_script(
				self::SCRIPT_HANDLE,
				'wpMcpAiUserChats',
				array(
					'restUrl' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'strings' => $localised_strings,
				)
			);

			self::$script_localized = true;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Determine the version string for an asset using its modification time when available.
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$relative_path = ltrim( $relative_path, '/' );
		$absolute_path = WP_MCP_AI_PATH . $relative_path;

		if ( file_exists( $absolute_path ) ) {
			$modified = filemtime( $absolute_path );

			if ( $modified ) {
				return WP_MCP_AI_VERSION . '.' . $modified;
			}
		}

		return WP_MCP_AI_VERSION;
	}
}
