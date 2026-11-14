<?php
/**
 * Replays the shared OAuth discovery corpus against the core ordering.
 *
 * The fixtures are the same file the WordPress-side test uses, so the ordering
 * and its adapter are held to one contract. No WordPress bootstrap: this is
 * plain PHP reading JSON.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Mcp;

use Nvoos\Core\Domain\Mcp\OAuthDiscoveryCandidates;
use Nvoos\Core\Domain\Mcp\WwwAuthenticateChallenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthDiscoveryCorpusTest extends TestCase {

	/**
	 * Repository-root corpus file.
	 *
	 * Absent in the split `nvoos/core` repository, which
	 * testTheCorpusIsPresent() reports explicitly rather than passing quietly.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 6 ) . '/tests/fixtures/mcp/oauth-discovery-cases.json';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function corpus(): array {
		$file = self::corpusFile();

		if ( ! is_file( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture, not a URL.
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string $key Corpus section.
	 * @return array<int, array<string, mixed>>
	 */
	private static function section( string $key ): array {
		$corpus = self::corpus();

		return isset( $corpus[ $key ] ) && is_array( $corpus[ $key ] ) ? $corpus[ $key ] : array();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string[], 4: string[], 5: string}>
	 */
	public static function urlCases(): array {
		$cases = array();

		foreach ( self::section( 'url_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['server_url'],
				(string) $case['origin'],
				(string) $case['path'],
				array_map( 'strval', (array) $case['authorization_server_metadata_urls'] ),
				array_map( 'strval', (array) $case['protected_resource_urls'] ),
				(string) $case['rest_metadata_url'],
			);
		}

		return $cases;
	}

	/**
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function authorizationServerCases(): array {
		$cases = array();

		foreach ( self::section( 'authorization_server_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['authorization_server'],
				array_map( 'strval', (array) $case['urls'] ),
			);
		}

		return $cases;
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function headerCases(): array {
		$cases = array();

		foreach ( self::section( 'header_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['header'],
				(array) $case['params'],
			);
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function testTheCorpusIsPresent(): void {
		$this->assertNotSame( array(), self::urlCases(), 'The shared OAuth discovery corpus is missing or unreadable.' );
		$this->assertNotSame( array(), self::headerCases(), 'The shared challenge corpus is missing or unreadable.' );
	}

	/**
	 * @param string   $serverUrl  Recorded server URL.
	 * @param string   $origin     Expected origin.
	 * @param string   $path       Expected normalised path.
	 * @param string[] $asUrls     Expected RFC 8414 candidates.
	 * @param string[] $prmUrls    Expected RFC 9728 candidates.
	 * @param string   $restUrl    Expected REST metadata URL.
	 */
	#[DataProvider( 'urlCases' )]
	public function testItBuildsTheRecordedUrls(
		string $serverUrl,
		string $origin,
		string $path,
		array $asUrls,
		array $prmUrls,
		string $restUrl
	): void {
		$this->assertSame( $origin, OAuthDiscoveryCandidates::origin( $serverUrl ) );
		$this->assertSame( $path, OAuthDiscoveryCandidates::path( $serverUrl ) );
		$this->assertSame( $asUrls, OAuthDiscoveryCandidates::authorizationServerMetadataUrls( $serverUrl ) );
		$this->assertSame( $prmUrls, OAuthDiscoveryCandidates::protectedResourceUrls( $serverUrl ) );
		$this->assertSame( $restUrl, OAuthDiscoveryCandidates::restMetadataUrl( $serverUrl ) );
	}

	/**
	 * @param string   $authorizationServer Recorded authorization server.
	 * @param string[] $urls                Expected candidates.
	 */
	#[DataProvider( 'authorizationServerCases' )]
	public function testItBuildsTheRecordedAuthorizationServerUrls( string $authorizationServer, array $urls ): void {
		$this->assertSame( $urls, OAuthDiscoveryCandidates::authorizationServerUrlsFor( $authorizationServer ) );
	}

	/**
	 * @param string                $header Recorded header value.
	 * @param array<string, string> $params Expected parameters.
	 */
	#[DataProvider( 'headerCases' )]
	public function testItParsesTheRecordedChallenge( string $header, array $params ): void {
		$this->assertSame( $params, WwwAuthenticateChallenge::parse( $header ) );
	}

	public function testAChallengeWithoutAQuotedValueYieldsNothing(): void {
		// Only quoted auth-params are collected: a token is not a fetchable URL.
		$this->assertSame( array(), WwwAuthenticateChallenge::parse( 'Bearer mcp' ) );
	}

	public function testAnAuthorizationServerDocumentNeedsBothEndpoints(): void {
		$this->assertTrue(
			OAuthDiscoveryCandidates::isAuthorizationServerMetadata(
				array(
					'authorization_endpoint' => 'https://a',
					'token_endpoint'         => 'https://t',
				)
			)
		);

		$this->assertFalse(
			OAuthDiscoveryCandidates::isAuthorizationServerMetadata( array( 'authorization_endpoint' => 'https://a' ) )
		);
	}

	public function testAProtectedResourceDocumentNeedsAnAuthorizationServer(): void {
		$this->assertTrue(
			OAuthDiscoveryCandidates::isProtectedResourceMetadata( array( 'authorization_servers' => array( 'https://a' ) ) )
		);

		$this->assertFalse( OAuthDiscoveryCandidates::isProtectedResourceMetadata( array() ) );
	}
}
