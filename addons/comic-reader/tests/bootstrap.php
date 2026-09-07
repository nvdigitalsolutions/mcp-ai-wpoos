<?php
/**
 * PHPUnit bootstrap for the NV oOS Comic Reader addon.
 *
 * The WordPress test environment itself is bootstrapped by the root test
 * suite; this file's job is to define the addon's constants and require its
 * PHP classes so addon tests can run inside the shared suite.
 *
 * Only constants and class definitions are loaded — no file-scope hooks —
 * so it is safe to require from the root bootstrap.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow the file to be loaded from a phpunit.xml that bootstraps the
	// WordPress test environment first.
	return;
}

if ( ! defined( 'NVOOS_COMIC_READER_PATH' ) ) {
	define( 'NVOOS_COMIC_READER_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'NVOOS_COMIC_READER_URL' ) ) {
	define( 'NVOOS_COMIC_READER_URL', 'http://example.test/wp-content/plugins/nvoos-comic-reader/' );
}

if ( ! defined( 'NVOOS_COMIC_READER_VERSION' ) ) {
	define( 'NVOOS_COMIC_READER_VERSION', '0.2.0' );
}

require_once NVOOS_COMIC_READER_PATH . 'includes/class-nvoos-comic-reader-plugin.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/class-nvoos-comic-reader-mime.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/rest/class-nvoos-comic-reader-rest.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/shortcode/class-nvoos-comic-reader-shortcode.php';
require_once NVOOS_COMIC_READER_PATH . 'includes/block/class-nvoos-comic-reader-block.php';

// Register REST routes on rest_api_init so they exist for every test. The
// hook survives wp-phpunit's per-test filter snapshot because it is added
// here, at bootstrap time, before any test runs.
add_action( 'rest_api_init', array( 'NV_oOS_Comic_Reader_REST', 'register_routes' ) );
