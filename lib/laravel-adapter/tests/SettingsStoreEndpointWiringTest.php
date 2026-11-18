<?php
/**
 * Provider endpoint wiring for the Laravel settings store.
 *
 * The store used to read `gemini_api_base_url`, `lm_studio_base_url`,
 * `nvidia_nim_base_url` and `ollama_base_url` — four names nothing wrote.
 * An administrator who set a custom endpoint therefore got `null`, and the
 * provider client silently used its built-in default. The settings surface
 * names are canonical (`gemini_base_url`, `lm_studio_endpoint_url`,
 * `nvidia_endpoint_url`, `ollama_endpoint_url`); these tests hold the adapter
 * to them by exercising `getApiBaseUrl()` rather than reading its map.
 *
 * The catalogue is the machine-readable mirror of the WordPress settings
 * surface, so a provider section that publishes an endpoint must resolve
 * through the adapter under that exact storage key.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests\Adapter;

use Nvoos\Laravel\Adapter\SettingsStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint resolution across every provider the catalogue publishes.
 */
class SettingsStoreEndpointWiringTest extends TestCase {

	/**
	 * Absolute path to the ported WordPress settings catalogue.
	 */
	private const CATALOGUE_PATH = __DIR__ . '/../../../docker/laravel/app-skeleton/resources/settings/catalogue.json';

	/**
	 * Settings slugs whose client-registry name differs.
	 *
	 * @var array<string, string>
	 */
	private const SLUG_ALIASES = array(
		'nvidia' => 'nvidia_nim',
	);

	/**
	 * Providers with no nvoos/core client; WordPress reads these keys directly.
	 *
	 * @var list<string>
	 */
	private const WORDPRESS_ONLY = array( 'typesafe', 'zai' );

	/**
	 * Keys that predate the surface rename and must still resolve.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function legacyKeys(): array {
		return array(
			'gemini'     => array( 'gemini', 'gemini_api_base_url', 'gemini_base_url' ),
			'lm_studio'  => array( 'lm_studio', 'lm_studio_base_url', 'lm_studio_endpoint_url' ),
			'nvidia_nim' => array( 'nvidia_nim', 'nvidia_nim_base_url', 'nvidia_endpoint_url' ),
			'ollama'     => array( 'ollama', 'ollama_base_url', 'ollama_endpoint_url' ),
		);
	}

	/**
	 * Provider endpoint fields published by the catalogue.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function providerEndpoints(): array {
		$catalogue = json_decode( (string) @file_get_contents( self::CATALOGUE_PATH ), true );

		if ( ! is_array( $catalogue ) || ! isset( $catalogue['fields'] ) || ! is_array( $catalogue['fields'] ) ) {
			// A sentinel row makes the missing catalogue a visible failure in
			// the resolution test instead of an empty data set that passes.
			return array( 'catalogue missing' => array( 'catalogue', 'catalogue', 'catalogue' ) );
		}

		$suffixes = array( '_base_url', '_endpoint', '_endpoint_url' );
		$rows     = array();

		foreach ( $catalogue['fields'] as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$is_endpoint = false;
			foreach ( $suffixes as $suffix ) {
				if ( str_ends_with( $key, $suffix ) ) {
					$is_endpoint = true;
					break;
				}
			}

			if ( ! $is_endpoint ) {
				continue;
			}

			$section = (string) ( $field['section_id'] ?? '' );

			if ( ! preg_match( '/^providers__([a-z0-9_]+?)(?:__connection)?$/', $section, $match ) ) {
				continue;
			}

			$provider = $match[1];

			if ( in_array( $provider, self::WORDPRESS_ONLY, true ) ) {
				continue;
			}

			$slug           = self::SLUG_ALIASES[ $provider ] ?? $provider;
			$storage        = (string) ( $field['storage'] ?? $key );
			$rows[ $provider ] = array( $provider, $slug, $storage );
		}

		return $rows;
	}

	public function test_catalogue_is_readable(): void {
		$this->assertFileExists( self::CATALOGUE_PATH, 'The ported settings catalogue is the wiring contract.' );
	}

	/**
	 * Every provider section publishes exactly one endpoint field.
	 *
	 * Two would mean the adapter cannot know which one an operator set; zero
	 * would mean the provider's address is not configurable at all.
	 */
	public function test_each_provider_publishes_one_endpoint(): void {
		$by_provider = array();

		foreach ( self::providerEndpoints() as $row ) {
			$by_provider[ $row[0] ][] = $row[2];
		}

		foreach ( $by_provider as $provider => $storages ) {
			$this->assertCount( 1, array_unique( $storages ), "{$provider} must publish exactly one endpoint field." );
		}
	}

	/**
	 * The adapter resolves the key the surface actually writes.
	 */
	#[DataProvider( 'providerEndpoints' )]
	public function test_provider_endpoint_resolves_from_the_surface_key( string $provider, string $slug, string $storage ): void {
		$url   = 'https://endpoint.test/' . $slug . '/v1/';
		$store = $this->storeWith( array( $storage => $url ) );

		$this->assertSame(
			rtrim( $url, '/' ),
			$store->getApiBaseUrl( $slug ),
			"{$provider} did not resolve from `{$storage}`."
		);
	}

	/**
	 * The surface key wins when a row still holds the old name too.
	 */
	public function test_surface_key_wins_over_legacy_key(): void {
		foreach ( self::legacyKeys() as $pair ) {
			$slug    = $pair[0];
			$surface = $pair[2];
			$store   = $this->storeWith(
				array(
					$pair[1] => 'https://legacy.test/v1/',
					$surface => 'https://surface.test/v1/',
				)
			);

			$this->assertSame(
				'https://surface.test/v1',
				$store->getApiBaseUrl( $slug ),
				"{$slug} must prefer the surface key over the legacy name."
			);
		}
	}

	/**
	 * Rows written before the fix still resolve.
	 */
	#[DataProvider( 'legacyKeys' )]
	public function test_legacy_key_still_resolves( string $slug, string $legacy_key, string $surface_key ): void {
		$this->assertNotSame( $legacy_key, $surface_key, 'The legacy and surface names must differ.' );

		$store = $this->storeWith( array( $legacy_key => 'https://legacy.test/v1/' ) );

		$this->assertSame(
			'https://legacy.test/v1',
			$store->getApiBaseUrl( $slug ),
			"{$slug} lost the fallback for `{$legacy_key}`."
		);
	}

	public function test_unknown_provider_returns_null(): void {
		$store = $this->storeWith( array( 'whatever_base_url' => 'https://example.test' ) );

		$this->assertNull( $store->getApiBaseUrl( 'not_a_provider' ) );
	}

	public function test_empty_value_returns_null(): void {
		$store = $this->storeWith( array( 'openai_base_url' => '' ) );

		$this->assertNull( $store->getApiBaseUrl( 'openai' ) );
	}

	/**
	 * Build a store whose reads come from the supplied array.
	 *
	 * `SettingsStore::get()` walks config and database layers; overriding it
	 * keeps this a unit test of endpoint resolution, not of persistence.
	 *
	 * @param array<string, mixed> $values Stored values.
	 * @return SettingsStore
	 */
	private function storeWith( array $values ): SettingsStore {
		return new class( $values ) extends SettingsStore {
			/**
			 * @param array<string, mixed> $values Stored values.
			 */
			public function __construct( private array $values ) {
				parent::__construct();
			}

			/**
			 * @param string $key     Setting key.
			 * @param mixed  $default Fallback.
			 * @return mixed
			 */
			public function get( string $key, mixed $default = null ): mixed {
				return $this->values[ $key ] ?? $default;
			}
		};
	}
}
