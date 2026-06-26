<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote\Drivers;

use NvoosGraphify\Contracts\RemoteSource;
use NvoosGraphify\Remote\HttpClient;
use function absint;
use function esc_url_raw;
use function is_wp_error;
use function md5;
use function sanitize_key;
use function sanitize_text_field;
use function simplexml_load_string;
use function sprintf;
use function strtolower;

/**
 * RSS / Atom / Sitemap remote source driver.
 *
 * Ingests feed items or sitemap URLs as knowledge-graph nodes.
 * Supports RSS 2.0, Atom 1.0, and XML Sitemaps.
 *
 * @since 1.0.0
 */
class RssSitemap implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	/** @var HttpClient HTTP client instance. */
	private HttpClient $http;

	public function __construct() {
		$this->http = new HttpClient( 'rss_sitemap' );
	}

	public function getDriverId(): string {
		return 'rss_sitemap';
	}

	public function getDriverLabel(): string {
		return __( 'RSS / Atom / Sitemap Feed', 'nvoos-graphify' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
		$slug         = $config['_slug'] ?? 'rss_sitemap';
		$this->http   = new HttpClient( $slug );
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'fetch_nodes' );
	}

	public function getConfigSchema(): array {
		return array(
			'feed_url'  => array(
				'type'        => 'url',
				'label'       => __( 'Feed / Sitemap URL', 'nvoos-graphify' ),
				'description' => __( 'RSS 2.0, Atom 1.0, or XML sitemap URL.', 'nvoos-graphify' ),
				'required'    => true,
			),
			'node_type' => array(
				'type'        => 'text',
				'label'       => __( 'Node Type', 'nvoos-graphify' ),
				'description' => __( 'Graph node type to assign to ingested items.', 'nvoos-graphify' ),
				'default'     => 'article',
			),
			'max_items' => array(
				'type'        => 'number',
				'label'       => __( 'Max Items', 'nvoos-graphify' ),
				'description' => __( 'Maximum items to ingest per sync (0 = unlimited).', 'nvoos-graphify' ),
				'default'     => 100,
			),
		);
	}

	public function testConnection(): array {
		$feedUrl = $this->getFeedUrl();
		if ( empty( $feedUrl ) ) {
			return array(
				'success' => false,
				'message' => __( 'No feed_url configured.', 'nvoos-graphify' ),
			);
		}

		$result = $this->http->get( $feedUrl );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			return array(
				'success' => false,
				/* translators: %d HTTP status code */
				'message' => sprintf( __( 'HTTP %d.', 'nvoos-graphify' ), $result['status'] ),
			);
		}

		// Validate XML.
		$xml = @simplexml_load_string( $result['body'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $xml ) {
			return array(
				'success' => false,
				'message' => __( 'Could not parse XML from feed.', 'nvoos-graphify' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Feed accessible.', 'nvoos-graphify' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'feed_url'     => $this->getFeedUrl(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		$feedUrl    = $this->getFeedUrl();
		$maxItems   = absint( $this->config['max_items'] ?? 100 );
		$sourceSlug = $this->config['_slug'] ?? 'rss_sitemap';

		if ( empty( $feedUrl ) ) {
			return array();
		}

		$result = $this->http->get( $feedUrl );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		if ( empty( $result['body'] ) ) {
			return array();
		}

		$xml = @simplexml_load_string( $result['body'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $xml ) {
			return array();
		}

		$feedType = $this->detectFeedType( $xml );

		switch ( $feedType ) {
			case 'sitemap':
				return $this->parseSitemap( $xml, $sourceSlug, $maxItems );
			case 'atom':
				return $this->parseAtom( $xml, $sourceSlug, $maxItems );
			case 'rss':
			default:
				return $this->parseRss( $xml, $sourceSlug, $maxItems );
		}
	}

	public function fetchEdges( array $args = array() ): array {
		return array();
	}

	public function reconcile( $localNode ): array {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Auto-detect feed type from XML structure.
	 *
	 * @param \SimpleXMLElement $xml Parsed XML.
	 * @return string 'rss'|'atom'|'sitemap'
	 */
	private function detectFeedType( \SimpleXMLElement $xml ): string {
		$configured = $this->config['feed_type'] ?? '';
		if ( in_array( $configured, array( 'rss', 'atom', 'sitemap' ), true ) ) {
			return $configured;
		}

		$root = strtolower( $xml->getName() );
		if ( 'feed' === $root ) {
			return 'atom';
		}
		if ( 'urlset' === $root || 'sitemapindex' === $root ) {
			return 'sitemap';
		}
		return 'rss';
	}

	/**
	 * Parse RSS 2.0 items into node arrays.
	 *
	 * @param \SimpleXMLElement $xml        Parsed XML.
	 * @param string            $sourceSlug Source slug.
	 * @param int               $maxItems   Max items.
	 * @return array<int,array<string,mixed>>
	 */
	private function parseRss( \SimpleXMLElement $xml, string $sourceSlug, int $maxItems ): array {
		$nodes    = array();
		$nodeType = sanitize_text_field( (string) ( $this->config['node_type'] ?? 'article' ) );
		$count    = 0;

		if ( ! isset( $xml->channel->item ) ) {
			return array();
		}

		foreach ( $xml->channel->item as $item ) {
			if ( $maxItems > 0 && $count >= $maxItems ) {
				break;
			}

			$title = sanitize_text_field( (string) ( $item->title ?? '' ) );
			$link  = esc_url_raw( (string) ( $item->link ?? '' ) );

			if ( empty( $title ) ) {
				continue;
			}

			$description = sanitize_text_field( (string) ( $item->description ?? '' ) );
			$guid        = sanitize_text_field( (string) ( $item->guid ?? '' ) );
			$remoteId    = $guid ?: ( $link ?: md5( $title ) );

			$nodeId = 'remote_' . sanitize_key( $sourceSlug ) . '_' . md5( $remoteId );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $title,
				'type'        => $nodeType,
				'post_id'     => 0,
				'url'         => $link,
				'properties'  => array( 'description' => $description ),
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Parse Atom 1.0 entries into node arrays.
	 *
	 * @param \SimpleXMLElement $xml        Parsed XML.
	 * @param string            $sourceSlug Source slug.
	 * @param int               $maxItems   Max items.
	 * @return array<int,array<string,mixed>>
	 */
	private function parseAtom( \SimpleXMLElement $xml, string $sourceSlug, int $maxItems ): array {
		$nodes    = array();
		$nodeType = sanitize_text_field( (string) ( $this->config['node_type'] ?? 'article' ) );
		$count    = 0;

		if ( ! isset( $xml->entry ) ) {
			return array();
		}

		foreach ( $xml->entry as $entry ) {
			if ( $maxItems > 0 && $count >= $maxItems ) {
				break;
			}

			$title = sanitize_text_field( (string) ( $entry->title ?? '' ) );
			$link  = '';
			if ( isset( $entry->link ) ) {
				foreach ( $entry->link as $l ) {
					$rel  = (string) ( $l['rel'] ?? 'alternate' );
					$href = esc_url_raw( (string) ( $l['href'] ?? '' ) );
					if ( 'self' !== $rel && '' !== $href ) {
						$link = $href;
						break;
					}
				}
			}

			if ( empty( $title ) ) {
				continue;
			}

			$remoteId = sanitize_text_field( (string) ( $entry->id ?? '' ) );
			$nodeId   = 'remote_' . sanitize_key( $sourceSlug ) . '_' . md5( $remoteId ?: $title );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $title,
				'type'        => $nodeType,
				'post_id'     => 0,
				'url'         => $link,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $remoteId,
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Parse XML Sitemap URLs into node arrays.
	 *
	 * @param \SimpleXMLElement $xml        Parsed XML.
	 * @param string            $sourceSlug Source slug.
	 * @param int               $maxItems   Max items.
	 * @return array<int,array<string,mixed>>
	 */
	private function parseSitemap( \SimpleXMLElement $xml, string $sourceSlug, int $maxItems ): array {
		$nodes    = array();
		$nodeType = sanitize_text_field( (string) ( $this->config['node_type'] ?? 'page' ) );
		$count    = 0;

		if ( ! isset( $xml->url ) ) {
			return array();
		}

		foreach ( $xml->url as $urlEl ) {
			if ( $maxItems > 0 && $count >= $maxItems ) {
				break;
			}

			$loc = esc_url_raw( (string) ( $urlEl->loc ?? '' ) );
			if ( empty( $loc ) ) {
				continue;
			}

			$nodeId = 'remote_' . sanitize_key( $sourceSlug ) . '_' . md5( $loc );

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => $loc,
				'type'        => $nodeType,
				'post_id'     => 0,
				'url'         => $loc,
				'source_slug' => $sourceSlug,
				'provenance'  => 'REMOTE',
				'external_id' => $loc,
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Return the configured feed URL.
	 *
	 * @return string
	 */
	private function getFeedUrl(): string {
		return esc_url_raw( (string) ( $this->config['feed_url'] ?? '' ) );
	}
}
