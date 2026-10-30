<?php
/**
 * PHPUnit bootstrap for the Funiq Bridge addon (self-guarding pattern —
 * mirrors addons/checkout-api/tests/bootstrap.php; see the test-suite skill,
 * pattern #7134).
 *
 * Loaded from the root tests/bootstrap.php. The WordPress test environment
 * itself is bootstrapped by the root config; this file's job is to register
 * the addon's PHP files with the running WordPress instance.
 *
 * @package NvoosAstroBridge\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow the file to be loaded standalone without a booted WordPress.
	return;
}

// The plugin main file defines its own constants and autoloader; require it
// once (plugins_loaded has already fired under PHPUnit, so init() below
// takes over the boot that production defers to plugins_loaded).
if ( ! class_exists( 'NvoosAstroBridge\\Plugin' ) ) {
	require_once dirname( __DIR__ ) . '/nvoos-astro-bridge.php';
}

// Boot the addon directly so its init/rest_api_init hooks exist when tests
// fire the actions. Guarded: has_action() with the singleton instance keeps
// a second require (or a re-run bootstrap) idempotent.
$bridge = NvoosAstroBridge\Plugin::instance();
if ( ! has_action( 'init', array( $bridge, 'register_post_types' ) ) ) {
	NvoosAstroBridge\Plugin::init();
}

// Activation never runs under PHPUnit — grant the write capability the same
// way Plugin::activate() does in production.
$admin = get_role( 'administrator' );
if ( $admin && ! $admin->has_cap( \NvoosAstroBridge\Schema::CAP_MANAGE ) ) {
	$admin->add_cap( \NvoosAstroBridge\Schema::CAP_MANAGE );
}
$editor = get_role( 'editor' );
if ( $editor && ! $editor->has_cap( \NvoosAstroBridge\Schema::CAP_MANAGE ) ) {
	$editor->add_cap( \NvoosAstroBridge\Schema::CAP_MANAGE );
}
