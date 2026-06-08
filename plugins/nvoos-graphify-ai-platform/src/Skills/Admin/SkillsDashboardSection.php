<?php
/**
 * Skills Dashboard Section — powers the "Skills" tab on the NV Platform dashboard.
 *
 * Displays registered skill packs, skill counts, and links to skill
 * management in the base plugin's admin.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Skills\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Skills\Admin;

use NvoosGraphifyAiPlatform\Admin\PlatformSection;

/**
 * Skills dashboard section.
 */
final class SkillsDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_skills_dashboard';
	}

	public function get_title(): string {
		return __( 'Skills', 'nvoos-graphify-ai-platform' );
	}

	public function get_tab(): string {
		return 'skills';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array();
	}

	/**
	 * Render the skills overview panel.
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		$skill_count      = $this->countSkills();
		$skill_packs      = $this->getSkillPacks();
		$profession_count = $this->countProfessions();

		?>
		<h2><?php esc_html_e( 'Skill Management', 'nvoos-graphify-ai-platform' ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $skill_count ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Total Skills', 'nvoos-graphify-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( count( $skill_packs ) ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Skill Packs', 'nvoos-graphify-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $profession_count ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Professions with Skills', 'nvoos-graphify-ai-platform' ); ?></div>
			</div>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Manage Professions', 'nvoos-graphify-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ); ?>" class="button">
				<?php esc_html_e( 'Add Profession', 'nvoos-graphify-ai-platform' ); ?>
			</a>
		</div>

		<h3><?php esc_html_e( 'How Skills Work', 'nvoos-graphify-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'Skills are reusable AI instruction sets defined in SKILL.md files. Each skill declares its own description, tools, and context loading rules. Professions (agent templates) reference skills to build specialized agents.', 'nvoos-graphify-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'Skill packs are collections of related skills that can be imported, exported, and shared across agents. The base plugin manages skill registration and parsing; the Platform addon bridges this into the knowledge graph.', 'nvoos-graphify-ai-platform' ); ?></p>
		</div>

		<?php if ( ! empty( $skill_packs ) ) : ?>
			<h3><?php esc_html_e( 'Registered Skill Packs', 'nvoos-graphify-ai-platform' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="max-width:800px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Pack', 'nvoos-graphify-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Skills', 'nvoos-graphify-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Source', 'nvoos-graphify-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $skill_packs as $pack ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $pack['name'] ?? __( 'Unknown', 'nvoos-graphify-ai-platform' ) ); ?></strong></td>
							<td><?php echo absint( $pack['count'] ?? 0 ); ?></td>
							<td><?php echo esc_html( $pack['source'] ?? __( 'Built-in', 'nvoos-graphify-ai-platform' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:2rem;text-align:center;max-width:800px;">
				<h3><?php esc_html_e( 'No skill packs registered', 'nvoos-graphify-ai-platform' ); ?></h3>
				<p><?php esc_html_e( 'Skill packs will appear here once the base plugin loads its skill registry. Create professions to assign skills to agent templates.', 'nvoos-graphify-ai-platform' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Count total registered skills from the base plugin.
	 */
	private function countSkills(): int {
		if ( function_exists( 'wp_mcp_ai_get_registered_skills' ) ) {
			$skills = wp_mcp_ai_get_registered_skills();
			return is_array( $skills ) ? count( $skills ) : 0;
		}
		return 0;
	}

	/**
	 * Get skill pack information from the base plugin.
	 */
	private function getSkillPacks(): array {
		if ( function_exists( 'wp_mcp_ai_get_skill_packs' ) ) {
			$packs = wp_mcp_ai_get_skill_packs();
			return is_array( $packs ) ? $packs : array();
		}
		return array();
	}

	/**
	 * Count professions with assigned skills.
	 */
	private function countProfessions(): int {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'mcp_ai_profession' );
		return is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
	}
}
