<?php
/**
 * Verify that every provider endpoint the settings surface publishes is read
 * by the adapters under the same key.
 *
 * Proposal 149 §65. Three adapters mapped several providers to a second key
 * that nothing wrote (`gemini_api_base_url`, `lm_studio_base_url`,
 * `nvidia_nim_base_url`, `ollama_base_url`), so an administrator who set a
 * custom endpoint in Settings got silence: the store returned `null` and the
 * provider client quietly used its built-in default. The settings surface was
 * right and the adapters were wrong, so the surface names
 * (`gemini_base_url`, `lm_studio_endpoint_url`, `nvidia_endpoint_url`,
 * `ollama_endpoint_url`) are canonical and the adapters read them.
 *
 * This gate makes that rule machine-checkable, in three directions:
 *
 *  1. **Surface → adapter** — every endpoint-shaped field in the ported
 *     catalogue whose section is a provider must be the exact key the
 *     adapters read for that provider slug.
 *  2. **Adapter → surface** — every key an adapter reads must be either a
 *     catalogue field or an explicitly listed key that the Laravel core
 *     schema owns or that has no surface field at all.
 *  3. **Allowlisted consumers** — endpoint-shaped fields outside the provider
 *     tabs are not fetched by a provider client, so each is listed together
 *     with the file that consumes it; the gate fails if that file or its
 *     mention disappears.
 *
 * The catalogue is generated from the plugin's WordPress section classes, so
 * it is the machine-readable mirror of the surface this gate holds the
 * adapters to. A rename on the WordPress side that is not carried into the
 * catalogue is a different gate's problem.
 *
 * Usage:
 *   php bin/check-endpoint-wiring.php
 *
 * @package WP_MCP_AI
 * @since   2.7.0
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$checks   = 0;
$failures = 0;

/**
 * Record one assertion and print its result.
 *
 * @param bool   $ok     Whether the assertion holds.
 * @param string $label  Human-readable description of the assertion.
 * @param string $detail Extra context printed only on failure.
 * @return void
 */
function check_endpoint( bool $ok, string $label, string $detail = '' ): void {
	global $checks, $failures;

	++$checks;

	if ( $ok ) {
		echo "  \u{2713} {$label}\n";
		return;
	}

	++$failures;
	echo "  \u{2717} {$label}\n";

	if ( '' !== $detail ) {
		echo "      {$detail}\n";
	}
}

/**
 * Read the provider => storage-key map out of an adapter's getApiBaseUrl().
 *
 * The method body is isolated first so the legacy fallback map cannot be
 * mistaken for the primary one. Parsing source is deliberate: the maps are
 * private to the adapters, and a gate that needed production code changed in
 * order to be testable would be a worse gate.
 *
 * @param string $path Absolute path to an adapter SettingsStore.php.
 * @return array<string, string>|null Map, or null when the shape is not found.
 */
function read_adapter_url_map( string $path ): ?array {
	$source = file_get_contents( $path );

	if ( false === $source ) {
		return null;
	}

	$method = strpos( $source, 'function getApiBaseUrl' );
	if ( false === $method ) {
		return null;
	}

	$map_start = strpos( $source, '$urlMap', $method );
	if ( false === $map_start ) {
		return null;
	}

	$map_end = strpos( $source, '$legacyMap', $map_start );
	if ( false === $map_end ) {
		$map_end = strpos( $source, '$optionKey', $map_start );
	}
	if ( false === $map_end ) {
		return null;
	}

	$body = substr( $source, $map_start, $map_end - $map_start );

	if ( ! preg_match_all( "/'([a-z0-9_]+)'\\s*=>\\s*'([a-z0-9_]+)'/", $body, $matches, PREG_SET_ORDER ) ) {
		return null;
	}

	$map = array();
	foreach ( $matches as $match ) {
		$map[ $match[1] ] = $match[2];
	}

	return $map;
}

$catalogue_path = $root . '/docker/laravel/app-skeleton/resources/settings/catalogue.json';

echo "Catalogue\n";

if ( ! is_readable( $catalogue_path ) ) {
	check_endpoint( false, 'Catalogue is readable', $catalogue_path );
	printf( "\n\u{2717} 1 check, 1 failure\n" );
	exit( 1 );
}

$catalogue = json_decode( (string) file_get_contents( $catalogue_path ), true );

check_endpoint(
	is_array( $catalogue ) && isset( $catalogue['fields'] ) && is_array( $catalogue['fields'] ),
	'Catalogue decodes to a fields list'
);

if ( ! is_array( $catalogue ) || empty( $catalogue['fields'] ) ) {
	printf( "\n\u{2717} %d check(s), %d failure(s)\n", $checks, $failures + ( 0 === $failures ? 1 : 0 ) );
	exit( 1 );
}

$suffixes = array( '_base_url', '_endpoint', '_endpoint_url' );

$is_endpoint_key = static function ( string $key ) use ( $suffixes ): bool {
	foreach ( $suffixes as $suffix ) {
		if ( str_ends_with( $key, $suffix ) ) {
			return true;
		}
	}

	return false;
};

$surface_keys   = array();
$provider_field = array();

foreach ( $catalogue['fields'] as $field ) {
	$key = (string) ( $field['key'] ?? '' );

	if ( '' === $key || ! $is_endpoint_key( $key ) ) {
		continue;
	}

	$storage = (string) ( $field['storage'] ?? $key );
	$section = (string) ( $field['section_id'] ?? '' );

	$surface_keys[ $storage ] = $key;

	if ( preg_match( '/^providers__([a-z0-9_]+?)(?:__connection)?$/', $section, $match ) ) {
		$provider_field[ $match[1] ][] = $storage;
	}
}

echo "\nSurface → adapter\n";

