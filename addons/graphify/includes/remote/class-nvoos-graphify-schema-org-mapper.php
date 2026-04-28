<?php
/**
 * NV oOS Graphify — schema.org Auto-Typing Helper
 *
 * Maps free-text node types (e.g. "product", "blog post", "person",
 * "company") onto canonical schema.org types so that downstream JSON-LD
 * emission, faceting, and federation can speak a shared vocabulary.
 *
 * The mapping is intentionally conservative — only well-established
 * schema.org core types are emitted, and unknown inputs are returned
 * unchanged so callers can decide whether to keep the local type. The
 * `nvoos_graphify_schema_org_aliases` filter lets consumers extend or
 * override the alias table.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema.org auto-typing helper.
 *
 * @since 0.7.7
 */
class NV_oOS_Graphify_Schema_Org_Mapper {

	/**
	 * Canonical schema.org IRI base.
	 *
	 * @var string
	 */
	const IRI_BASE = 'https://schema.org/';

	/**
	 * Built-in alias table — keys are normalised lowercase strings, values
	 * are canonical schema.org type names. Only well-established core
	 * types are included; longer-tail types should come in via filter.
	 *
	 * @var array<string,string>
	 */
	private static $defaults = array(
		// People / orgs.
		'person'       => 'Person',
		'people'       => 'Person',
		'user'         => 'Person',
		'author'       => 'Person',
		'org'          => 'Organization',
		'organization' => 'Organization',
		'organisation' => 'Organization',
		'company'      => 'Organization',
		'corporation'  => 'Corporation',
		'team'         => 'Organization',

		// Content.
		'article'      => 'Article',
		'blog post'    => 'BlogPosting',
		'blogpost'     => 'BlogPosting',
		'blog'         => 'Blog',
		'post'         => 'BlogPosting',
		'news'         => 'NewsArticle',
		'news article' => 'NewsArticle',
		'page'         => 'WebPage',
		'webpage'      => 'WebPage',
		'web page'     => 'WebPage',
		'site'         => 'WebSite',
		'website'      => 'WebSite',
		'web site'     => 'WebSite',
		'document'     => 'CreativeWork',
		'image'        => 'ImageObject',
		'photo'        => 'ImageObject',
		'video'        => 'VideoObject',
		'audio'        => 'AudioObject',
		'media'        => 'MediaObject',
		'book'         => 'Book',
		'recipe'       => 'Recipe',
		'review'       => 'Review',
		'rating'       => 'Rating',
		'comment'      => 'Comment',

		// Commerce.
		'product'      => 'Product',
		'sku'          => 'Product',
		'item'         => 'Product',
		'service'      => 'Service',
		'offer'        => 'Offer',
		'order'        => 'Order',
		'invoice'      => 'Invoice',
		'payment'      => 'PaymentMethod',

		// Place / event.
		'event'        => 'Event',
		'place'        => 'Place',
		'location'     => 'Place',
		'address'      => 'PostalAddress',
		'country'      => 'Country',
		'city'         => 'City',

		// Knowledge.
		'tag'          => 'DefinedTerm',
		'term'         => 'DefinedTerm',
		'taxonomy'     => 'DefinedTermSet',
		'category'     => 'CategoryCode',
		'topic'        => 'Thing',
		'concept'      => 'DefinedTerm',
		'entity'       => 'Thing',
		'thing'        => 'Thing',

		// Software / data.
		'project'      => 'Project',
		'repository'   => 'SoftwareSourceCode',
		'repo'         => 'SoftwareSourceCode',
		'software'     => 'SoftwareApplication',
		'app'          => 'SoftwareApplication',
		'dataset'      => 'Dataset',
		'data'         => 'Dataset',
		'api'          => 'WebAPI',

		// Issue tracking / ops.
		'issue'        => 'Action',
		'ticket'       => 'Action',
		'incident'     => 'Action',
		'task'         => 'Action',
	);

