<?php
/**
 * Admin telemetry page for the markup subsystem.
 *
 * Renders the aggregate counters maintained by
 * {@see WP_MCP_AI_Markup_Telemetry} as a read-only dashboard under the
 * NV oOS admin menu, with a single `admin_post_*` action that lets an
 * administrator reset the counters.
 *
 * The page is designed to mirror the conventions established by the
 * cron and DLQ admin managers: server-rendered HTML, a shared
 * monitor stylesheet, scoped inline CSS for the markup-specific
 * badges, and a flash notice surfaced via a `?reset=1` query arg
 * after redirect.
 *
 * @package WP_MCP_AI
 * @subpackage Admin
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Admin_Markup_Telemetry_Page.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Admin_Markup_Telemetry_Page {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'wp-mcp-ai-markup-telemetry';

	/**
	 * `admin_post_*` action used by the reset form.
	 */
	const RESET_ACTION = 'wp_mcp_ai_reset_markup_telemetry';

	/**
	 * Admin page hook suffix once registered.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Wire admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 16 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_page_styles' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	/**
	 * Add the submenu page under the NV oOS dashboard.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'NV oOS Markup Telemetry', 'mcp-ai-wpoos' ),
			__( 'Markup Telemetry', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scoped inline CSS for this admin page only.
	 *
	 * Uses wp_add_inline_style() rather than a raw <style> echo to comply
	 * with WordPress.org coding standards.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_page_styles( $hook_suffix ) {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}
		// Register a handle with no source file; wp_add_inline_style() will
		// emit the CSS inline when this handle is printed.
		wp_register_style( 'wp-mcp-ai-markup-telemetry', false, array(), WP_MCP_AI_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version passed via WP_MCP_AI_VERSION constant.
		wp_enqueue_style( 'wp-mcp-ai-markup-telemetry' );
		wp_add_inline_style( 'wp-mcp-ai-markup-telemetry', $this->build_inline_css() );
	}

	/**
	 * Handle the `Reset counters` form submission.
	 *
	 * @throws WPDieException When running under PHPUnit and the redirect is blocked.
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset markup telemetry.', 'mcp-ai-wpoos' ) );
		}

		check_admin_referer( self::RESET_ACTION );

		if ( class_exists( 'WP_MCP_AI_Markup_Telemetry' ) ) {
			WP_MCP_AI_Markup_Telemetry::reset();
		}

		$redirect = add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'reset' => '1',
			),
			admin_url( 'admin.php' )
		);

		if ( wp_safe_redirect( $redirect ) ) {
			exit;
		}

		// Under PHPUnit the redirect is blocked and the suite-wide contract
		// turns the failed redirect into a catchable exception instead of a
		// process-killing bare exit.
		if ( defined( 'WP_MCP_AI_TESTS_RUNNING' ) && WP_MCP_AI_TESTS_RUNNING && class_exists( 'WPDieException' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception message is not rendered anywhere; it only aborts the request flow under tests.
			throw new WPDieException( $redirect );
		}
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view markup telemetry.', 'mcp-ai-wpoos' ) );
		}

		$summary = class_exists( 'WP_MCP_AI_Markup_Telemetry' )
			? WP_MCP_AI_Markup_Telemetry::get_summary()
			: array(
				'counts'    => array(),
				'tools'     => array(),
				'modes'     => array(),
				'last_seen' => array(),
			);

		$counts    = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$tools     = isset( $summary['tools'] ) && is_array( $summary['tools'] ) ? $summary['tools'] : array();
		$modes     = isset( $summary['modes'] ) && is_array( $summary['modes'] ) ? $summary['modes'] : array();
		$last_seen = isset( $summary['last_seen'] ) && is_array( $summary['last_seen'] ) ? $summary['last_seen'] : array();

		$created   = isset( $counts['created'] ) ? (int) $counts['created'] : 0;
		$completed = isset( $counts['completed'] ) ? (int) $counts['completed'] : 0;
		$cancelled = isset( $counts['cancelled'] ) ? (int) $counts['cancelled'] : 0;

		$completion_rate = $created > 0 ? ( $completed / $created ) * 100 : 0.0;

		$reset_done = isset( $_GET['reset'] ) && '1' === sanitize_key( wp_unslash( $_GET['reset'] ) );

		echo '<div class="wrap wp-mcp-ai-markup-telemetry">';
		printf( '<h1>%s</h1>', esc_html__( 'NV oOS Markup Telemetry', 'mcp-ai-wpoos' ) );

		if ( $reset_done ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Markup telemetry counters have been reset.', 'mcp-ai-wpoos' )
			);
		}

		echo '<p class="description">';
		esc_html_e( 'Aggregate counters for markup-aware tool calls — refreshed every time the loop interceptor or the admin fallback page resolves a request. Counts are bounded; see the slash command `/markup-stats` for the same data inside chat.', 'mcp-ai-wpoos' );
		echo '</p>';

		// Totals row.
		echo '<div class="wp-mcp-ai-mt__cards">';
		$this->render_card( __( 'Created', 'mcp-ai-wpoos' ), $created );
		$this->render_card( __( 'Completed', 'mcp-ai-wpoos' ), $completed );
		$this->render_card( __( 'Cancelled', 'mcp-ai-wpoos' ), $cancelled );
		$this->render_card(
			__( 'Completion rate', 'mcp-ai-wpoos' ),
			number_format_i18n( $completion_rate, 1 ) . '%',
			$this->rate_class( $completion_rate, $created )
		);
		echo '</div>';

		// All-counters strip.
		echo '<h2>' . esc_html__( 'Outcomes', 'mcp-ai-wpoos' ) . '</h2>';
		echo '<table class="widefat striped wp-mcp-ai-mt__table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Outcome', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Count', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'mcp-ai-wpoos' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( WP_MCP_AI_Markup_Telemetry::outcomes() as $outcome ) {
			$value = isset( $counts[ $outcome ] ) ? (int) $counts[ $outcome ] : 0;
			$ts    = isset( $last_seen[ $outcome ] ) ? (int) $last_seen[ $outcome ] : 0;
			echo '<tr>';
			echo '<td><code>' . esc_html( $outcome ) . '</code></td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $value ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_last_seen( $ts ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Per-tool breakdown.
		$this->render_breakdown_table(
			__( 'By tool', 'mcp-ai-wpoos' ),
			__( 'Tool slug', 'mcp-ai-wpoos' ),
			$tools
		);

		// Per-mode breakdown.
		$this->render_breakdown_table(
			__( 'By mode', 'mcp-ai-wpoos' ),
			__( 'Mode', 'mcp-ai-wpoos' ),
			$modes
		);

		// Reset form.
		echo '<h2>' . esc_html__( 'Maintenance', 'mcp-ai-wpoos' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Reset all markup telemetry counters? This cannot be undone.', 'mcp-ai-wpoos' ) ) . '\');">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RESET_ACTION ) . '" />';
		wp_nonce_field( self::RESET_ACTION );
		submit_button( __( 'Reset counters', 'mcp-ai-wpoos' ), 'delete', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render a single summary card.
	 *
	 * @param string     $label      Card label.
	 * @param int|string $value      Card value (int or pre-formatted string).
	 * @param string     $modifier   Optional CSS modifier suffix (e.g. `--ok`, `--warn`).
	 */
	private function render_card( $label, $value, $modifier = '' ) {
		$class = 'wp-mcp-ai-mt__card';
		if ( '' !== $modifier ) {
			$class .= ' wp-mcp-ai-mt__card' . $modifier;
		}
		$display = is_int( $value ) ? number_format_i18n( $value ) : (string) $value;
		echo '<div class="' . esc_attr( $class ) . '">';
		echo '<div class="wp-mcp-ai-mt__card-label">' . esc_html( $label ) . '</div>';
		echo '<div class="wp-mcp-ai-mt__card-value">' . esc_html( $display ) . '</div>';
		echo '</div>';
	}

	/**
	 * Render a per-tool / per-mode breakdown table.
	 *
	 * @param string $title       Section heading.
	 * @param string $slug_label  Header for the slug column.
	 * @param array  $rows        Map of slug => outcome counts.
	 */
	private function render_breakdown_table( $title, $slug_label, array $rows ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No data yet.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		// Sort by `created` desc, tie-break by completed.
		uasort(
			$rows,
			static function ( $a, $b ) {
				$ac = isset( $a['created'] ) ? (int) $a['created'] : 0;
				$bc = isset( $b['created'] ) ? (int) $b['created'] : 0;
				if ( $ac === $bc ) {
					$ad = isset( $a['completed'] ) ? (int) $a['completed'] : 0;
					$bd = isset( $b['completed'] ) ? (int) $b['completed'] : 0;
					return $bd - $ad;
				}
				return $bc - $ac;
			}
		);

		echo '<table class="widefat striped wp-mcp-ai-mt__table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html( $slug_label ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Created', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Completed', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Cancelled', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Invalid', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Tool error', 'mcp-ai-wpoos' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Completion %', 'mcp-ai-wpoos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $slug => $row ) {
			$row_created   = isset( $row['created'] ) ? (int) $row['created'] : 0;
			$row_completed = isset( $row['completed'] ) ? (int) $row['completed'] : 0;
			$row_pct       = $row_created > 0 ? ( $row_completed / $row_created ) * 100 : 0.0;

			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $slug ) . '</code></td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $row_created ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $row_completed ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( isset( $row['cancelled'] ) ? (int) $row['cancelled'] : 0 ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( isset( $row['invalid'] ) ? (int) $row['invalid'] : 0 ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( isset( $row['tool_error'] ) ? (int) $row['tool_error'] : 0 ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $row_pct, 1 ) ) . '%</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Determine the visual modifier class for the completion-rate card.
	 *
	 * @param float $rate    Completion rate as a percentage.
	 * @param int   $created Total created requests.
	 * @return string CSS modifier suffix (`--ok`, `--warn`, `--err`, or empty).
	 */
	private function rate_class( $rate, $created ) {
		if ( $created <= 0 ) {
			return '';
		}
		if ( $rate >= 75 ) {
			return '--ok';
		}
		if ( $rate >= 40 ) {
			return '--warn';
		}
		return '--err';
	}

	/**
	 * Format a unix timestamp for display, with a "never" placeholder.
	 *
	 * @param int $ts Unix timestamp.
	 * @return string
	 */
	private function format_last_seen( $ts ) {
		if ( $ts <= 0 ) {
			return __( 'never', 'mcp-ai-wpoos' );
		}
		$diff = human_time_diff( $ts, time() );
		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 minutes". */
			__( '%s ago', 'mcp-ai-wpoos' ),
			$diff
		);
	}

	/**
	 * Build the scoped inline CSS for the page.
	 *
	 * @return string
	 */
	private function build_inline_css() {
		return implode(
			'',
			array(
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__cards{display:flex;flex-wrap:wrap;gap:1rem;margin:1.5rem 0;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card{flex:1 1 180px;min-width:160px;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card-value{font-size:1.75rem;font-weight:600;color:#1d2327;line-height:1.2;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card--ok{border-left:4px solid #2e7d32;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card--warn{border-left:4px solid #c77700;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__card--err{border-left:4px solid #b32d2e;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__table{margin:0.5rem 0 2rem;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__table th.num,.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__table td.num{text-align:right;font-variant-numeric:tabular-nums;}',
				'.wp-mcp-ai-markup-telemetry .wp-mcp-ai-mt__table code{background:transparent;padding:0;}',
			)
		);
	}
}