$adapter_files = array(
	'laravel'   => $root . '/lib/laravel-adapter/src/Adapter/SettingsStore.php',
	'wordpress' => $root . '/lib/wordpress-adapter/src/Adapter/SettingsStore.php',
	'craft'     => $root . '/lib/craft-adapter/src/Adapter/SettingsStore.php',
);

$adapter_maps = array();

foreach ( $adapter_files as $label => $path ) {
	check_endpoint( is_readable( $path ), "{$label} adapter exists", $path );

	$map = is_readable( $path ) ? read_adapter_url_map( $path ) : null;

	check_endpoint(
		is_array( $map ) && array() !== $map,
		"{$label} adapter exposes a readable provider map",
		'Expected a $urlMap array inside getApiBaseUrl().'
	);

	$adapter_maps[ $label ] = is_array( $map ) ? $map : array();
}

// `nvidia` is the settings slug; `nvidia_nim` is the client-registry slug.
$provider_aliases = array( 'nvidia' => 'nvidia_nim' );

// Providers with no nvoos/core client: their WordPress client reads the
// surface key directly, so an adapter entry would never be consumed.
$wordpress_only = array(
	'typesafe' => array(
		'file' => '/includes/class-wp-mcp-ai-typesafe-client.php',
		'keys' => array( 'typesafe_base_url', 'typesafe_endpoint' ),
	),
	'zai'      => array(
		'file' => '/includes/class-wp-mcp-ai-zai-client.php',
		'keys' => array( 'zai_base_url' ),
	),
);

ksort( $provider_field );

foreach ( $provider_field as $provider => $storages ) {
	$storages = array_values( array_unique( $storages ) );
	$slug     = $provider_aliases[ $provider ] ?? $provider;

	if ( isset( $wordpress_only[ $provider ] ) ) {
		$consumer  = $wordpress_only[ $provider ];
		$path      = $root . $consumer['file'];
		$has_file  = is_readable( $path );
		$contents  = $has_file ? (string) file_get_contents( $path ) : '';
		$missing   = array();
		$orphaned  = array_diff( $consumer['keys'], $storages );
		$declared  = array_intersect( $consumer['keys'], $storages );

		foreach ( $declared as $key ) {
			if ( ! str_contains( $contents, $key ) ) {
				$missing[] = $key;
			}
		}

		check_endpoint(
			$has_file && array() === $missing && array() === $orphaned,
			"{$provider} is WordPress-only and its client reads the surface key",
			sprintf(
				'%s missing=%s orphaned=%s',
				$consumer['file'],
				$missing ? implode( ', ', $missing ) : 'none',
				$orphaned ? implode( ', ', $orphaned ) : 'none'
			)
		);

		continue;
	}

	if ( 1 !== count( $storages ) ) {
		check_endpoint(
			false,
			"{$provider} publishes exactly one endpoint field",
			'Found: ' . implode( ', ', $storages )
		);

		continue;
	}

	$surface_key = $storages[0];

	foreach ( $adapter_maps as $label => $map ) {
		$read = $map[ $slug ] ?? null;

		check_endpoint(
			$read === $surface_key,
			"{$label} reads {$provider} from `{$surface_key}`",
			null === $read
				? "No `{$slug}` entry in the adapter map."
				: "Adapter reads `{$read}`; the surface writes `{$surface_key}`."
		);
	}
}

echo "\nAdapter → surface\n";

// Keys an adapter may read that the generated catalogue does not carry.
$known_without_surface = array(
	'ollama_endpoint_url' => 'owned by Laravel SettingsSchema::coreFields() (operator scope)',
	'cloudflare_base_url' => 'no surface field; the client derives its URL from cloudflare_account_id',
);

foreach ( $adapter_maps as $label => $map ) {
	foreach ( $map as $slug => $storage ) {
		if ( isset( $surface_keys[ $storage ] ) ) {
			continue;
		}

		check_endpoint(
			isset( $known_without_surface[ $storage ] ),
			"{$label} key for {$slug} has an owner",
			"`{$storage}` is neither a catalogue field nor a documented exception."
		);
	}
}

echo "\nNon-provider endpoint consumers\n";

$non_provider_consumers = array(
	'filter_default_ollama_endpoint_url'    => '/includes/admin/class-wp-mcp-ai-custom-filters-applicator.php',
	'filter_default_lm_studio_endpoint_url' => '/includes/admin/class-wp-mcp-ai-custom-filters-applicator.php',
	'wordpress_gravatar_userinfo_endpoint'  => '/includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php',
	'otel_endpoint'                         => '/includes/services/class-wp-mcp-ai-otel-span-exporter.php',
);

$adapter_read_keys = array();
foreach ( $adapter_maps as $map ) {
	$adapter_read_keys += array_flip( array_values( $map ) );
}

$provider_keys = array();
foreach ( $provider_field as $storages ) {
	foreach ( $storages as $storage ) {
		$provider_keys[ $storage ] = true;
	}
}

foreach ( $surface_keys as $storage => $key ) {
	if ( isset( $provider_keys[ $storage ] ) || isset( $adapter_read_keys[ $storage ] ) ) {
		continue;
	}

	$consumer = $non_provider_consumers[ $storage ] ?? null;
	$path     = null === $consumer ? '' : $root . $consumer;

	check_endpoint(
		null !== $consumer && is_readable( $path ) && str_contains( (string) file_get_contents( $path ), $storage ),
		"`{$storage}` has a named consumer",
		null === $consumer
			? 'Add the consumer file to $non_provider_consumers in this script.'
			: "{$consumer} does not mention `{$storage}`."
	);
}

printf( "\n%s %d check(s), %d failure(s)\n", 0 === $failures ? "\u{2713}" : "\u{2717}", $checks, $failures );

exit( 0 === $failures ? 0 : 1 );
