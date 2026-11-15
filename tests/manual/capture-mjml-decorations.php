<?php
/**
 * Regenerates tests/fixtures/email/mjml-decorations.json from WordPress.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-mjml-decorations.php
 *
 * The branding-header and footer markup used to live in the WordPress tool's
 * private methods. They were captured from that implementation *before* it was
 * replaced by calls to `MjmlTemplateBuilder`, so the fixture records WordPress
 * output rather than the port's own opinion.
 *
 * To re-capture the same way, put the pre-refactor class back at
 * `tmp/legacy-generate-email-template.php` (renamed to
 * `WP_MCP_AI_Tool_Generate_Email_Template_Legacy`):
 *
 *   git show <pre-refactor>:addons/pro/includes/tools/document-generation/\
 *     class-wp-mcp-ai-tool-generate-email-template.php > tmp/legacy-generate-email-template.php
 *
 * Without that file the script falls back to the core builder, which is what
 * every subsequent regeneration will do — the two produce identical markup,
 * which is the whole point of the corpus.
 *
 * Regenerating is a deliberate act: it asserts "this markup is correct", not
 * "the test is green again".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesProhibited, WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone runner.

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = __DIR__;

	while ( ! is_file( $wp_root . '/wp-load.php' ) && dirname( $wp_root ) !== $wp_root ) {
		$wp_root = dirname( $wp_root );
	}

	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, 'Could not locate wp-load.php above ' . __DIR__ . "\n" );
		exit( 1 );
	}

	require $wp_root . '/wp-load.php';
}

$legacy_file = WP_MCP_AI_PATH . 'tmp/legacy-generate-email-template.php';

if ( is_file( $legacy_file ) ) {
	require_once $legacy_file;

	$legacy = new WP_MCP_AI_Tool_Generate_Email_Template_Legacy();

	$branding_method = new ReflectionMethod( $legacy, 'add_branding_to_mjml' );
	$footer_method   = new ReflectionMethod( $legacy, 'add_footer_to_mjml' );
	$branding_method->setAccessible( true );
	$footer_method->setAccessible( true );

	$decorate_branding = static function ( string $mjml, array $branding ) use ( $legacy, $branding_method ): string {
		return (string) $branding_method->invoke( $legacy, $mjml, $branding );
	};

	$decorate_footer = static function ( string $mjml, array $links, array $branding ) use ( $legacy, $footer_method ): string {
		return (string) $footer_method->invoke( $legacy, $mjml, $links, $branding );
	};

	echo "Capturing from the pre-refactor WordPress tool.\n";
} else {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllNamespaces.UnprefixedNamespace -- MIT package namespace, not a plugin class.
	$builder = new \Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder();

	$decorate_branding = static fn ( string $mjml, array $branding ): string => $builder->withBrandingHeader( $mjml, $branding );
	$decorate_footer   = static fn ( string $mjml, array $links, array $branding ): string => $builder->withFooter( $mjml, $links, $branding );

	echo "No legacy copy found; capturing from the core builder.\n";
}

/**
 * Fixed base document: decorations only, so the capture isolates them.
 */
$base = '<mjml><mj-head></mj-head><mj-body><mj-section><mj-column><mj-text>Body</mj-text></mj-column></mj-section></mj-body></mjml>';

$cases = array(
	'header-logo-only'        => array(
		'branding' => array( 'logo_url' => 'https://cdn.example.com/logo.png' ),
	),
	'header-logo-and-name'    => array(
		'branding' => array(
			'logo_url'     => 'https://cdn.example.com/logo.png',
			'company_name' => 'Acme & Co',
		),
	),
	'header-logo-with-query'  => array(
		'branding' => array( 'logo_url' => 'https://cdn.example.com/logo.png?w=300&h=80' ),
	),
	'header-logo-spaces'      => array(
		'branding' => array( 'logo_url' => 'https://cdn.example.com/my logo.png' ),
	),
	'header-no-logo'          => array(
		'branding' => array( 'company_name' => 'Acme' ),
	),
	'header-empty-logo'       => array(
		'branding' => array( 'logo_url' => '' ),
	),
	'header-zero-logo'        => array(
		'branding' => array( 'logo_url' => '0' ),
	),
	'header-hostile-logo'     => array(
		'branding' => array( 'logo_url' => 'javascript:alert(1)' ),
	),
	'footer-company-only'     => array(
		'footer_links' => array(),
		'branding'     => array( 'company_name' => 'Acme & Co' ),
	),
	'footer-company-address'  => array(
		'footer_links' => array(),
		'branding'     => array(
			'company_name'    => 'Acme & Co',
			'company_address' => '1 Main St, Springfield',
		),
	),
	'footer-links-only'       => array(
		'footer_links' => array(
			array(
				'text' => 'Unsubscribe',
				'url'  => 'https://acme.test/u?e=1&t=2',
			),
			array(
				'text' => 'Privacy',
				'url'  => 'https://acme.test/privacy',
			),
		),
	),
	'footer-link-missing-url' => array(
		'footer_links' => array(
			array( 'text' => 'No url' ),
			array(
				'text' => 'Ok',
				'url'  => 'https://acme.test/ok',
			),
		),
	),
	'footer-empty-links'      => array(
		'footer_links' => array(),
	),
	'footer-hostile-link'     => array(
		'footer_links' => array(
			array(
				'text' => 'Click',
				'url'  => 'javascript:alert(1)',
			),
		),
	),
	'footer-escaped-text'     => array(
		'footer_links' => array(
			array(
				'text' => 'Privacy & Terms',
				'url'  => '/privacy?x=1&y=2',
			),
		),
	),
	'header-and-footer'       => array(
		'branding'     => array(
			'logo_url'        => 'https://cdn.example.com/logo.png',
			'company_name'    => 'Acme & Co',
			'company_address' => '1 Main St',
		),
		'footer_links' => array(
			array(
				'text' => 'Unsubscribe',
				'url'  => 'https://acme.test/u',
			),
		),
	),
);

$out = array();

foreach ( $cases as $name => $case ) {
	$branding     = isset( $case['branding'] ) ? $case['branding'] : array();
	$footer_links = isset( $case['footer_links'] ) ? $case['footer_links'] : null;

	$expected = $base;

	if ( array() !== $branding ) {
		$expected = $decorate_branding( $expected, $branding );
	}

	if ( null !== $footer_links ) {
		$expected = $decorate_footer( $expected, $footer_links, $branding );
	}

	$out[ $name ] = array(
		'mjml'         => $base,
		'branding'     => $branding,
		'footer_links' => $footer_links,
		'expected'     => $expected,
	);
}

$target = WP_MCP_AI_PATH . 'tests/fixtures/email/mjml-decorations.json';
$json   = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, not HTML output.
