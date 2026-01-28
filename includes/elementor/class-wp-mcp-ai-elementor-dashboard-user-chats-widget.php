// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget for browsing a user's chat transcript history.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * @package WP_MCP_AI
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! defined( 'ABSPATH' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	exit;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
// Prevent parse errors on PHP < 7.4 by exiting before class definition.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 * Elementor widget definition for the user chat transcript list.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
class WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget extends \Elementor\Widget_Base {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	use WP_MCP_AI_Elementor_Text_Formatting;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	const SCRIPT_HANDLE = 'wp-mcp-ai-user-chats';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	const STYLE_HANDLE  = 'wp-mcp-ai-user-chats';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Track whether the front-end assets have been registered.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @var bool
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected static $assets_registered = false;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Track whether the script localisation has already occurred.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @var bool
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected static $script_localized = false;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget slug.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_name() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'wp_mcp_ai_user_chats';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget title shown in the Elementor editor.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_title() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return __( 'NV oOS User Chat History', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget icon for Elementor panel.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_icon() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return 'eicon-time-line';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Widget categories.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_categories() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'general' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Keywords to help search for the widget.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_keywords() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( 'mcp', 'chat', 'history', 'transcripts', 'user', 'assistant' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Declare script dependencies for this widget.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array List of script handles this widget depends on.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_script_depends() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Ensure the script is registered before declaring it as a dependency.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_assets_if_needed();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( self::SCRIPT_HANDLE );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Declare style dependencies for this widget.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return array List of style handles this widget depends on.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	public function get_style_depends() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Ensure the style is registered before declaring it as a dependency.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_assets_if_needed();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return array( self::STYLE_HANDLE );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Check if currently in Elementor editor mode.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return bool True if in Elementor editor mode, false otherwise.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function is_elementor_editor() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( class_exists( '\Elementor\Plugin' ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$elementor = \Elementor\Plugin::instance();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( $elementor && $elementor->editor && $elementor->editor->is_edit_mode() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				return true;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return false;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Register widget assets if not already registered.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * This is called by get_script_depends() and get_style_depends()
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * to ensure assets are registered before Elementor tries to load them.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function register_assets_if_needed() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( self::$assets_registered ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$script_relative = 'assets/js/user-chats.js';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$style_relative  = 'assets/css/user-chats.css';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		wp_register_script(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			self::SCRIPT_HANDLE,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			WP_MCP_AI_URL . $script_relative,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$this->get_asset_version( $script_relative ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			true
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		wp_register_style(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			self::STYLE_HANDLE,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			WP_MCP_AI_URL . $style_relative,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$this->get_asset_version( $style_relative )
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		self::$assets_registered = true;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Register controls for the widget settings.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function register_controls() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->start_controls_section(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'section_content',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label' => __( 'Chat History', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'title',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Title', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Conversation history', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'description',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'Description', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'rows'    => 3,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => __( 'Review the stored chat transcripts for this operator. Select a session to inspect the full conversation.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'user_mode',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'User Source', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => 'current',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options' => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'current'  => __( 'Current user', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'specific' => __( 'Specific user ID', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'user_id',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'User ID', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::NUMBER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'min'         => 1,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'condition'   => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'user_mode' => 'specific',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'assistant_mode',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'   => __( 'Assistant Filter', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'    => \Elementor\Controls_Manager::SELECT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default' => 'all',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'options' => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'all'      => __( 'All assistants', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'specific' => __( 'Specific assistant ID', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'assistant_id',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Assistant ID', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::NUMBER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'min'         => 1,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'description' => __( 'Filter chats by a specific assistant.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'condition'   => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'assistant_mode' => 'specific',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'max_sessions',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Maximum chats to show', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::NUMBER,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'min'         => 1,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => 20,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'description' => __( 'Limit the number of chat sessions displayed. Leave empty to show every available session.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'empty_message',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Empty state message', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'No chat transcripts are stored for this user yet.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'no_user_message',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Missing user message', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Select a user to view their chat transcripts.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'empty_session_message',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Empty session message', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'This chat does not contain any messages yet.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'select_prompt_message',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Selection prompt', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'default'     => __( 'Select a chat session to review the conversation.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->add_control(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'target_chat_widget',
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label'       => __( 'Target Chat Widget', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'type'        => \Elementor\Controls_Manager::TEXT,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'description' => __( 'CSS selector for the target chat widget (e.g., #my-chat-widget). Leave empty to auto-detect the closest chat widget.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'label_block' => true,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->end_controls_section();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_theme_style_controls(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'section_id' => 'section_style_user_chats',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectors'  => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'container'  => '{{WRAPPER}} .wp-mcp-ai-user-chats',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'heading'    => '{{WRAPPER}} .wp-mcp-ai-user-chats__title',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'text'       => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__description',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__status',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__preview',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__assistant',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__timestamp',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__message-content',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'meta'       => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__meta',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
						'{{WRAPPER}} .wp-mcp-ai-user-chats__message-meta',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'link'       => '{{WRAPPER}} .wp-mcp-ai-user-chats__session-button',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'link_hover' => '{{WRAPPER}} .wp-mcp-ai-user-chats__session-button:focus',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Render the widget on the front-end.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function render() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$settings = $this->get_settings_for_display();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$title                 = isset( $settings['title'] ) ? $settings['title'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$description           = isset( $settings['description'] ) ? $settings['description'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$user_mode             = isset( $settings['user_mode'] ) ? $settings['user_mode'] : 'current';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$user_id_setting       = isset( $settings['user_id'] ) ? (int) $settings['user_id'] : 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$assistant_mode        = isset( $settings['assistant_mode'] ) ? $settings['assistant_mode'] : 'all';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$assistant_id_setting  = isset( $settings['assistant_id'] ) ? (int) $settings['assistant_id'] : 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$max_sessions_setting  = isset( $settings['max_sessions'] ) ? (int) $settings['max_sessions'] : 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$empty_message         = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$no_user_message       = isset( $settings['no_user_message'] ) ? $settings['no_user_message'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$empty_session_message = isset( $settings['empty_session_message'] ) ? $settings['empty_session_message'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$select_prompt_message = isset( $settings['select_prompt_message'] ) ? $settings['select_prompt_message'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$target_chat_widget    = isset( $settings['target_chat_widget'] ) ? $settings['target_chat_widget'] : '';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( 'specific' === $user_mode ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$user_id = absint( $user_id_setting );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$user_id = get_current_user_id();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( 'specific' === $assistant_mode ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$assistant_id = absint( $assistant_id_setting );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		} else {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$assistant_id = 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' !== $title ) {
			echo '<h3 class="wp-mcp-ai-user-chats__title">' . esc_html( $title ) . '</h3>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( '' !== $description ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$description_output = $this->format_text_block( $description );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-user-chats__description">' . $description_output . '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Show placeholder in Elementor editor mode to prevent JavaScript conflicts.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( $this->is_elementor_editor() ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '<div class="wp-mcp-ai-user-chats__editor-placeholder" style="padding: 20px; background: #f0f0f1; border: 2px dashed #c3c4c7; text-align: center;">';
			echo '<p style="margin: 0;">' . esc_html__( 'Chat history will be displayed here on the live page.', 'mcp-ai-wpoos' ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! $user_id ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( '' !== $no_user_message ) {
				echo '<p class="wp-mcp-ai-user-chats__status">' . esc_html( $no_user_message ) . '</p>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			return;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$max_sessions = $max_sessions_setting > 0 ? $max_sessions_setting : 0;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->enqueue_assets();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$config = array(
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'userId'           => $user_id,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'assistantId'      => $assistant_id,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'maxSessions'      => $max_sessions,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'targetChatWidget' => $target_chat_widget,
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			'strings'          => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'emptyList'            => $empty_message,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'emptySession'         => $empty_session_message,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'selectPrompt'         => $select_prompt_message,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'noUserMessage'        => $no_user_message,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadIntoChat'         => __( 'Load into chat', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadIntoChatLabel'    => __( 'Load this conversation into the chat window', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadingIntoChat'      => __( 'Loading into chat…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadedIntoChat'       => __( 'Conversation loaded into chat.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'errorLoadingIntoChat' => __( 'Unable to load into chat.', 'mcp-ai-wpoos' ),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$config_json = wp_json_encode( $config );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

		echo '<div class="wp-mcp-ai-user-chats__wrapper" data-wp-mcp-ai-user-chats="' . esc_attr( $config_json ) . '">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats__status" aria-live="polite"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats__list" hidden>';
		echo '<input type="search" class="wp-mcp-ai-user-chats__search" placeholder="' . esc_attr__( 'Search sessions...', 'mcp-ai-wpoos' ) . '" aria-label="' . esc_attr__( 'Search chat sessions', 'mcp-ai-wpoos' ) . '" />';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<ul class="wp-mcp-ai-user-chats__sessions"></ul>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats__conversation" hidden>';
		echo '<button type="button" class="wp-mcp-ai-user-chats__back">' . esc_html__( 'Back to chats', 'mcp-ai-wpoos' ) . '</button>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats__conversation-header">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<h4 class="wp-mcp-ai-user-chats__conversation-title"></h4>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<div class="wp-mcp-ai-user-chats__conversation-meta"></div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '<ol class="wp-mcp-ai-user-chats__messages"></ol>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		echo '</div>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Ensure the required scripts and styles are loaded.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function enqueue_assets() {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Register assets if needed.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$this->register_assets_if_needed();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		// Localize script if not already done.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( ! self::$script_localized ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$localised_strings = array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadingList'                    => __( 'Loading chats…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'loadingConversation'            => __( 'Loading chat…', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'errorLoadingList'               => __( 'Unable to load chats right now.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'errorLoadingSession'            => __( 'Unable to load the selected chat.', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'back'                           => __( 'Back to chats', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				/* translators: %s: chat session identifier */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								'sessionLabel'   => __( 'Chat session %s', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'assistantLabel'                 => __( 'Assistant', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'startedLabel'                   => __( 'Started', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'updatedLabel'                   => __( 'Last activity', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				/* translators: %d: number of messages */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
								'turnCountLabel' => __( '%d messages', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'roleLabels'                     => array(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'system'    => __( 'System', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'user'      => __( 'User', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'assistant' => __( 'Assistant', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'tool'      => __( 'Tool', 'mcp-ai-wpoos' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				),
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( ! isset( $localised_strings['roleLabels']['assistant'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				$localised_strings['roleLabels']['assistant'] = __( 'Assistant', 'mcp-ai-wpoos' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			wp_localize_script(
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				self::SCRIPT_HANDLE,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				'wpMcpAiUserChats',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				array(
					'restUrl' => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'nonce'   => wp_create_nonce( 'wp_rest' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
					'strings' => $localised_strings,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			self::$script_localized = true;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		wp_enqueue_script( self::SCRIPT_HANDLE );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		wp_enqueue_style( self::STYLE_HANDLE );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	/**
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * Determine the version string for an asset using its modification time when available.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 *
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @param string $relative_path Asset path relative to the plugin root.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 * @return string
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	 */
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	protected function get_asset_version( $relative_path ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$relative_path = ltrim( $relative_path, '/' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		$absolute_path = WP_MCP_AI_PATH . $relative_path;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		if ( file_exists( $absolute_path ) ) {
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			$modified = filemtime( $absolute_path );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			if ( $modified ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
				return WP_MCP_AI_VERSION . '.' . $modified;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
			}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
		return WP_MCP_AI_VERSION;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
	}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure.
}
