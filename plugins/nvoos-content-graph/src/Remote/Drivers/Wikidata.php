<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote\Drivers;

use NvoosContentGraph\Contracts\RemoteSource;
use NvoosContentGraph\Remote\HttpClient;
use function add_query_arg;
use function is_wp_error;
use function json_decode;
use function rawurlencode;
use function sanitize_text_field;

/**
 * Wikidata entity reconciliation driver.
 *
 * Matches local knowledge-graph nodes to Wikidata entities via the
 * wbsearchentities API. Reconciliation-only — no fetch_nodes/fetch_edges.
 *
 * @since 1.0.0
 */
class Wikidata implements RemoteSource {

	/** @var string Wikidata API base URL. */
	private const API_URL = 'https://www.wikidata.org/w/api.php';

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	/** @var HttpClient HTTP client instance. */
	private HttpClient $http;

	public function __construct() {
		$this->http = new HttpClient( 'wikidata' );
	}

	public function getDriverId(): string {
		return 'wikidata';
	}

	public function getDriverLabel(): string {
		return __( 'Wikidata (Entity Reconciliation)', 'nvoos-content-graph' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
		$slug         = $config['_slug'] ?? 'wikidata';
		$this->http   = new HttpClient( $slug );
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'reconcile' );
	}

	public function getConfigSchema(): array {
		return array(
			'language'       => array(
				'type'        => 'text',
				'label'       => __( 'Language', 'nvoos-content-graph' ),
				'description' => __( 'BCP 47 language code (e.g. en, de, fr).', 'nvoos-content-graph' ),
				'default'     => 'en',
			),
			'min_confidence' => array(
				'type'        => 'number',
				'label'       => __( 'Min Confidence', 'nvoos-content-graph' ),
				'description' => __( 'Minimum match confidence threshold (0.0–1.0).', 'nvoos-content-graph' ),
				'default'     => 0.6,
			),
		);
	}

	public function testConnection(): array {
		$url    = add_query_arg(
			array(
				'action'   => 'wbsearchentities',
				'search'   => 'WordPress',
				'language' => 'en',
				'limit'    => 1,
				'format'   => 'json',
			),
			self::API_URL
		);
		$result = $this->http->get( $url );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$data = json_decode( $result['body'], true );
		if ( empty( $data['search'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Empty results from Wikidata.', 'nvoos-content-graph' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connected to Wikidata.', 'nvoos-content-graph' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'capabilities' => $this->getCapabilities(),
			'endpoint'     => self::API_URL,
		);
	}

	public function fetchNodes( array $args = array() ): array {
		return array();
	}

	public function fetchEdges( array $args = array() ): array {
		return array();
	}

	public function reconcile( $localNode ): array {
		$label = is_object( $localNode ) ? ( $localNode->label ?? '' ) : ( $localNode['label'] ?? '' );
		$label = sanitize_text_field( (string) $label );
		if ( empty( $label ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$lang = $this->config['language'] ?? 'en';

		$url    = add_query_arg(
			array(
				'action'   => 'wbsearchentities',
				'search'   => rawurlencode( $label ),
				'language' => $lang,
				'limit'    => 5,
				'format'   => 'json',
				'type'     => 'item',
			),
			self::API_URL
		);
		$result = $this->http->get( $url );

		if ( is_wp_error( $result ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$data = json_decode( $result['body'], true );
		if ( empty( $data['search'] ) || ! is_array( $data['search'] ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$nodeType = is_object( $localNode ) ? ( $localNode->type ?? '' ) : ( $localNode['type'] ?? '' );

		$bestMatch      = null;
		$bestConfidence = 0.0;
		$minConfidence  = (float) ( $this->config['min_confidence'] ?? 0.6 );

		foreach ( $data['search'] as $item ) {
			$wikidataLabel       = $item['label'] ?? '';
			$wikidataDescription = $item['description'] ?? '';
			$qid                 = $item['id'] ?? '';

			if ( empty( $qid ) ) {
				continue;
			}

			$confidence = $this->calculateConfidence( $label, $wikidataLabel, $wikidataDescription, $nodeType );

			if ( $confidence > $bestConfidence ) {
				$bestConfidence = $confidence;
				$bestMatch      = $item;
			}
		}

		if ( null === $bestMatch || $bestConfidence < $minConfidence ) {
			return array(
				'external_id' => '',
				'confidence'  => $bestConfidence,
				'matched'     => false,
			);
		}

		$qid = $bestMatch['id'];
		return array(
			'external_id'  => $qid,
			'confidence'   => $bestConfidence,
			'matched'      => true,
			'wikidata_url' => 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ),
			'label'        => $bestMatch['label'] ?? '',
			'description'  => $bestMatch['description'] ?? '',
		);
	}

	/**
	 * Calculate confidence score based on label similarity.
	 *
	 * @param string $localLabel      Local node label.
	 * @param string $wikidataLabel   Wikidata item label.
	 * @param string $description     Wikidata item description.
	 * @param string $nodeType        Local node type.
	 * @return float Confidence score 0.0–1.0.
	 */
	private function calculateConfidence( string $localLabel, string $wikidataLabel, string $description, string $nodeType ): float {
		$localLower = strtolower( trim( $localLabel ) );
		$itemLower  = strtolower( trim( $wikidataLabel ) );

		if ( $localLower === $itemLower ) {
			return 0.9 + ( empty( $description ) ? -0.05 : 0.05 );
		}

		if ( false !== strpos( $itemLower, $localLower ) || false !== strpos( $localLower, $itemLower ) ) {
			return 0.7;
		}

		$localWords = array_filter( explode( ' ', $localLower ) );
		$itemWords  = array_filter( explode( ' ', $itemLower ) );
		if ( ! empty( $localWords ) && ! empty( $itemWords ) ) {
			$intersect = array_intersect( $localWords, $itemWords );
			$score     = count( $intersect ) / max( count( $localWords ), count( $itemWords ) );
			return min( 0.6, $score );
		}

		return 0.1;
	}
}
