<?php
/**
 * Elementor widget for displaying recent tool and request activity.
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
 * Elementor widget definition for the recent activity feed.
 */
class WP_MCP_AI_Elementor_Dashboard_Activity_Feed_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_activity_feed';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Activity Feed', 'wp-mcp-ai' );
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
		return array( 'mcp', 'activity', 'logs', 'tools', 'dashboard' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Activity Feed', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Recent MCP activity', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Summaries of the latest tool executions and model requests captured by the MCP logger.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'entry_limit',
			array(
				'label'   => __( 'Entries to display', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 30,
				'default' => 8,
			)
		);

		$this->add_control(
			'include_request_events',
			array(
				'label'        => __( 'Include provider request logs', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_activity_feed',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-activity-feed',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-activity-feed__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__description',
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__notice',
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__message',
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__context-json',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__badge',
						'{{WRAPPER}} .wp-mcp-ai-activity-feed__timestamp',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-activity-feed a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title          = isset( $settings['title'] ) ? $settings['title'] : '';
		$description    = isset( $settings['description'] ) ? $settings['description'] : '';
		$limit          = isset( $settings['entry_limit'] ) ? absint( $settings['entry_limit'] ) : 8;
		$include_remote = ! empty( $settings['include_request_events'] ) && 'yes' === $settings['include_request_events'];

		echo '<div class="wp-mcp-ai-activity-feed">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-activity-feed__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-activity-feed__description">' . $description_output . '</div>';
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) || ! WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			echo '<p class="wp-mcp-ai-activity-feed__notice">' . esc_html__( 'Enable logging in the NV oOS settings to populate the activity feed.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$types = array( 'tool_execution', 'tool_error', 'chat_interaction' );

		if ( $include_remote ) {
			$types = array();
		}

		$entries = array();

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			$entries = WP_MCP_AI_Logger::get_recent_activity_entries( $limit, $types );
		}

		if ( empty( $entries ) ) {
			echo '<p class="wp-mcp-ai-activity-feed__notice">' . esc_html__( 'No recent activity has been recorded yet.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<ul class="wp-mcp-ai-activity-feed__list">';
		foreach ( $entries as $entry ) {
			$timestamp = isset( $entry['timestamp'] ) ? $entry['timestamp'] : '';
			$type      = isset( $entry['type'] ) ? strtoupper( $entry['type'] ) : '';
			$message   = isset( $entry['message'] ) ? $entry['message'] : '';
			$context   = isset( $entry['context'] ) ? $entry['context'] : array();

			$formatted_time = $this->format_timestamp( $timestamp );
			$context_markup = $this->format_context( $context );

			echo '<li class="wp-mcp-ai-activity-feed__item">';
			echo '<div class="wp-mcp-ai-activity-feed__meta">';
			if ( $type ) {
				echo '<span class="wp-mcp-ai-activity-feed__badge">' . esc_html( $type ) . '</span>';
			}
			if ( $formatted_time ) {
				if ( $type ) {
					echo '<span class="wp-mcp-ai-activity-feed__meta-separator" aria-hidden="true"> </span>';
				}
				echo '<span class="wp-mcp-ai-activity-feed__timestamp">' . esc_html( $formatted_time ) . '</span>';
			}
			echo '</div>';
			echo '<div class="wp-mcp-ai-activity-feed__message">' . esc_html( $message ) . '</div>';
			if ( '' !== $context_markup ) {
				echo '<details class="wp-mcp-ai-activity-feed__context">';
				echo '<summary>' . esc_html__( 'View context', 'wp-mcp-ai' ) . '</summary>';
				echo $context_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</details>';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '</div>';
	}

	/**
	 * Format a timestamp for output.
	 *
	 * @param string $timestamp Timestamp string.
	 * @return string
	 */
	protected function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return '';
		}

		$time = strtotime( $timestamp );

		if ( false === $time ) {
			return $timestamp;
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $time ), $date_format . ' ' . $time_format );
	}

	/**
	 * Format context data for display.
	 *
	 * @param array $context Context payload.
	 * @return string
	 */
	protected function format_context( $context ) {
		if ( empty( $context ) || ! is_array( $context ) ) {
			return '';
		}

		$options = 0;

		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			$options |= JSON_PRETTY_PRINT;
		}

		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$options |= JSON_UNESCAPED_SLASHES;
		}

		$json = wp_json_encode( $context, $options );

		if ( false === $json ) {
			return '';
		}

		return '<pre class="wp-mcp-ai-activity-feed__context-json">' . esc_html( $json ) . '</pre>';
	}
}
