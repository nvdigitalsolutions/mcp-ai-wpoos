<?php
/**
 * Admin Page: Measurement Dashboard (read-only).
 *
 * Shows the current state of the measurement subsystem:
 *   - Registered metrics (with privacy tier and counter-metric pairing)
 *   - Registered verifiers (with independence profile)
 *   - Registered reward functions (with anti-gaming text)
 *   - Registered eval suites (with case counts)
 *   - The most recent events from the in-memory collector buffer
 *
 * This page writes nothing. It exists so operators can inspect what's
 * wired before PR 4 adds the OTel exporter and writable admin actions.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Measurement Dashboard Admin Page.
 */
class WP_MCP_AI_Admin_Measurement_Dashboard {

	/**
	 * Parent menu slug. Matches the existing plugin menu.
	 */
	const PARENT_SLUG = 'wp-mcp-ai-dashboard';

	/**
	 * This page's slug.
	 */
	const PAGE_SLUG = 'wp-mcp-ai-measurement';

	/**
	 * Maximum recent events to display (from the collector buffer).
	 */
	const MAX_RECENT_EVENTS = 50;

	/**
	 * Required capability. `manage_options` matches the rest of the plugin's
	 * admin surface and is the narrowest capability that still lets a site
	 * admin inspect measurement wiring.
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 30 );
	}

	/**
	 * Register submenu.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		// Only register if the parent menu exists; otherwise fall back to Tools.
		global $submenu;
		$parent = self::PARENT_SLUG;
		if ( empty( $submenu[ self::PARENT_SLUG ] ) && empty( $GLOBALS['admin_page_hooks'][ self::PARENT_SLUG ] ) ) {
			$parent = 'tools.php';
		}

		add_submenu_page(
			$parent,
			__( 'Measurement', 'mcp-ai-wpoos' ),
			__( 'Measurement', 'mcp-ai-wpoos' ),
			self::REQUIRED_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos' ) );
		}

		$metrics   = $this->get_metrics();
		$verifiers = $this->get_verifiers();
		$rewards   = $this->get_rewards();
		$suites    = $this->get_suites();
		$events    = $this->get_recent_events();

		?>
		<div class="wrap wp-mcp-ai-measurement-dashboard">
			<h1><?php esc_html_e( 'NV oOS Measurement', 'mcp-ai-wpoos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Read-only overview of registered metrics, verifiers, reward functions, eval suites, and the most recent events in the in-memory collector buffer.', 'mcp-ai-wpoos' ); ?>
			</p>

			<h2><?php esc_html_e( 'Summary', 'mcp-ai-wpoos' ); ?></h2>
			<ul class="wp-mcp-ai-measurement-summary">
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $metrics ) ) ); ?></strong>
					<?php esc_html_e( 'metrics registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $verifiers ) ) ); ?></strong>
					<?php esc_html_e( 'verifiers registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $rewards ) ) ); ?></strong>
					<?php esc_html_e( 'reward functions registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $suites ) ) ); ?></strong>
					<?php esc_html_e( 'eval suites registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $events ) ) ); ?></strong>
					<?php esc_html_e( 'recent events in buffer', 'mcp-ai-wpoos' ); ?>
				</li>
			</ul>

			<?php $this->render_metrics_table( $metrics ); ?>
			<?php $this->render_verifiers_table( $verifiers ); ?>
			<?php $this->render_rewards_table( $rewards ); ?>
			<?php $this->render_suites_table( $suites ); ?>
			<?php $this->render_events_table( $events ); ?>
		</div>
		<?php
	}

	/**
	 * Fetch metric definitions.
	 *
	 * @return array
	 */
	private function get_metrics() {
		if ( ! class_exists( 'WP_MCP_AI_Measurement_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Measurement_Registry::get_instance()->all();
	}

	/**
	 * Fetch verifiers.
	 *
	 * @return array
	 */
	private function get_verifiers() {
		if ( ! class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Verifier_Registry::get_instance()->all();
	}

	/**
	 * Fetch reward functions.
	 *
	 * @return array
	 */
	private function get_rewards() {
		if ( ! class_exists( 'WP_MCP_AI_Reward_Function_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Reward_Function_Registry::get_instance()->all();
	}

	/**
	 * Fetch suites.
	 *
	 * @return array
	 */
	private function get_suites() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Eval_Suite_Registry::get_instance()->all();
	}

	/**
	 * Fetch recent events.
	 *
	 * @return array
	 */
	private function get_recent_events() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			return array();
		}
		$buffered = WP_MCP_AI_Metric_Collector::get_instance()->buffered();
		$buffered = array_slice( $buffered, -1 * self::MAX_RECENT_EVENTS );
		return array_reverse( $buffered );
	}

	/**
	 * Metrics table.
	 *
	 * @param array $metrics Metrics.
	 * @return void
	 */
	private function render_metrics_table( array $metrics ) {
		?>
		<h2><?php esc_html_e( 'Metrics', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $metrics ) ) : ?>
			<p><em><?php esc_html_e( 'No metrics registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php return; endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Unit', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Privacy', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Counter', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $metrics as $id => $def ) : ?>
				<tr>
					<td><code><?php echo esc_html( $id ); ?></code></td>
					<td><?php echo esc_html( isset( $def['type'] ) ? $def['type'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['unit'] ) ? $def['unit'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['direction'] ) ? $def['direction'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['privacy_tier'] ) ? $def['privacy_tier'] : '' ); ?></td>
					<td><?php echo esc_html( ! empty( $def['counter_metric'] ) ? $def['counter_metric'] : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Verifiers table.
	 *
	 * @param array $verifiers Verifiers.
	 * @return void
	 */
	private function render_verifiers_table( array $verifiers ) {
		?>
		<h2><?php esc_html_e( 'Verifiers', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $verifiers ) ) : ?>
			<p><em><?php esc_html_e( 'No verifiers registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php return; endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Kind', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Disallowed Providers', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Disallowed Models', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $verifiers as $slug => $v ) :
				$profile = is_object( $v ) && method_exists( $v, 'get_independence_profile' ) ? $v->get_independence_profile() : array();
				$providers = isset( $profile['disallowed_providers'] ) ? (array) $profile['disallowed_providers'] : array();
				$models    = isset( $profile['disallowed_models'] ) ? (array) $profile['disallowed_models'] : array();
				?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( is_object( $v ) && method_exists( $v, 'get_label' ) ? $v->get_label() : '' ); ?></td>
					<td><?php echo esc_html( is_object( $v ) && method_exists( $v, 'get_kind' ) ? $v->get_kind() : '' ); ?></td>
					<td><?php echo esc_html( implode( ', ', $providers ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $models ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Rewards table.
	 *
	 * @param array $rewards Rewards.
	 * @return void
	 */
	private function render_rewards_table( array $rewards ) {
		?>
		<h2><?php esc_html_e( 'Reward Functions', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $rewards ) ) : ?>
			<p><em><?php esc_html_e( 'No reward functions registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php return; endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Output Range', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Counter Metric', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Anti-gaming', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rewards as $slug => $def ) : ?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( isset( $def['label'] ) ? $def['label'] : '' ); ?></td>
					<td>
						<?php
						echo esc_html(
							sprintf(
								'[%s, %s]',
								isset( $def['output_min'] ) ? (string) $def['output_min'] : '?',
								isset( $def['output_max'] ) ? (string) $def['output_max'] : '?'
							)
						);
						?>
					</td>
					<td><?php echo esc_html( ! empty( $def['counter_metric'] ) ? $def['counter_metric'] : '—' ); ?></td>
					<td><?php echo esc_html( isset( $def['anti_gaming'] ) ? $def['anti_gaming'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Suites table.
	 *
	 * @param array $suites Suites.
	 * @return void
	 */
	private function render_suites_table( array $suites ) {
		?>
		<h2><?php esc_html_e( 'Eval Suites', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $suites ) ) : ?>
			<p><em><?php esc_html_e( 'No eval suites registered. Use the wp_mcp_ai_register_eval_suites hook to add one.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php return; endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Cases', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $suites as $slug => $suite ) :
				$arr = $suite->to_array();
				?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( $arr['label'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $arr['case_count'] ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $arr['tags'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Recent events table.
	 *
	 * @param array $events Events.
	 * @return void
	 */
	private function render_events_table( array $events ) {
		?>
		<h2><?php esc_html_e( 'Recent Events (in-memory buffer)', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><em><?php esc_html_e( 'The collector buffer is empty on this request. Buffered events are per-request and in-memory only — see the exporter PR for persistent storage.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php return; endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Metric', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Recorded', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $events as $event ) :
				$metric_id = isset( $event['id'] ) ? (string) $event['id'] : '';
				$value     = isset( $event['value'] ) ? $event['value'] : '';
				$timestamp = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0;
				?>
				<tr>
					<td><code><?php echo esc_html( $metric_id ); ?></code></td>
					<td><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td>
					<td><?php echo esc_html( $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
