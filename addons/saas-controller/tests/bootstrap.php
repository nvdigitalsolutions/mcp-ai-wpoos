<?php
/**
 * PHPUnit bootstrap for the NV oOS SaaS Controller addon.
 *
 * Loaded via the addon's `phpunit.xml.dist` (and by the repo-wide harness
 * when running `composer run test`). The base-plugin test bootstrap
 * (`tests/bootstrap.php` at the repository root) is responsible for spinning
 * up the WordPress test environment; this file's only job is to register
 * the addon's PHP files with the running WordPress instance so tests can
 * exercise them.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow the file to be loaded from a phpunit.xml that bootstraps WP test env first.
	return;
}

if ( ! defined( 'NVOOS_SAAS_CONTROLLER_PATH' ) ) {
	define( 'NVOOS_SAAS_CONTROLLER_PATH', dirname( __DIR__ ) . '/' );
}

require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-credential-store.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-deployment-config.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-audit-log.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-webhook-event-store.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-connection-tester.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-cloudflare-client.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-stripe-client.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-stripe-webhook-verifier.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-openrouter-client.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-plan-generator.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-cloudflare-mutating-client.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-apply-engine.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-apply-job.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-smoke-tester.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-drift-detector.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/admin/class-nvoos-saas-controller-admin-page.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/admin/class-nvoos-saas-controller-assets.php';
require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/rest/class-nvoos-saas-controller-rest.php';
