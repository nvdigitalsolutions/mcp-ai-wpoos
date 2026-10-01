<?php
/**
 * PHPUnit bootstrap for the NV oOS Design System addon.
 *
 * The WordPress test environment itself is bootstrapped by the root test
 * suite; this file's job is to define the addon's constants and require its
 * PHP classes so addon tests can run inside the shared suite.
 *
 * Only constants and class definitions are loaded — no file-scope hooks —
 * so it is safe to require from the root bootstrap.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow the file to be loaded from a phpunit.xml that bootstraps the
	// WordPress test environment first.
	return;
}

if ( ! defined( 'NVOOS_DESIGN_SYSTEM_PATH' ) ) {
	define( 'NVOOS_DESIGN_SYSTEM_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'NVOOS_DESIGN_SYSTEM_URL' ) ) {
	define( 'NVOOS_DESIGN_SYSTEM_URL', 'http://example.org/wp-content/plugins/nvoos-design-system/' );
}

if ( ! defined( 'NVOOS_DESIGN_SYSTEM_VERSION' ) ) {
	define( 'NVOOS_DESIGN_SYSTEM_VERSION', '0.3.0' );
}

if ( ! defined( 'NVOOS_DESIGN_SYSTEM_FILE' ) ) {
	define( 'NVOOS_DESIGN_SYSTEM_FILE', NVOOS_DESIGN_SYSTEM_PATH . 'nvoos-design-system.php' );
}

require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/base/class-nvoos-nds-data-token.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/base/class-nvoos-nds-data-preset.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-preset-minimal.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-preset-ecommerce.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-preset-directory.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-plugin.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-token-registry.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-css-generator.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-assets.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-dtcg-exporter.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-template-cpt.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-template-registry.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-renderer.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-wrapper.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-auditor.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-generator.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-paper-store.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-seeder.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/emails/class-nvoos-nds-email-admin.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/admin/class-nvoos-nds-admin-page.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/integrations/class-nvoos-nds-integration-jsf.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/integrations/class-nvoos-nds-integration-jetengine.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/integrations/class-nvoos-nds-integration-jfb.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/integrations/class-nvoos-nds-integration-elementor.php';
require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/integrations/class-nvoos-nds-integration-woocommerce.php';

// The tool classes implement the NV oOS tool contracts (interface + trait),
// which live in the base plugin and may be absent in standalone suites that
// boot through the shared root bootstrap. Load them only when the contracts
// exist; the tools test skips itself in the same situation.
if ( interface_exists( 'WP_MCP_AI_Tool_Interface' ) && trait_exists( 'WP_MCP_AI_Tool_Default_Capability' ) ) {
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-list-email-templates.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-preview-email-template.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-audit-email-template.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-set-active-email-template.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-test-send-email.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-generate-email-template.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-export-email-template.php';
	require_once NVOOS_DESIGN_SYSTEM_PATH . 'includes/tools/class-nvoos-nds-tool-import-email-template.php';
}
