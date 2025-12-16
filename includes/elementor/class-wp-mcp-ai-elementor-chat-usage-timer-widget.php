<?php
/**
 * Elementor widget for displaying usage summaries and a focus timer near the chat interface.
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
 * Elementor widget definition for chat usage and timer information.
 */
class WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_chat_usage_timer';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Usage & Timer', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-countdown';
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
		return array( 'ai', 'chat', 'usage', 'timer', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Session overview', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => __( 'Description', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Track how long you have been collaborating and keep an eye on token usage for this workspace.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Provide additional context for the timer and usage summary.', 'wp-mcp-ai' ),
				'rows'        => 3,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_timer',
			array(
				'label' => __( 'Timer', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_timer',
			array(
				'label'        => __( 'Display Timer', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'true',
			)
		);

		$this->add_control(
			'timer_label',
			array(
				'label'       => __( 'Timer Label', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Focus timer', 'wp-mcp-ai' ),
				'placeholder' => __( 'Label displayed above the timer.', 'wp-mcp-ai' ),
				'condition'   => array(
					'show_timer' => 'true',
				),
			)
		);

		$this->add_control(
			'timer_duration',
			array(
				'label'       => __( 'Duration (minutes)', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 180,
				'step'        => 1,
				'default'     => 25,
				'condition'   => array(
					'show_timer' => 'true',
				),
				'description' => __( 'Set how long the countdown should run when the page loads.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'timer_complete_text',
			array(
				'label'       => __( 'Timer Complete Message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Time is up! Take a break or start a new sprint.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Message displayed when the timer finishes.', 'wp-mcp-ai' ),
				'condition'   => array(
					'show_timer' => 'true',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_usage',
			array(
				'label' => __( 'Usage Summary', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_usage',
			array(
				'label'        => __( 'Display Usage Totals', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'true',
				'default'      => 'true',
			)
		);

		$this->add_control(
			'usage_heading',
			array(
				'label'       => __( 'Usage Heading', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Your token usage', 'wp-mcp-ai' ),
				'placeholder' => __( 'Heading shown above the usage summary.', 'wp-mcp-ai' ),
				'condition'   => array(
					'show_usage' => 'true',
				),
			)
		);

		$this->add_control(
			'usage_empty_message',
			array(
				'label'       => __( 'No Usage Message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Usage details will appear here after you exchange a few messages.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Message shown when no usage data is available yet.', 'wp-mcp-ai' ),
				'rows'        => 2,
				'condition'   => array(
					'show_usage' => 'true',
				),
			)
		);

		$this->add_control(
			'usage_login_message',
			array(
				'label'       => __( 'Login Required Message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Log in to track personal usage and see token totals.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Message shown to visitors who are not logged in.', 'wp-mcp-ai' ),
				'condition'   => array(
					'show_usage' => 'true',
				),
			)
		);

		$this->add_control(
			'usage_unavailable_message',
			array(
				'label'       => __( 'Unavailable Message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Usage tracking is currently unavailable.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Message displayed if usage tracking cannot be loaded.', 'wp-mcp-ai' ),
				'condition'   => array(
					'show_usage' => 'true',
				),
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_chat_usage_timer',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-chat-usage-timer',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__title',
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__usage-heading',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__description',
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__timer-label',
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__usage-message',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__time',
						'{{WRAPPER}} .wp-mcp-ai-chat-usage-timer__usage-total',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-chat-usage-timer a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title       = isset( $settings['title'] ) ? $settings['title'] : '';
		$description = isset( $settings['description'] ) ? $settings['description'] : '';

		$show_timer          = isset( $settings['show_timer'] ) && 'true' === $settings['show_timer'];
		$timer_label         = isset( $settings['timer_label'] ) ? $settings['timer_label'] : '';
		$timer_duration      = isset( $settings['timer_duration'] ) ? (int) $settings['timer_duration'] : 0;
		$timer_complete_text = isset( $settings['timer_complete_text'] ) ? $settings['timer_complete_text'] : '';

		$show_usage                = isset( $settings['show_usage'] ) && 'true' === $settings['show_usage'];
		$usage_heading             = isset( $settings['usage_heading'] ) ? $settings['usage_heading'] : '';
		$usage_empty_message       = isset( $settings['usage_empty_message'] ) ? $settings['usage_empty_message'] : '';
		$usage_login_message       = isset( $settings['usage_login_message'] ) ? $settings['usage_login_message'] : '';
		$usage_unavailable_message = isset( $settings['usage_unavailable_message'] ) ? $settings['usage_unavailable_message'] : '';

		echo '<div class="wp-mcp-ai-chat-usage-timer">';

		if ( ! empty( $title ) ) {
			echo '<h2 class="wp-mcp-ai-chat-usage-timer__title">' . esc_html( $title ) . '</h2>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-chat-usage-timer__description">' . $description_output . '</div>';
			}
		}

		if ( $show_timer && $timer_duration > 0 ) {
			$this->render_timer_block( $timer_label, $timer_duration, $timer_complete_text );
		}

		if ( $show_usage ) {
			$this->render_usage_block( $usage_heading, $usage_empty_message, $usage_login_message, $usage_unavailable_message );
		}

		echo '</div>';
	}

	/**
	 * Render the timer block.
	 *
	 * @param string $label              Timer label.
	 * @param int    $duration_in_minutes Countdown duration in minutes.
	 * @param string $complete_text       Message displayed when the timer finishes.
	 */
	protected function render_timer_block( $label, $duration_in_minutes, $complete_text ) {
		$duration_in_minutes = max( 0, (int) $duration_in_minutes );

		if ( $duration_in_minutes <= 0 ) {
			return;
		}

		$timer_id         = 'wp-mcp-ai-timer-' . $this->get_id();
		$timer_id_attr    = sanitize_html_class( $timer_id );
		$duration_seconds = $duration_in_minutes * 60;

		echo '<div class="wp-mcp-ai-chat-usage-timer__timer" data-duration="' . esc_attr( $duration_seconds ) . '">';

		if ( ! empty( $label ) ) {
			echo '<span class="wp-mcp-ai-chat-usage-timer__timer-label">' . esc_html( $label ) . '</span>';
			echo ' ';
		}

		echo '<span id="' . esc_attr( $timer_id_attr ) . '" class="wp-mcp-ai-chat-usage-timer__time" aria-live="polite">' . esc_html( $this->format_time_remaining( $duration_seconds ) ) . '</span>';
		echo '</div>';

		$data = array(
			'id'            => $timer_id_attr,
			'duration'      => $duration_seconds,
			'complete_text' => $complete_text,
		);

		$script = '( function() {'
			. ' var data = ' . wp_json_encode( $data ) . ';'
			. ' if ( ! data || ! data.id ) { return; }'
			. ' var display = document.getElementById( data.id );'
			. ' if ( ! display ) { return; }'
			. ' var duration = parseInt( data.duration, 10 );'
			. ' if ( isNaN( duration ) || duration <= 0 ) { return; }'
			. ' var remaining = duration;'
			. ' function format( value ) {'
			. '     var minutes = Math.floor( value / 60 );'
			. '     var seconds = value % 60;'
			. '     return minutes + ":" + ( seconds < 10 ? "0" + seconds : seconds );'
			. ' }'
			. ' function tick() {'
			. '     if ( remaining < 0 ) { return; }'
			. '     if ( remaining === 0 ) {'
			. '         if ( data.complete_text ) { display.textContent = data.complete_text; } else { display.textContent = "00:00"; }'
			. '         clearInterval( interval );'
			. '         return;'
			. '     }'
			. '     display.textContent = format( remaining );'
			. '     remaining--;'
			. ' }'
			. ' tick();'
			. ' var interval = setInterval( tick, 1000 );'
			. '} )();';

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $script );
		} else {
			echo '<script>' . $script . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render the usage summary block.
	 *
	 * @param string $heading                  Heading label.
	 * @param string $empty_message            Message when no usage data is stored.
	 * @param string $login_message            Message when user is not logged in.
	 * @param string $unavailable_message      Message when tracking is unavailable.
	 */
	protected function render_usage_block( $heading, $empty_message, $login_message, $unavailable_message ) {
		$summary = $this->get_usage_summary();

		echo '<div class="wp-mcp-ai-chat-usage-timer__usage">';

		if ( ! empty( $heading ) ) {
			echo '<div class="wp-mcp-ai-chat-usage-timer__usage-heading">' . esc_html( $heading ) . '</div>';
		}

		if ( ! empty( $summary['unavailable'] ) ) {
			echo '<p class="wp-mcp-ai-chat-usage-timer__usage-message">' . esc_html( $unavailable_message ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! empty( $summary['requires_login'] ) ) {
			echo '<p class="wp-mcp-ai-chat-usage-timer__usage-message">' . esc_html( $login_message ) . '</p>';
			echo '</div>';
			return;
		}

		if ( empty( $summary['has_usage'] ) ) {
			echo '<p class="wp-mcp-ai-chat-usage-timer__usage-message">' . esc_html( $empty_message ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<dl class="wp-mcp-ai-chat-usage-timer__usage-totals">';
		echo '<div class="wp-mcp-ai-chat-usage-timer__usage-total">'
			. '<dt>' . esc_html__( 'Prompt tokens', 'wp-mcp-ai' ) . '</dt>'
			. '<dd>' . esc_html( number_format_i18n( $summary['totals']['prompt_tokens'] ) ) . '</dd>'
			. '</div>';

		echo '<div class="wp-mcp-ai-chat-usage-timer__usage-total">'
			. '<dt>' . esc_html__( 'Completion tokens', 'wp-mcp-ai' ) . '</dt>'
			. '<dd>' . esc_html( number_format_i18n( $summary['totals']['completion_tokens'] ) ) . '</dd>'
			. '</div>';

		if ( $summary['totals']['cached_prompt_tokens'] > 0 ) {
			echo '<div class="wp-mcp-ai-chat-usage-timer__usage-total">'
				. '<dt>' . esc_html__( 'Cached prompt tokens', 'wp-mcp-ai' ) . '</dt>'
				. '<dd>' . esc_html( number_format_i18n( $summary['totals']['cached_prompt_tokens'] ) ) . '</dd>'
				. '</div>';
		}

		echo '<div class="wp-mcp-ai-chat-usage-timer__usage-total">'
			. '<dt>' . esc_html__( 'Cached tokens', 'wp-mcp-ai' ) . '</dt>'
			. '<dd>' . esc_html( number_format_i18n( $summary['totals']['cached_tokens'] ) ) . '</dd>'
			. '</div>';

		echo '<div class="wp-mcp-ai-chat-usage-timer__usage-total">'
			. '<dt>' . esc_html__( 'Total tokens', 'wp-mcp-ai' ) . '</dt>'
			. '<dd>' . esc_html( number_format_i18n( $summary['totals']['total_tokens'] ) ) . '</dd>'
			. '</div>';

		echo '</dl>';
		echo '</div>';
	}

	/**
	 * Retrieve usage summary for the current user.
	 *
	 * @return array
	 */
	protected function get_usage_summary() {
		if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			return array(
				'unavailable'    => true,
				'requires_login' => false,
				'has_usage'      => false,
				'totals'         => array(
					'prompt_tokens'        => 0,
					'completion_tokens'    => 0,
					'cached_prompt_tokens' => 0,
					'total_tokens'         => 0,
					'cached_tokens'        => 0,
				),
			);
		}

		if ( ! is_user_logged_in() ) {
			return array(
				'unavailable'    => false,
				'requires_login' => true,
				'has_usage'      => false,
				'totals'         => array(
					'prompt_tokens'        => 0,
					'completion_tokens'    => 0,
					'cached_prompt_tokens' => 0,
					'total_tokens'         => 0,
					'cached_tokens'        => 0,
				),
			);
		}

		$user_usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( get_current_user_id() );

		if ( empty( $user_usage ) || ! is_array( $user_usage ) ) {
			return array(
				'unavailable'    => false,
				'requires_login' => false,
				'has_usage'      => false,
				'totals'         => array(
					'prompt_tokens'        => 0,
					'completion_tokens'    => 0,
					'cached_prompt_tokens' => 0,
					'total_tokens'         => 0,
					'cached_tokens'        => 0,
				),
			);
		}

		$totals = array(
			'prompt_tokens'        => 0,
			'completion_tokens'    => 0,
			'cached_prompt_tokens' => 0,
			'total_tokens'         => 0,
			'cached_tokens'        => 0,
		);

		foreach ( $user_usage as $provider_usage ) {
			if ( ! is_array( $provider_usage ) ) {
				continue;
			}

			foreach ( $provider_usage as $model_usage ) {
				if ( ! is_array( $model_usage ) ) {
					continue;
				}

				$totals['prompt_tokens']        += isset( $model_usage['prompt_tokens'] ) ? (int) $model_usage['prompt_tokens'] : 0;
				$totals['completion_tokens']    += isset( $model_usage['completion_tokens'] ) ? (int) $model_usage['completion_tokens'] : 0;
				$totals['cached_prompt_tokens'] += isset( $model_usage['cached_prompt_tokens'] ) ? (int) $model_usage['cached_prompt_tokens'] : 0;
				$totals['total_tokens']         += isset( $model_usage['total_tokens'] ) ? (int) $model_usage['total_tokens'] : 0;
				$totals['cached_tokens']        += isset( $model_usage['cached_tokens'] ) ? (int) $model_usage['cached_tokens'] : 0;
			}
		}

		$has_usage = ( $totals['prompt_tokens'] + $totals['completion_tokens'] + $totals['total_tokens'] + $totals['cached_tokens'] ) > 0;

		return array(
			'unavailable'    => false,
			'requires_login' => false,
			'has_usage'      => $has_usage,
			'totals'         => $totals,
		);
	}

	/**
	 * Format seconds into an initial timer string.
	 *
	 * @param int $duration_seconds Remaining seconds.
	 *
	 * @return string
	 */
	protected function format_time_remaining( $duration_seconds ) {
		$duration_seconds = max( 0, (int) $duration_seconds );
		$minutes          = floor( $duration_seconds / 60 );
		$seconds          = $duration_seconds % 60;

		return sprintf( '%d:%02d', $minutes, $seconds );
	}
}
