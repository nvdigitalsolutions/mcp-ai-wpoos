<?php
/**
 * Slash Commands Dashboard Section — powers the "Slash Commands" tab.
 *
 * Lists registered slash commands, provides a test console placeholder,
 * and shows workflow integration status.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\SlashCommands\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;
use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandBridge;

/**
 * Slash commands dashboard section.
 */
final class SlashCommandsDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_slash_commands_dashboard';
	}

	public function get_title(): string {
		return __( 'Slash Commands', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'slash_commands';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array();
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$commands       = SlashCommandBridge::getAll( false );
		$user_commands  = SlashCommandBridge::getAll( true );
		$base_available = function_exists( 'wp_mcp_ai_get_slash_commands' );

		?>
		<h2><?php esc_html_e( 'Slash Commands', 'nvoos-content-graph-ai-platform' ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( count( $commands ) ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Total Commands', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( count( $user_commands ) ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Available to You', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $base_available ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $base_available ? '✓' : '✗'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Base Plugin Bridge', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<h3><?php esc_html_e( 'About Slash Commands', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;margin-bottom:1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'Slash commands provide a natural-language interface for executing AI operations. Type "/" in any chat interface to see available commands. Commands can trigger tools, run workflows, query the knowledge graph, or interact with external services.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>

		<?php if ( ! empty( $commands ) ) : ?>
			<h3><?php esc_html_e( 'Registered Commands', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
				<thead>
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Command', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Category', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $commands as $name => $config ) : ?>
						<tr>
							<td><code>/<?php echo esc_html( $name ); ?></code></td>
							<td><?php echo esc_html( $config['description'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $config['category'] ?? __( 'General', 'nvoos-content-graph-ai-platform' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:2rem;text-align:center;max-width:800px;">
				<h3><?php esc_html_e( 'No slash commands registered', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php esc_html_e( 'Slash commands are provided by the base plugin. Ensure the base plugin is active and its slash command module is loaded.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}
}
