<?php
/**
 * Regenerates tests/fixtures/email/mjml-templates.json from WordPress.
 *
 * Run inside the WordPress container — the fixtures record the *WordPress*
 * service's output, so they must be captured where that service runs:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-mjml-templates.php
 *
 * Regenerating is a deliberate act: it asserts "this new markup is correct",
 * not "the test is green again". Both the core builder test and the WordPress
 * service test replay the file.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone runner.

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = __DIR__;

	while ( ! is_file( $wp_root . '/wp-load.php' ) && dirname( $wp_root ) !== $wp_root ) {
		$wp_root = dirname( $wp_root );
	}

	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, "Could not locate wp-load.php above " . __DIR__ . "\n" );
		exit( 1 );
	}

	require $wp_root . '/wp-load.php';
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/services/class-wp-mcp-ai-mjml-service.php';

$service = new WP_MCP_AI_MJML_Service();

$cases = array(
	'text-only'          => array(
		'components' => array(
			array(
				'type'    => 'text',
				'content' => 'Hello world',
			),
		),
		'options'    => array(),
	),
	'with-head-metadata' => array(
		'components' => array(
			array(
				'type'    => 'text',
				'content' => 'Body copy',
			),
		),
		'options'    => array(
			'title'        => 'Release Notes',
			'preview_text' => 'What shipped this week',
		),
	),
	'every-component'    => array(
		'components' => array(
			array(
				'type'    => 'text',
				'content' => '<strong>Bold</strong> &amp; bright',
			),
			array(
				'type'       => 'button',
				'text'       => 'Read more',
				'attributes' => array(
					'href'  => 'https://example.com/a?b=1&c=2',
					'align' => 'center',
				),
			),
			array(
				'type'       => 'image',
				'attributes' => array(
					'src' => 'https://example.com/x.png',
					'alt' => 'Logo',
				),
			),
			array(
				'type'       => 'divider',
				'attributes' => array( 'border-color' => '#dddddd' ),
			),
			array(
				'type'       => 'spacer',
				'attributes' => array( 'height' => '24px' ),
			),
			array( 'type' => 'unknown-type' ),
		),
		'options'    => array(),
	),
	'custom-theme'       => array(
		'components' => array(
			array(
				'type'    => 'text',
				'content' => 'Themed',
			),
		),
		'options'    => array(
			'font_family'      => 'Georgia, serif',
			'background_color' => '#101010',
			'container_width'  => '640px',
		),
	),
	'script-is-stripped' => array(
		'components' => array(
			array(
				'type'    => 'text',
				'content' => 'Safe <script>alert(1)</script><em>em</em>',
			),
		),
		'options'    => array(),
	),
	'empty-components'   => array(
		'components' => array(),
		'options'    => array(),
	),
);

$out = array();

foreach ( $cases as $name => $case ) {
	$result = $service->generate_template( $case['components'], $case['options'] );

	$out[ $name ] = array(
		'components'           => $case['components'],
		// What the WordPress boundary passes to the core builder: rich text is
		// kses-sanitised first, because the builder injects content verbatim.
		'components_sanitised' => array_map(
			static function ( $component ) {
				if ( is_array( $component ) && isset( $component['content'] ) ) {
					$component['content'] = wp_kses_post( (string) $component['content'] );
				}

				return $component;
			},
			$case['components']
		),
		'options'              => $case['options'],
		'expected'             => is_wp_error( $result )
			? array( 'error' => $result->get_error_code() )
			: $result,
	);
}

$target = WP_MCP_AI_PATH . 'tests/fixtures/email/mjml-templates.json';
$json   = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

echo sprintf( "Wrote %d cases to %s\n", count( $out ), $target );
