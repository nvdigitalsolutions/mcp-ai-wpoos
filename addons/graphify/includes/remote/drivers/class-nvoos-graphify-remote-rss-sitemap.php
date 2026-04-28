<?php
/**
 * NV oOS Graphify — RSS / Atom / Sitemap Remote Driver
 *
 * Ingests feed items or sitemap URLs as knowledge-graph nodes.
 * Supports RSS 2.0, Atom 1.0, and XML Sitemaps.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RSS / Atom / Sitemap remote source driver.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_RSS_Sitemap implements NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Driver configuration.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * HTTP client instance.
	 *
	 * @var NV_oOS_Graphify_HTTP_Client
	 */
	private $http;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'rss_sitemap' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'rss_sitemap';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'RSS / Atom / Sitemap Feed', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 'rss_sitemap';
		$this->http   = new NV_oOS_Graphify_HTTP_Client( $slug );
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes' );
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
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

	/** {@inheritdoc} */
	public function test_connection() {
		$feed_url = $this->get_feed_url();
		if ( empty( $feed_url ) ) {
			return array(
				'success' => false,
				'message' => __( 'No feed_url configured.', 'nvoos-graphify' ),
			);
		}

		$result = $this->http->get( $feed_url );
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

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'feed_url'     => $this->get_feed_url(),
			'capabilities' => $this->get_capabilities(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments.
	 */
	public function fetch_nodes( array $args = array() ) {
		$feed_url    = $this->get_feed_url();
		$max_items   = isset( $this->config['max_items'] ) ? absint( $this->config['max_items'] ) : 100;
		$source_slug = isset( $this->config['_slug'] ) ? $this->config['_slug'] : 'rss_sitemap';

		if ( empty( $feed_url ) ) {
			return array();
		}

		$result = $this->http->get( $feed_url );
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

		$feed_type = $this->detect_feed_type( $xml );

		switch ( $feed_type ) {
			case 'sitemap':
				return $this->parse_sitemap( $xml, $source_slug, $max_items );
			case 'atom':
				return $this->parse_atom( $xml, $source_slug, $max_items );
			case 'rss':
			default:
				return $this->parse_rss( $xml, $source_slug, $max_items );
		}
	}

	/**
	 * Reconciliation not supported by this driver.
	 *
	 * @param object $local_node Unused.
	 * @return array
	 */
	public function reconcile( $local_node ) {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Edges not supported by this driver.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public function fetch_edges( array $args = array() ) {
		return array();
	}

	/**
	 * Auto-detect feed type from XML structure.
	 *
	 * @since 0.6.0
	 *
	 * @param SimpleXMLElement $xml Parsed XML.
	 * @return string 'rss'|'atom'|'sitemap'
	 */
	private function detect_feed_type( $xml ) {
		$configured = isset( $this->config['feed_type'] ) ? $this->config['feed_type'] : '';
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
	 * @since 0.6.0
	 *
	 * @param SimpleXMLElement $xml         Parsed XML.
	 * @param string           $source_slug Source slug.
	 * @param int              $max_items   Max items.
	 * @return array
	 */
	private function parse_rss( $xml, $source_slug, $max_items ) {
		$nodes = array();
		$count = 0;

		if ( ! isset( $xml->channel->item ) ) {
			return $nodes;
		}

		foreach ( $xml->channel->item as $item ) {
			if ( $count >= $max_items ) {
				break;
			}
			$title       = isset( $item->title ) ? sanitize_text_field( (string) $item->title ) : '';
			$link        = isset( $item->link ) ? esc_url_raw( (string) $item->link ) : '';
			$description = isset( $item->description ) ? wp_kses_post( (string) $item->description ) : '';
			$pub_date    = isset( $item->pubDate ) ? sanitize_text_field( (string) $item->pubDate ) : '';  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( empty( $title ) && empty( $link ) ) {
				continue;
			}
			$label   = $title ? $title : $link;
			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . md5( $link ? $link : $label );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => 'remote_post',
				'post_id'     => 0,
				'url'         => $link,
				'properties'  => array(
					'description' => $description,
					'pub_date'    => $pub_date,
				),
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Parse Atom 1.0 entries into node arrays.
	 *
	 * @since 0.6.0
	 *
	 * @param SimpleXMLElement $xml         Parsed XML.
	 * @param string           $source_slug Source slug.
	 * @param int              $max_items   Max items.
	 * @return array
	 */
	private function parse_atom( $xml, $source_slug, $max_items ) {
		$nodes = array();
		$count = 0;

		if ( ! isset( $xml->entry ) ) {
			return $nodes;
		}

		foreach ( $xml->entry as $entry ) {
			if ( $count >= $max_items ) {
				break;
			}
			$title = isset( $entry->title ) ? sanitize_text_field( (string) $entry->title ) : '';
			$link  = '';
			if ( isset( $entry->link ) ) {
				foreach ( $entry->link as $l ) {
					$attrs = $l->attributes();
					if ( isset( $attrs['href'] ) ) {
						$link = esc_url_raw( (string) $attrs['href'] );
						break;
					}
				}
			}
			$updated = isset( $entry->updated ) ? sanitize_text_field( (string) $entry->updated ) : '';
			$summary = isset( $entry->summary ) ? wp_kses_post( (string) $entry->summary ) : '';
			$label   = $title ? $title : $link;

			if ( empty( $label ) ) {
				continue;
			}
			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . md5( $link ? $link : $label );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => 'remote_post',
				'post_id'     => 0,
				'url'         => $link,
				'properties'  => array(
					'updated' => $updated,
					'summary' => $summary,
				),
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Parse XML Sitemap URLs into node arrays.
	 *
	 * @since 0.6.0
	 *
	 * @param SimpleXMLElement $xml         Parsed XML.
	 * @param string           $source_slug Source slug.
	 * @param int              $max_items   Max items.
	 * @return array
	 */
	private function parse_sitemap( $xml, $source_slug, $max_items ) {
		$nodes = array();
		$count = 0;

		if ( ! isset( $xml->url ) ) {
			return $nodes;
		}

		foreach ( $xml->url as $url_entry ) {
			if ( $count >= $max_items ) {
				break;
			}
			$loc     = isset( $url_entry->loc ) ? esc_url_raw( (string) $url_entry->loc ) : '';
			$lastmod = isset( $url_entry->lastmod ) ? sanitize_text_field( (string) $url_entry->lastmod ) : '';

			if ( empty( $loc ) ) {
				continue;
			}

			// Use the URL path as a label.
			$path  = wp_parse_url( $loc, PHP_URL_PATH );
			$label = $path ? trim( $path, '/' ) : $loc;
			$label = $label ? sanitize_text_field( $label ) : $loc;

			$node_id = 'remote_' . sanitize_key( $source_slug ) . '_' . md5( $loc );

			$nodes[] = array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => 'remote_url',
				'post_id'     => 0,
				'url'         => $loc,
				'properties'  => array( 'lastmod' => $lastmod ),
				'source_slug' => $source_slug,
				'provenance'  => 'REMOTE',
			);
			++$count;
		}

		return $nodes;
	}

	/**
	 * Return the configured feed URL.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	private function get_feed_url() {
		$url = isset( $this->config['feed_url'] ) ? $this->config['feed_url'] : '';
		return esc_url_raw( $url );
	}
}
