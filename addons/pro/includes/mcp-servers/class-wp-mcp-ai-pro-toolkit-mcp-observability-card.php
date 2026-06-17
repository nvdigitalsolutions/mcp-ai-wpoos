<?php
/**
 * Toolkit MCP Servers — Observability Dashboard Card.
 *
 * Injects a summary card into the NV oOS Performance / Orchestration admin
 * section via the `wp_mcp_ai_performance_section_after_components` action.
 * The card shows:
 *   - Total registered servers + enabled count.
 *   - Last cross-mount read timestamp (from the audit log).
 *   - Top-3 most active consumer servers (last 24 h from audit log).
 *   - Link to the full audit log REST endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card
 *
 * Instantiate once from mcp-servers-init.php; the constructor registers the hook.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card {

	/**
	 * Bind WordPress hooks.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action(
			'wp_mcp_ai_performance_section_after_components',
			array( $this, 'render_card' )
		);
	}

	/**
	 * Render the observability card HTML.
	 *
	 * Gracefully no-ops when registry or audit log classes are unavailable
	 * (e.g. base-only mode or during unit tests without full bootstrap).
	 *
	 * @since 1.4.0
	 */
	public function render_card() {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' )
			|| ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Audit_Log' )
		) {
			return;
		}

		$servers       = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->all();
		$total         = count( $servers );
		$enabled_count = 0;
		foreach ( $servers as $server ) {
			if ( $server instanceof WP_MCP_AI_Toolkit_Server_Base && $server->is_enabled() ) {
				++$enabled_count;
			}
		}

		// Pull the last 50 audit entries to compute statistics.
		$audit_entries = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance()->get_entries( 50 );

		// Last access time.
		$last_access = '';
		if ( ! empty( $audit_entries ) ) {
			$last_ts     = max( array_column( $audit_entries, 'ts' ) );
			$last_access = human_time_diff( $last_ts, time() ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' );
		}

		// Top consumers in the last 24 h.
		$since         = time() - DAY_IN_SECONDS;
		$recent        = array_filter(
			$audit_entries,
			static function ( $entry ) use ( $since ) {
				return isset( $entry['ts'] ) && $entry['ts'] >= $since;
			}
		);
		$consumer_hits = array();
		foreach ( $recent as $entry ) {
			$consumer                   = isset( $entry['consumer'] ) ? (string) $entry['consumer'] : 'unknown';
			$consumer_hits[ $consumer ] = ( $consumer_hits[ $consumer ] ?? 0 ) + 1;
		}
		arsort( $consumer_hits );
		$top_consumers = array_slice( $consumer_hits, 0, 3, true );

		$audit_url = rest_url( 'mcp-ai-pro/v1/mcp-audit' );

		?>
		<!-- Toolkit MCP Servers Observability Card (Phase 5) -->
		<div class="wp-mcp-ai-toolkit-mcp-obs-card" style="
			margin-top:24px;
			padding:16px 20px;
			background:#fff;
			border:1px solid #c3c4c7;
			border-left:4px solid #2271b1;
			border-radius:3px;
		">
			<h2 style="margin-top:0;">
				<?php esc_html_e( 'Toolkit MCP Servers', 'mcp-ai-wpoos-pro' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Real-time summary of per-toolkit MCP server activity.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;">
				<!-- Registered -->
				<div class="stat-card" style="min-width:120px;">
					<h3><?php esc_html_e( 'Registered', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="stat-value" style="font-size:2em;font-weight:600;"><?php echo esc_html( $total ); ?></div>
				</div>

				<!-- Enabled -->
				<div class="stat-card" style="min-width:120px;">
					<h3><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="stat-value" style="font-size:2em;font-weight:600;color:<?php echo $enabled_count > 0 ? '#46b450' : '#888'; ?>;">
						<?php echo esc_html( $enabled_count ); ?>
					</div>
				</div>

				<!-- Last Access -->
				<div class="stat-card" style="min-width:160px;">
					<h3><?php esc_html_e( 'Last Cross-Mount Read', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="stat-value" style="font-size:1.1em;">
						<?php
						echo '' !== $last_access
							? esc_html( $last_access )
							: esc_html__( 'No activity yet', 'mcp-ai-wpoos-pro' );
						?>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $top_consumers ) ) : ?>
				<h3 style="margin-bottom:6px;">
					<?php esc_html_e( 'Top Consumers (last 24 h)', 'mcp-ai-wpoos-pro' ); ?>
				</h3>
				<table class="widefat fixed striped" style="max-width:480px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Server', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Cross-Mount Reads', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_consumers as $slug => $hits ) : ?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><?php echo esc_html( $hits ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-toolkit-mcp-servers' ) ); ?>"
					class="button button-secondary">
					<?php esc_html_e( 'Manage MCP Servers', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( $audit_url ); ?>"
					target="_blank"
					style="margin-left:8px;font-size:12px;">
					<?php esc_html_e( 'Audit Log (REST)', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
