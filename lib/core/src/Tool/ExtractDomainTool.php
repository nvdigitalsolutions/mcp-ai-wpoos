<?php
/**
 * Extract Domain tool — parses URLs and extracts components.
 *
 * Pure logic — zero external dependencies. Uses PHP's parse_url().
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

class ExtractDomainTool extends AbstractTool {

	public function getSlug(): string {
		return 'extract_domain';
	}

	public function getName(): string {
		return 'Extract Domain';
	}

	public function getDescription(): string {
		return 'Parses a URL and extracts its components: scheme, hostname, domain, TLD, subdomain, path, and query parameters.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'     => array(
					'type'        => 'string',
					'description' => 'The URL to parse.',
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$url = $this->stringParam( $arguments, 'url' );
		if ( '' === $url ) {
			return $this->errors->validationFailed(
				'url is required.',
				array( 'url' => array( 'A URL is required.' ) ),
			);
		}

		$parsed = parse_url( $url );

		if ( false === $parsed ) {
			return $this->success(
				'Invalid URL.',
				array(
					'valid' => false,
					'input' => $url,
				),
			);
		}

		$hostname = $parsed['host'] ?? '';
		$parts    = $this->splitHostname( $hostname );

		return $this->success(
			sprintf( 'URL parsed: %s.', $hostname ?: '(no hostname)' ),
			array(
				'valid'     => true,
				'input'     => $url,
				'scheme'    => $parsed['scheme'] ?? '',
				'hostname'  => $hostname,
				'domain'    => $parts['domain'],
				'tld'       => $parts['tld'],
				'subdomain' => $parts['subdomain'],
				'port'      => isset( $parsed['port'] ) ? (string) $parsed['port'] : '',
				'path'      => $parsed['path'] ?? '',
				'query'     => $parsed['query'] ?? '',
				'fragment'  => $parsed['fragment'] ?? '',
			),
		);
	}

	/**
	 * Split a hostname into subdomain, domain, and TLD components.
	 *
	 * @return array{domain: string, tld: string, subdomain: string}
	 */
	private function splitHostname( string $hostname ): array {
		if ( '' === $hostname ) {
			return array( 'domain' => '', 'tld' => '', 'subdomain' => '' );
		}

		$parts = explode( '.', $hostname );

		if ( count( $parts ) <= 1 ) {
			return array( 'domain' => $hostname, 'tld' => '', 'subdomain' => '' );
		}

		// Handle known two-part TLDs (co.uk, com.au, etc.).
		$twoPartTlds = array( 'co.uk', 'com.au', 'co.nz', 'co.jp', 'or.jp', 'ne.jp',
			'co.in', 'net.in', 'org.in', 'co.za', 'web.za', 'ac.uk', 'gov.uk' );

		$tld       = array_pop( $parts );
		$lastTwo   = count( $parts ) >= 1
			? $parts[ count( $parts ) - 1 ] . '.' . $tld
			: '';

		if ( '' !== $lastTwo && in_array( $lastTwo, $twoPartTlds, true ) ) {
			$tld   = $lastTwo;
			array_pop( $parts ); // Remove the second-level TLD part.
		}

		$domain = array() === $parts ? '' : array_pop( $parts );

		return array(
			'domain'    => $domain,
			'tld'       => $tld,
			'subdomain' => array() === $parts ? '' : implode( '.', $parts ),
		);
	}
}
