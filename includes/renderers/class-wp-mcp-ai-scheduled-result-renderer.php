<?php
/**
 * Scheduled Result Renderer.
 *
 * Single source of truth for the markup that backs both the Gutenberg
 * "Scheduled Result Display" block and the Elementor widget. Reads the
 * envelope produced by `WP_MCP_AI_Pro_Schedule_Manager` and renders one of
 * six canonical modes — summary-card / list / table / metric / timeline /
 * raw — with full output escaping.
 *
 * Lives in the base plugin so the block ships in any installation, but
 * gracefully degrades to a "Pro required" notice when the Pro addon is not
 * loaded.
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Scheduled_Result_Renderer' ) ) {
	/**
	 * Server-side renderer for scheduled-result envelopes.
	 */
	class WP_MCP_AI_Scheduled_Result_Renderer {

		/**
		 * Canonical render modes.
		 */
		const MODES = array( 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' );

		/**
		 * Render a scheduled-result tile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $schedule_id Schedule ID.
		 * @param array  $opts {
		 *     Render options.
		 *
		 *     @type string $render_mode      One of self::MODES. Default 'summary-card'.
		 *     @type string $title            Tile title. Defaults to the schedule name.
		 *     @type bool   $show_last_run    Whether to show the last-run timestamp.
		 *     @type int    $refresh_interval Auto-refresh interval in seconds (0 = off).
		 *     @type int    $truncate_chars   Soft-truncate raw text at N chars.
		 * }
		 * @return string Sanitized HTML.
		 */
		public static function render( $schedule_id, array $opts = array() ) {
			$schedule_id = sanitize_text_field( (string) $schedule_id );
			if ( '' === $schedule_id ) {
				return self::wrap_notice( __( 'No schedule selected.', 'mcp-ai-wpoos' ) );
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
				return self::wrap_notice( __( 'The Scheduled Result widget requires the NV oOS Pro addon.', 'mcp-ai-wpoos' ) );
			}

			$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
			if ( ! $schedule ) {
				return self::wrap_notice( __( 'Schedule not found.', 'mcp-ai-wpoos' ) );
			}

			$envelope = WP_MCP_AI_Pro_Schedule_Manager::get_latest_result( $schedule_id );

			// Apply public-render gating for non-authenticated visitors.
			$is_authed = is_user_logged_in() && current_user_can( 'read_private_posts' );
			if ( ! $is_authed ) {
				if ( empty( $schedule['display']['public_render'] ) ) {
					return self::wrap_notice( __( 'This schedule result is not publicly visible.', 'mcp-ai-wpoos' ) );
				}
				if ( $envelope ) {
					$envelope = WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $envelope, $schedule );
				}
			}

			if ( ! $envelope ) {
				return self::wrap_notice(
					__( 'No runs have been recorded yet for this schedule.', 'mcp-ai-wpoos' ),
					isset( $schedule['name'] ) ? $schedule['name'] : ''
				);
			}

			$defaults = array(
				'render_mode'      => isset( $schedule['display']['widget_defaults']['render_mode'] )
					? (string) $schedule['display']['widget_defaults']['render_mode']
					: 'summary-card',
				'title'            => isset( $schedule['name'] ) ? (string) $schedule['name'] : '',
				'show_last_run'    => true,
				'refresh_interval' => isset( $schedule['display']['widget_defaults']['refresh_interval'] )
					? (int) $schedule['display']['widget_defaults']['refresh_interval']
					: 0,
				'truncate_chars'   => 0,
			);
			$opts     = array_merge( $defaults, $opts );

			$mode = in_array( $opts['render_mode'], self::MODES, true ) ? $opts['render_mode'] : 'summary-card';

			$body = '';
			switch ( $mode ) {
				case 'list':
					$body = self::render_list( $envelope );
					break;
				case 'table':
					$body = self::render_table( $envelope );
					break;
				case 'metric':
					$body = self::render_metric( $envelope );
					break;
				case 'timeline':
					$body = self::render_timeline( $schedule_id );
					break;
				case 'raw':
					$body = self::render_raw( $envelope, (int) $opts['truncate_chars'], $is_authed );
					break;
				case 'summary-card':
				default:
					$body = self::render_summary_card( $envelope );
					break;
			}

			$header = '';
			$title  = (string) $opts['title'];
			if ( '' !== $title ) {
				$header .= '<h3 class="mcp-ai-scheduled-result__title">' . esc_html( $title ) . '</h3>';
			}
			if ( $opts['show_last_run'] && ! empty( $envelope['generated_at'] ) ) {
				$header .= '<p class="mcp-ai-scheduled-result__meta">' . esc_html(
					sprintf(
						/* translators: %s: localized datetime */
						__( 'Last run: %s', 'mcp-ai-wpoos' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $envelope['generated_at'] )
					)
				) . '</p>';
			}

			$refresh_attr = '';
			$interval     = max( 0, (int) $opts['refresh_interval'] );
			if ( $interval > 0 ) {
				$refresh_attr = sprintf(
					' data-mcp-ai-refresh-interval="%d" data-mcp-ai-refresh-schedule="%s"',
					$interval,
					esc_attr( $schedule_id )
				);
			}

			return sprintf(
				'<div class="mcp-ai-scheduled-result mcp-ai-scheduled-result--%1$s"%2$s>%3$s<div class="mcp-ai-scheduled-result__body">%4$s</div></div>',
				esc_attr( $mode ),
				$refresh_attr,
				$header,
				$body
			);
		}

		/**
		 * Render a "Pro required" / "no data" notice.
		 *
		 * @param string $message Notice text.
		 * @param string $title   Optional title.
		 * @return string HTML.
		 */
		protected static function wrap_notice( $message, $title = '' ) {
			$header = '';
			if ( '' !== $title ) {
				$header = '<h3 class="mcp-ai-scheduled-result__title">' . esc_html( $title ) . '</h3>';
			}
			return sprintf(
				'<div class="mcp-ai-scheduled-result mcp-ai-scheduled-result--notice">%1$s<p class="mcp-ai-scheduled-result__notice">%2$s</p></div>',
				$header,
				esc_html( $message )
			);
		}

		/**
		 * Summary-card mode.
		 *
		 * @param array $envelope Envelope.
		 * @return string HTML.
		 */
		protected static function render_summary_card( array $envelope ) {
			$summary = isset( $envelope['summary'] ) ? (string) $envelope['summary'] : '';
			$status  = isset( $envelope['status'] ) ? (string) $envelope['status'] : '';
			$badge   = '';
			if ( '' !== $status ) {
				$badge = sprintf(
					'<span class="mcp-ai-scheduled-result__badge mcp-ai-scheduled-result__badge--%1$s">%2$s</span>',
					esc_attr( $status ),
					esc_html( $status )
				);
			}
			return sprintf(
				'<p class="mcp-ai-scheduled-result__summary">%1$s %2$s</p>',
				esc_html( $summary ),
				$badge
			);
		}

		/**
		 * List mode — renders data.items or data.steps as a <ol>.
		 *
		 * @param array $envelope Envelope.
		 * @return string HTML.
		 */
		protected static function render_list( array $envelope ) {
			$items = array();
			if ( isset( $envelope['data']['items'] ) && is_array( $envelope['data']['items'] ) ) {
				$items = $envelope['data']['items'];
			} elseif ( isset( $envelope['data']['steps'] ) && is_array( $envelope['data']['steps'] ) ) {
				foreach ( $envelope['data']['steps'] as $step ) {
					if ( is_array( $step ) && isset( $step['label'] ) ) {
						$items[] = (string) $step['label'];
					} elseif ( is_string( $step ) ) {
						$items[] = $step;
					}
				}
			}

			if ( empty( $items ) ) {
				return self::render_summary_card( $envelope );
			}

			$html = '<ol class="mcp-ai-scheduled-result__list">';
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$label = isset( $item['title'] )
						? $item['title']
						: ( isset( $item['label'] ) ? $item['label'] : wp_json_encode( $item ) );
					$html .= '<li>' . esc_html( (string) $label ) . '</li>';
				} else {
					$html .= '<li>' . esc_html( (string) $item ) . '</li>';
				}
			}
			$html .= '</ol>';
			return $html;
		}

		/**
		 * Table mode — renders data.rows[] with optional data.columns[].
		 *
		 * @param array $envelope Envelope.
		 * @return string HTML.
		 */
		protected static function render_table( array $envelope ) {
			$rows    = isset( $envelope['data']['rows'] ) && is_array( $envelope['data']['rows'] ) ? $envelope['data']['rows'] : array();
			$columns = isset( $envelope['data']['columns'] ) && is_array( $envelope['data']['columns'] ) ? $envelope['data']['columns'] : array();
			if ( empty( $rows ) ) {
				return self::render_summary_card( $envelope );
			}
			if ( empty( $columns ) ) {
				$first   = is_array( $rows[0] ) ? $rows[0] : array();
				$columns = array_keys( $first );
			}

			$html = '<table class="mcp-ai-scheduled-result__table"><thead><tr>';
			foreach ( $columns as $col ) {
				$html .= '<th>' . esc_html( (string) $col ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$html .= '<tr>';
				foreach ( $columns as $col ) {
					$cell  = is_array( $row ) && isset( $row[ $col ] ) ? $row[ $col ] : '';
					$html .= '<td>' . esc_html( is_scalar( $cell ) ? (string) $cell : wp_json_encode( $cell ) ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
			return $html;
		}

		/**
		 * Metric mode — big number + delta vs. previous.
		 *
		 * @param array $envelope Envelope.
		 * @return string HTML.
		 */
		protected static function render_metric( array $envelope ) {
			$value = isset( $envelope['data']['value'] ) ? $envelope['data']['value'] : null;
			$label = isset( $envelope['data']['label'] ) ? (string) $envelope['data']['label'] : '';
			$delta = isset( $envelope['data']['delta'] ) ? $envelope['data']['delta'] : null;
			if ( null === $value ) {
				return self::render_summary_card( $envelope );
			}
			$delta_html = '';
			if ( null !== $delta && is_scalar( $delta ) ) {
				$direction  = ( (float) $delta >= 0 ) ? 'up' : 'down';
				$delta_html = sprintf(
					'<span class="mcp-ai-scheduled-result__delta mcp-ai-scheduled-result__delta--%1$s">%2$s</span>',
					esc_attr( $direction ),
					esc_html( (string) $delta )
				);
			}
			return sprintf(
				'<div class="mcp-ai-scheduled-result__metric"><span class="mcp-ai-scheduled-result__value">%1$s</span>%2$s<span class="mcp-ai-scheduled-result__label">%3$s</span></div>',
				esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ),
				$delta_html,
				esc_html( $label )
			);
		}

		/**
		 * Timeline mode — last N runs as pass/fail strip.
		 *
		 * @param string $schedule_id Schedule ID.
		 * @return string HTML.
		 */
		protected static function render_timeline( $schedule_id ) {
			$results = WP_MCP_AI_Pro_Schedule_Manager::get_results( $schedule_id, 20 );
			if ( empty( $results ) ) {
				return self::wrap_notice( __( 'No history yet.', 'mcp-ai-wpoos' ) );
			}
			$html = '<ul class="mcp-ai-scheduled-result__timeline" aria-label="' . esc_attr__( 'Recent runs', 'mcp-ai-wpoos' ) . '">';
			foreach ( array_reverse( $results ) as $r ) {
				$status = isset( $r['status'] ) ? (string) $r['status'] : 'unknown';
				$at     = isset( $r['generated_at'] ) ? (int) $r['generated_at'] : 0;
				$html  .= sprintf(
					'<li class="mcp-ai-scheduled-result__pip mcp-ai-scheduled-result__pip--%1$s" title="%2$s"></li>',
					esc_attr( $status ),
					esc_attr( $at ? wp_date( 'Y-m-d H:i', $at ) : '' )
				);
			}
			$html .= '</ul>';
			return $html;
		}

		/**
		 * Raw mode — text or HTML-safe markup.
		 *
		 * @param array $envelope    Envelope.
		 * @param int   $truncate    Optional truncation length.
		 * @param bool  $is_authed   Whether the viewer is authenticated.
		 * @return string HTML.
		 */
		protected static function render_raw( array $envelope, $truncate = 0, $is_authed = false ) {
			$render = isset( $envelope['render'] ) ? (string) $envelope['render'] : 'text';

			$text = '';
			if ( isset( $envelope['data']['response'] ) ) {
				$text = (string) $envelope['data']['response'];
			} elseif ( isset( $envelope['summary'] ) ) {
				$text = (string) $envelope['summary'];
			}

			if ( $truncate > 0 && function_exists( 'mb_substr' ) && mb_strlen( $text ) > $truncate ) {
				$text = mb_substr( $text, 0, $truncate ) . '…';
			} elseif ( $truncate > 0 && strlen( $text ) > $truncate ) {
				$text = substr( $text, 0, $truncate ) . '…';
			}

			if ( 'html-safe' === $render && $is_authed ) {
				return '<div class="mcp-ai-scheduled-result__raw mcp-ai-scheduled-result__raw--html">' . wp_kses_post( $text ) . '</div>';
			}

			if ( 'markdown' === $render ) {
				return '<pre class="mcp-ai-scheduled-result__raw mcp-ai-scheduled-result__raw--markdown">' . esc_html( $text ) . '</pre>';
			}

			return '<pre class="mcp-ai-scheduled-result__raw mcp-ai-scheduled-result__raw--text">' . esc_html( $text ) . '</pre>';
		}
	}
}
