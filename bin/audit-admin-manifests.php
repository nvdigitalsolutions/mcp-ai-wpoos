#!/usr/bin/env php
<?php
/**
 * Audit the Pro toolkit manifests — Proposal 149 Wave 3.
 *
 * The manifest is the only thing standing between an operator and a broken
 * toolkit screen: the panel and the console both render from it, so a document
 * that validates but describes something neither surface can serve becomes a
 * blank page rather than an error. This gate checks three things the PHPUnit
 * suite cannot see once the manifest is committed:
 *
 *  1. every source document decodes and validates through `ToolkitManifest`;
 *  2. the document's `slug` matches its filename, because the loader keys by
 *     slug and skips a mismatched pair rather than guessing;
 *  3. every manifest declares at least one screen, and a screen is either one
 *     this panel routes (`settings`, `entities`, `dashboard`) or a documented
 *     console-only screen (`research`, `consolidate` — Wave 4 on the panel).
 *
 * The **mirror** (`docker/laravel/app-skeleton/resources/pro-toolkits`) is
 * guarded by `bin/sync-toolkit-manifests.php --check` in the same workflow, so
 * this script does not duplicate that comparison.
 *
 * Manifest coverage (how many Pro toolkits still have no manifest) is reported,
 * not failed: authoring the remaining manifests is the Wave 3 content pass and
 * a red gate would just be turned off.
 *
 * Usage:
 *   php bin/audit-admin-manifests.php
 *   php bin/audit-admin-manifests.php --json
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

require_once dirname( __DIR__ ) . '/lib/core/src/Domain/ValueObject/ToolkitManifest.php';

$root   = dirname( __DIR__ );
$source = $root . '/addons/pro/includes/toolkits/manifests';
$json   = in_array( '--json', array_slice( $argv, 1 ), true );

/** Screens the Filament renderer routes (App\Filament\Admin\Pages\ToolkitPage). */
$panelScreens = array( 'settings', 'entities', 'dashboard' );

/** Screens the console serves and the panel deliberately does not route yet. */
$consoleOnlyScreens = array( 'research', 'consolidate' );

$errors = array();
$report = array();

if ( ! is_dir( $source ) ) {
	fwrite( STDERR, "audit-admin-manifests: missing source tree {$source}\n" );
	exit( 1 );
}

$files = glob( $source . '/*.json' );
sort( $files );

if ( array() === $files ) {
	fwrite( STDERR, "audit-admin-manifests: no manifests found in {$source}\n" );
	exit( 1 );
}

$panelServable = 0;

foreach ( $files as $file ) {
	$name     = basename( $file, '.json' );
	$document = json_decode( (string) file_get_contents( $file ), true );
	$declared = array();

	if ( ! is_array( $document ) ) {
		$errors[] = $name . ': not a JSON object';
		$report[] = array(
			'slug'    => $name,
			'screens' => array(),
			'status'  => 'invalid',
		);

		continue;
	}

	foreach ( ToolkitManifest::validate( $document ) as $error ) {
		$errors[] = $name . ': ' . $error;
	}

	if ( (string) ( $document['slug'] ?? '' ) !== $name ) {
		$errors[] = $name . ': slug "' . (string) ( $document['slug'] ?? '' ) . '" does not match the filename';
	}

	$declared = array_values( array_map( 'strval', (array) ( $document['screens'] ?? array() ) ) );

	$unknown = array_diff( $declared, $panelScreens, $consoleOnlyScreens );

	foreach ( $unknown as $screen ) {
		$errors[] = $name . ': screen "' . $screen . '" is served by no surface';
	}

	if ( array() === $declared ) {
		$errors[] = $name . ': declares no screens, so nothing can render it';
	}

	if ( array() !== array_intersect( $declared, $panelScreens ) ) {
		++$panelServable;
	}

	$report[] = array(
		'slug'     => $name,
		'screens'  => $declared,
		'entities' => count( (array) ( $document['entities'] ?? array() ) ),
		'settings' => count( (array) ( $document['settings'] ?? array() ) ),
		'status'   => 'ok',
	);
}

if ( $json ) {
	echo json_encode(
		array(
			'manifests'        => $report,
			'panel_servable'   => $panelServable,
			'errors'           => $errors,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . PHP_EOL;

	exit( array() === $errors ? 0 : 1 );
}

echo 'Toolkit manifest audit — Proposal 149 Wave 3' . PHP_EOL;
echo 'source: addons/pro/includes/toolkits/manifests' . PHP_EOL;
echo PHP_EOL;

foreach ( $report as $row ) {
	printf(
		"  %-12s  %3d setting(s)  %2d entit%s  %s%s",
		$row['slug'],
		(int) ( $row['settings'] ?? 0 ),
		(int) ( $row['entities'] ?? 0 ),
		1 === (int) ( $row['entities'] ?? 0 ) ? 'y ' : 'ies',
		implode( ', ', array_map( 'strval', $row['screens'] ?? array() ) ),
		PHP_EOL
	);
}

echo PHP_EOL;
printf( "manifests             : %d%s", count( $files ), PHP_EOL );
printf( "panel-servable        : %d (declares at least one of %s)%s", $panelServable, implode( '/', $panelScreens ), PHP_EOL );
printf( "console-only screens  : %s (Wave 4 on the panel)%s", implode( '/', $consoleOnlyScreens ), PHP_EOL );
echo 'mirror check          : php bin/sync-toolkit-manifests.php --check' . PHP_EOL;
echo PHP_EOL;

if ( array() === $errors ) {
	echo 'RESULT: every manifest validates and declares only servable screens.' . PHP_EOL;
	exit( 0 );
}

echo 'FAILURES:' . PHP_EOL;

foreach ( $errors as $error ) {
	echo '  - ' . $error . PHP_EOL;
}

exit( 1 );
