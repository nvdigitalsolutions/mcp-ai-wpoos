<?php
/**
 * Skills admin — registers the Skills tab in the core settings page.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Skills\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Skills\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

/**
 * Skills management admin UI.
 */
final class SkillsAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		SettingsRegistry::register_tab( 'skills', __( 'Skills', 'nvoos-graphify-ai-platform' ) );
	}
}
