<?php
/**
 * NV oOS Graphify — Wikidata Remote Driver
 *
 * Reconciliation-only driver that matches local knowledge-graph nodes
 * to Wikidata entities via the wbsearchentities API.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wikidata entity reconciliation driver.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_Wikidata implements NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Wikidata API base URL.
	 *
	 * @var string
	 */
	const API_URL = 'https://www.wikidata.org/w/api.php';

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
		$this->http = new NV_oOS_Graphify_HTTP_Client( 'wikidata' );
	}

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'wikidata';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'Wikidata (Entity Reconciliation)', 'nvoos-graphify' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
		$slug         = isset( $config['_slug'] ) ? $config['_slug'] : 'wikidata';
		$this->http   = new NV_oOS_Graphify_HTTP_Client( $slug );
	}

	/** {@inheritdoc} */
	public function get_config() {
		return $this->config;
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'reconcile' );
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'language'       => array(
				'type'        => 'text',
				'label'       => __( 'Language', 'nvoos-graphify' ),
				'description' => __( 'BCP 47 language code (e.g. en, de, fr).', 'nvoos-graphify' ),
				'default'     => 'en',
			),
			'min_confidence' => array(
				'type'        => 'number',
				'label'       => __( 'Min Confidence', 'nvoos-graphify' ),
				'description' => __( 'Minimum match confidence threshold (0.0–1.0).', 'nvoos-graphify' ),
				'default'     => 0.6,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
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
				'message' => __( 'Empty results from Wikidata.', 'nvoos-graphify' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connected to Wikidata.', 'nvoos-graphify' ),
		);
	}

	/** {@inheritdoc} */
	public function discover() {
		return array(
			'driver'       => $this->get_driver_id(),
			'label'        => $this->get_driver_label(),
			'capabilities' => $this->get_capabilities(),
			'endpoint'     => self::API_URL,
		);
	}

	/**
	 * Not applicable for reconciliation-only driver.
	 *
	 * @param array $args Unused.
	 * @return array Empty array.
	 */
	public function fetch_nodes( array $args = array() ) {
		return array();
	}

	/**
	 * Not applicable for reconciliation-only driver.
	 *
	 * @param array $args Unused.
	 * @return array Empty array.
	 */
	public function fetch_edges( array $args = array() ) {
		return array();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array|object $local_node Local node to reconcile.
	 */
	public function reconcile( $local_node ) {
		$label = is_object( $local_node ) ? $local_node->label : ( isset( $local_node['label'] ) ? $local_node['label'] : '' );
		$label = sanitize_text_field( $label );
		if ( empty( $label ) ) {
			return array(
				'external_id' => '',
				'confidence'  => 0.0,
				'matched'     => false,
			);
		}

		$url    = add_query_arg(
			array(
				'action'   => 'wbsearchentities',
				'search'   => rawurlencode( $label ),
				'language' => 'en',
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

		$node_type = is_object( $local_node ) ? $local_node->type : ( isset( $local_node['type'] ) ? $local_node['type'] : '' );

		$best_match      = null;
		$best_confidence = 0.0;

		foreach ( $data['search'] as $item ) {
			$wikidata_label       = isset( $item['label'] ) ? $item['label'] : '';
			$wikidata_description = isset( $item['description'] ) ? $item['description'] : '';
			$qid                  = isset( $item['id'] ) ? $item['id'] : '';

			if ( empty( $qid ) ) {
				continue;
			}

			$confidence = $this->calculate_confidence( $label, $wikidata_label, $wikidata_description, $node_type );

			if ( $confidence > $best_confidence ) {
				$best_confidence = $confidence;
				$best_match      = $item;
			}
		}

		if ( null === $best_match || $best_confidence < 0.6 ) {
			return array(
				'external_id' => '',
				'confidence'  => $best_confidence,
				'matched'     => false,
			);
		}

		$qid = $best_match['id'];
		return array(
			'external_id'  => $qid,
			'confidence'   => $best_confidence,
			'matched'      => true,
			'wikidata_url' => 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ),
			'label'        => isset( $best_match['label'] ) ? $best_match['label'] : '',
			'description'  => isset( $best_match['description'] ) ? $best_match['description'] : '',
		);
	}

	/**
	 * Calculate confidence score for a Wikidata match.
	 *
	 * @since 0.6.0
	 *
	 * @param string $local_label   Local node label.
	 * @param string $remote_label  Wikidata entity label.
	 * @param string $description   Wikidata entity description.
	 * @param string $node_type     Local node type.
	 * @return float Confidence score 0.0–1.0.
	 */
	private function calculate_confidence( $local_label, $remote_label, $description, $node_type ) {
		$local_lower  = strtolower( trim( $local_label ) );
		$remote_lower = strtolower( trim( $remote_label ) );

		// Base confidence from label similarity.
		if ( $local_lower === $remote_lower ) {
			$confidence = 1.0;
		} elseif ( 0 === strpos( $remote_lower, $local_lower ) || 0 === strpos( $local_lower, $remote_lower ) ) {
			$confidence = 0.85;
		} elseif ( false !== strpos( $remote_lower, $local_lower ) || false !== strpos( $local_lower, $remote_lower ) ) {
			$confidence = 0.7;
		} else {
			// Try Levenshtein distance for close matches.
			$distance = levenshtein( $local_lower, $remote_lower );
			$max_len  = max( strlen( $local_lower ), strlen( $remote_lower ) );
			if ( $max_len > 0 ) {
				$similarity = 1.0 - ( $distance / $max_len );
				$confidence = max( 0.0, $similarity * 0.7 );
			} else {
				$confidence = 0.0;
			}
		}

		// Reduce if type doesn't match based on description.
		if ( $confidence > 0.0 && ! empty( $node_type ) && ! empty( $description ) ) {
			$type_keywords = $this->get_type_keywords( $node_type );
			$desc_lower    = strtolower( $description );
			$type_matched  = false;
			foreach ( $type_keywords as $kw ) {
				if ( false !== strpos( $desc_lower, $kw ) ) {
					$type_matched = true;
					break;
				}
			}
			if ( ! empty( $type_keywords ) && ! $type_matched ) {
				$confidence = max( 0.0, $confidence - 0.1 );
			}
		}

		return round( $confidence, 4 );
	}

	/**
	 * Return keywords associated with a node type for description matching.
	 *
	 * @since 0.6.0
	 *
	 * @param string $type Node type.
	 * @return string[]
	 */
	private function get_type_keywords( $type ) {
		$map = array(
			'person'       => array( 'human', 'person', 'born', 'researcher', 'author', 'politician', 'actor', 'musician' ),
			'organization' => array( 'organization', 'company', 'corporation', 'institution', 'university', 'association' ),
			'place'        => array( 'city', 'country', 'town', 'region', 'location', 'place', 'river', 'mountain' ),
			'concept'      => array( 'concept', 'theory', 'idea', 'method', 'approach', 'field', 'discipline' ),
			'entity'       => array(),
			'topic'        => array(),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}
}