	/**
	 * Return the alias table merged with any consumer-provided overrides
	 * via the `nvoos_graphify_schema_org_aliases` filter. Keys are
	 * normalised lowercase strings.
	 *
	 * @return array<string,string>
	 */
	public static function get_aliases() {
		$aliases = self::$defaults;
		if ( function_exists( 'apply_filters' ) ) {
			$aliases = (array) apply_filters( 'nvoos_graphify_schema_org_aliases', $aliases );
			$out     = array();
			foreach ( $aliases as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) && '' !== $v ) {
					$out[ self::normalise( $k ) ] = $v;
				}
			}
			return $out;
		}
		return $aliases;
	}

	/**
	 * Map a free-text type to a canonical schema.org type name.
	 *
	 * Returns the original input (trimmed) when no alias is found so the
	 * caller may keep the local type as-is.
	 *
	 * @param string $raw_type  Free-text type label.
	 * @return string           Canonical schema.org type name (e.g. "Product")
	 *                          or the trimmed original on miss.
	 */
	public static function map_type( $raw_type ) {
		$raw_type = (string) $raw_type;
		$norm     = self::normalise( $raw_type );
		if ( '' === $norm ) {
			return '';
		}

		$aliases = self::get_aliases();
		if ( isset( $aliases[ $norm ] ) ) {
			return $aliases[ $norm ];
		}

		// If the input already looks like a PascalCase schema.org type,
		// accept it as-is so call sites can pass canonical input through
		// without losing it.
		if ( preg_match( '/^[A-Z][A-Za-z0-9]+$/', trim( $raw_type ) ) ) {
			return trim( $raw_type );
		}

		return trim( $raw_type );
	}

	/**
	 * Build the canonical schema.org IRI for a given type name. Returns
	 * '' for empty input.
	 *
	 * @param string $type Schema.org type name.
	 * @return string
	 */
	public static function to_iri( $type ) {
		$type = trim( (string) $type );
		if ( '' === $type ) {
			return '';
		}
		// Only canonical PascalCase types get an IRI; everything else
		// returns '' so callers can detect "unmapped".
		if ( ! preg_match( '/^[A-Z][A-Za-z0-9]+$/', $type ) ) {
			return '';
		}
		return self::IRI_BASE . $type;
	}

	/**
	 * Returns true if the given type is a recognised canonical alias
	 * target (i.e. exists somewhere in the merged alias table's values).
	 *
	 * @param string $type Schema.org type name.
	 * @return bool
	 */
	public static function is_canonical_type( $type ) {
		$type = trim( (string) $type );
		if ( '' === $type ) {
			return false;
		}
		$aliases = self::get_aliases();
		return in_array( $type, $aliases, true );
	}

	/**
	 * Enrich a node array with schema.org typing metadata. Idempotent —
	 * if the node already has `properties.schema_type` set it is left
	 * untouched.
	 *
	 * Adds:
	 *   - properties.schema_type — canonical schema.org type name
	 *   - properties.schema_iri  — full IRI ('' when not mappable)
	 *
	 * @param array $node Node array compatible with NV_oOS_Graphify_DB::upsert_node().
	 * @return array
	 */
	public static function enrich_node( array $node ) {
		if ( ! isset( $node['properties'] ) || ! is_array( $node['properties'] ) ) {
			$node['properties'] = array();
		}
		if ( isset( $node['properties']['schema_type'] ) && '' !== $node['properties']['schema_type'] ) {
			return $node;
		}
		$raw    = isset( $node['type'] ) ? (string) $node['type'] : '';
		$mapped = self::map_type( $raw );
		if ( '' === $mapped ) {
			return $node;
		}
		$iri                               = self::to_iri( $mapped );
		$node['properties']['schema_type'] = $mapped;
		$node['properties']['schema_iri']  = $iri;
		return $node;
	}

	/**
	 * Bulk variant of enrich_node().
	 *
	 * @param array $nodes List of node arrays.
	 * @return array
	 */
	public static function enrich_nodes( array $nodes ) {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) ) {
				$out[] = self::enrich_node( $node );
			} else {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * Normalise a type label for lookup: lowercase, single-spaced, trimmed.
	 *
	 * @param string $type Free-text type.
	 * @return string
	 */
	private static function normalise( $type ) {
		$type = strtolower( (string) $type );
		$type = preg_replace( '/[\s_\-]+/', ' ', $type );
		return trim( (string) $type );
	}
}
