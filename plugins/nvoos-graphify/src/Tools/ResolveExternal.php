<?php
declare(strict_types=1);

namespace NvoosGraphify\Tools;

use WP_Error;
use function add_query_arg;
use function esc_url_raw;
use function sanitize_key;
use function sanitize_text_field;
use function wp_parse_url;

/**
 * Tool: nvoos_graphify_resolve_external
 *
 * Resolves a Wikidata QID, schema.org URL, or remote oOS post ID to a
 * local knowledge-graph node; auto-ingests the entity if not found locally.
 *
 * @since 1.0.0
 */
class ResolveExternal extends AbstractTool {

	/**
	 * {@inheritdoc}
	 */
	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function getSlug(): string {
		return 'nvoos_graphify_resolve_external';
	}

	/** {@inheritdoc} */
	public function getName(): string {
		return __( 'Resolve External Entity', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function getDescription(): string {
		return __( 'Resolves a Wikidata QID (e.g. Q42), a schema.org URL, or a remote oOS post ID to a local knowledge-graph node. If the entity is not found locally and auto_ingest is true, it will be fetched from Wikidata and ingested as a new node. Returns the local node data including its external_id, label, type, and any existing edges.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'ref'         => array(
					'type'        => 'string',
					'description' => __( 'A Wikidata QID (e.g. "Q42"), a full URL, or an external entity ID.', 'nvoos-graphify' ),
					'maxLength'   => 512,
				),
				'auto_ingest' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, fetch and ingest the entity from Wikidata if not found locally.', 'nvoos-graphify' ),
					'default'     => true,
				),
			),
			'required'             => array( 'ref' ),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function getCapabilityFlags(): array {
		return array( 'read-only', 'external-api' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $context   Execution context.
	 * @return array<string,mixed>|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$ref         = sanitize_text_field( $arguments['ref'] ?? '' );
		$auto_ingest = isset( $arguments['auto_ingest'] ) ? (bool) $arguments['auto_ingest'] : true;

		if ( empty( $ref ) ) {
			return array(
				'success' => false,
				'error'   => __( 'ref is required.', 'nvoos-graphify' ),
			);
		}

		// Step 1: detect ref type.
		$ref_type = $this->detectRefType( $ref );

		// Step 2: search local graph.
		$local_node = \NvoosGraphify\Graph\Db::getNodeByExternalId( $ref );

		// If not found by external_id, try label search for URL or label refs.
		if ( ! $local_node && 'url' === $ref_type ) {
			// Search by URL in nodes table.
			$local_node = $this->findNodeByUrl( $ref );
		}

		if ( $local_node ) {
			return array(
				'success'  => true,
				'found'    => true,
				'ingested' => false,
				'node'     => $this->formatNode( $local_node ),
			);
		}

		// Step 3: auto-ingest if requested.
		if ( ! $auto_ingest ) {
			return array(
				'success'  => true,
				'found'    => false,
				'ingested' => false,
				'node'     => null,
			);
		}

		$node = null;

		if ( 'qid' === $ref_type ) {
			$node = $this->ingestFromWikidata( $ref );
		} elseif ( 'url' === $ref_type ) {
			$node = $this->ingestUrlAsNode( $ref );
		}

		if ( ! $node ) {
			return array(
				'success'  => true,
				'found'    => false,
				'ingested' => false,
				'node'     => null,
			);
		}

		return array(
			'success'  => true,
			'found'    => true,
			'ingested' => true,
			'node'     => $this->formatNode( $node ),
		);
	}

	/**
	 * Detect the type of external reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ref Reference string.
	 * @return string 'qid'|'url'|'label'
	 */
	private function detectRefType( string $ref ): string {
		// Wikidata QID: starts with Q followed by digits.
		if ( preg_match( '/^Q\d+$/', $ref ) ) {
			return 'qid';
		}
		// URL.
		if ( 0 === strpos( $ref, 'http' ) ) {
			return 'url';
		}
		return 'label';
	}

	/**
	 * Ingest a Wikidata entity by QID as a local node.
	 *
	 * @since 1.0.0
	 *
	 * @param string $qid Wikidata QID (e.g. 'Q42').
	 * @return object|null Created/found node object or null.
	 */
	private function ingestFromWikidata( string $qid ) {
		// Wikidata ingestion requires the HTTP client (Phase 7 feature).
		if ( ! class_exists( \NvoosGraphify\Remote\HttpClient::class ) ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'action'    => 'wbgetentities',
				'ids'       => $qid,
				'props'     => 'labels|descriptions|sitelinks/urls',
				'languages' => 'en',
				'format'    => 'json',
			),
			'https://www.wikidata.org/w/api.php'
		);

		$http     = new \NvoosGraphify\Remote\HttpClient( 'wikidata_ingest' );
		$response = $http->get( $url );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( $response['body'], true );
		if ( empty( $data['entities'][ $qid ] ) ) {
			return null;
		}

		$entity = $data['entities'][ $qid ];
		$label  = isset( $entity['labels']['en']['value'] ) ? sanitize_text_field( $entity['labels']['en']['value'] ) : $qid;
		$desc   = isset( $entity['descriptions']['en']['value'] ) ? sanitize_text_field( $entity['descriptions']['en']['value'] ) : '';

		// Determine canonical Wikipedia URL.
		$wiki_url = '';
		if ( ! empty( $entity['sitelinks']['enwiki']['url'] ) ) {
			$wiki_url = esc_url_raw( $entity['sitelinks']['enwiki']['url'] );
		}

		$node_id = 'entity_wikidata_' . sanitize_key( $qid );
		\NvoosGraphify\Graph\Db::upsertNode(
			array(
				'node_id'     => $node_id,
				'label'       => $label,
				'type'        => 'entity',
				'post_id'     => 0,
				'url'         => $wiki_url,
				'properties'  => array(
					'description' => $desc,
					'qid'         => $qid,
				),
				'external_id' => $qid,
				'provenance'  => 'REMOTE',
			)
		);

		return \NvoosGraphify\Graph\Db::getNode( $node_id );
	}

	/**
	 * Ingest a URL as a remote node.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to ingest.
	 * @return object|null Node object.
	 */
	private function ingestUrlAsNode( string $url ) {
		$node_id = 'remote_url_' . md5( $url );
		$path    = wp_parse_url( $url, PHP_URL_PATH );
		$label   = $path ? trim( $path, '/' ) : $url;

		\NvoosGraphify\Graph\Db::upsertNode(
			array(
				'node_id'     => $node_id,
				'label'       => sanitize_text_field( $label ),
				'type'        => 'remote_url',
				'post_id'     => 0,
				'url'         => $url,
				'properties'  => array(),
				'external_id' => $url,
				'provenance'  => 'REMOTE',
			)
		);

		return \NvoosGraphify\Graph\Db::getNode( $node_id );
	}

	/**
	 * Find a node by its URL field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to look up.
	 * @return object|null Node or null.
	 */
	private function findNodeByUrl( string $url ) {
		global $wpdb;
		$table = \NvoosGraphify\Graph\Db::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE url = %s LIMIT 1", esc_url_raw( $url ) )
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Format a node object for the response.
	 *
	 * @param object $node Node object.
	 * @return array<string,mixed>
	 */
	private function formatNode( $node ): array {
		return array(
			'node_id'     => $node->node_id,
			'label'       => $node->label,
			'type'        => $node->type,
			'url'         => $node->url,
			'external_id' => isset( $node->external_id ) ? $node->external_id : '',
			'degree'      => isset( $node->degree ) ? $node->degree : 0,
			'properties'  => is_string( $node->properties ) ? json_decode( $node->properties, true ) : (array) $node->properties,
		);
	}
}
