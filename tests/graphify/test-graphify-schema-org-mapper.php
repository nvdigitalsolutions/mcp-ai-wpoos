<?php
/**
 * Tests for the schema.org auto-typing helper — Phase 5 batch 1.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Schema_Org_Mapper
 */
class Test_Graphify_Schema_Org_Mapper extends WP_UnitTestCase {

	/**
	 * Common aliases map to the expected canonical types.
	 */
	public function test_map_type_common_aliases() {
		$cases = array(
			'product'      => 'Product',
			'Product'      => 'Product',
			'PRODUCT'      => 'Product',
			'  product  '  => 'Product',
			'blog post'    => 'BlogPosting',
			'blog_post'    => 'BlogPosting',
			'blog-post'    => 'BlogPosting',
			'person'       => 'Person',
			'organization' => 'Organization',
			'organisation' => 'Organization',
			'company'      => 'Organization',
			'event'        => 'Event',
			'place'        => 'Place',
			'image'        => 'ImageObject',
			'video'        => 'VideoObject',
			'recipe'       => 'Recipe',
			'dataset'      => 'Dataset',
			'incident'     => 'Action',
			'repo'         => 'SoftwareSourceCode',
			'webpage'      => 'WebPage',
			'web page'     => 'WebPage',
		);
		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, NV_oOS_Graphify_Schema_Org_Mapper::map_type( $input ), "input: {$input}" );
		}
	}

	/**
	 * Empty / whitespace input yields an empty string.
	 */
	public function test_map_type_handles_empty_input() {
		$this->assertSame( '', NV_oOS_Graphify_Schema_Org_Mapper::map_type( '' ) );
		$this->assertSame( '', NV_oOS_Graphify_Schema_Org_Mapper::map_type( '   ' ) );
	}

	/**
	 * Unknown lowercase input is returned unchanged (caller decides).
	 */
	public function test_map_type_returns_unknown_input_unchanged() {
		$this->assertSame( 'galactic federation node', NV_oOS_Graphify_Schema_Org_Mapper::map_type( 'galactic federation node' ) );
	}

	/**
	 * PascalCase input that looks like a canonical schema.org type is
	 * preserved verbatim, even when not in the alias table.
	 */
	public function test_map_type_passes_through_canonical_pascal_case() {
		$this->assertSame( 'MusicAlbum', NV_oOS_Graphify_Schema_Org_Mapper::map_type( 'MusicAlbum' ) );
		$this->assertSame( 'Quiz', NV_oOS_Graphify_Schema_Org_Mapper::map_type( 'Quiz' ) );
	}

	/**
	 * IRI builder returns full URLs for valid PascalCase types and ''
	 * for inputs that aren't valid type names.
	 */
	public function test_to_iri() {
		$this->assertSame( 'https://schema.org/Product', NV_oOS_Graphify_Schema_Org_Mapper::to_iri( 'Product' ) );
		$this->assertSame( 'https://schema.org/BlogPosting', NV_oOS_Graphify_Schema_Org_Mapper::to_iri( 'BlogPosting' ) );
		$this->assertSame( '', NV_oOS_Graphify_Schema_Org_Mapper::to_iri( '' ) );
		$this->assertSame( '', NV_oOS_Graphify_Schema_Org_Mapper::to_iri( 'lowercase' ) );
		$this->assertSame( '', NV_oOS_Graphify_Schema_Org_Mapper::to_iri( 'has space' ) );
	}

	/**
	 * Is_canonical_type recognises canonical alias targets.
	 */
	public function test_is_canonical_type() {
		$this->assertTrue( NV_oOS_Graphify_Schema_Org_Mapper::is_canonical_type( 'Product' ) );
		$this->assertTrue( NV_oOS_Graphify_Schema_Org_Mapper::is_canonical_type( 'WebPage' ) );
		$this->assertFalse( NV_oOS_Graphify_Schema_Org_Mapper::is_canonical_type( 'product' ) );
		$this->assertFalse( NV_oOS_Graphify_Schema_Org_Mapper::is_canonical_type( '' ) );
	}

	/**
	 * Enrich_node adds schema_type / schema_iri inside properties.
	 */
	public function test_enrich_node_adds_schema_metadata() {
		$node = array(
			'node_id' => 'remote_x_1',
			'label'   => 'Hello',
			'type'    => 'product',
		);
		$out  = NV_oOS_Graphify_Schema_Org_Mapper::enrich_node( $node );
		$this->assertSame( 'Product', $out['properties']['schema_type'] );
		$this->assertSame( 'https://schema.org/Product', $out['properties']['schema_iri'] );
	}

	/**
	 * Enrich_node is idempotent — pre-existing schema_type wins.
	 */
	public function test_enrich_node_is_idempotent() {
		$node = array(
			'node_id'    => 'remote_x_2',
			'label'      => 'Custom',
			'type'       => 'product',
			'properties' => array( 'schema_type' => 'PreservedType' ),
		);
		$out  = NV_oOS_Graphify_Schema_Org_Mapper::enrich_node( $node );
		$this->assertSame( 'PreservedType', $out['properties']['schema_type'] );
		// schema_iri is not added when schema_type was pre-set.
		$this->assertArrayNotHasKey( 'schema_iri', $out['properties'] );
	}

	/**
	 * Enrich_nodes maps each entry; non-array entries pass through.
	 */
	public function test_enrich_nodes_bulk() {
		$nodes = array(
			array(
				'node_id' => 'a',
				'label'   => 'A',
				'type'    => 'person',
			),
			array(
				'node_id' => 'b',
				'label'   => 'B',
				'type'    => 'event',
			),
			'not an array',
		);
		$out   = NV_oOS_Graphify_Schema_Org_Mapper::enrich_nodes( $nodes );
		$this->assertCount( 3, $out );
		$this->assertSame( 'Person', $out[0]['properties']['schema_type'] );
		$this->assertSame( 'Event', $out[1]['properties']['schema_type'] );
		$this->assertSame( 'not an array', $out[2] );
	}

	/**
	 * The `nvoos_graphify_schema_org_aliases` filter can extend the table.
	 */
	public function test_filter_can_extend_aliases() {
		$cb = static function ( $aliases ) {
			$aliases['galactic federation node'] = 'Federation';
			return $aliases;
		};
		add_filter( 'nvoos_graphify_schema_org_aliases', $cb );
		try {
			$this->assertSame( 'Federation', NV_oOS_Graphify_Schema_Org_Mapper::map_type( 'galactic federation node' ) );
		} finally {
			remove_filter( 'nvoos_graphify_schema_org_aliases', $cb );
		}
	}
}
