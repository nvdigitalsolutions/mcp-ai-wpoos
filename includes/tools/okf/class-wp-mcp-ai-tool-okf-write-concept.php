<?php
	/**
	 * Tool: okf_write_concept — Create or update an OKF concept.
	 *
	 * @package WP_MCP_AI
	 * @since   2.1.0
	 * @since   2.5.0 — Emits OKF v0.2 `generated` provenance field instead of v0.1 `timestamp`.
	 * @since   1.1.62 — Creates missing bundles on first write (bundle-name validation, root
	 *                index generation) and resolves bundles via WP_MCP_AI_OKF_Bundle_Manager;
	 *                auto-generated bundles are protected from direct writes; adds the v0.2
	 *                provenance/trust families (resource, sources, usage_window, verified).
	 * @author  NV Digital Solutions
	 * @copyright Copyright (c) 2026 NV Digital Solutions
	 * @license  GPL-3.0-or-later
	 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Write Concept tool.
 */
class WP_MCP_AI_Tool_OKF_Write_Concept implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_write_concept';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Write Concept', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates or updates an OKF concept document in a bundle (OKF v0.2). Requires at minimum a type field in the frontmatter. If the named bundle does not exist yet, it is created on first write (bundle names must be lowercase letters, numbers, hyphens, and underscores). Supports the v0.2 trust/provenance families: status (draft/stable/deprecated), stale_after (ISO 8601), resource, sources (with author/usage_count/last_modified credibility signals), usage_window, and verified ({by, at} list). Use this to curate and maintain the OKF knowledge base programmatically.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'       => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name.', 'mcp-ai-wpoos' ),
				),
				'concept_id'   => array(
					'type'        => 'string',
					'description' => __( 'Concept ID — the file path without .md suffix (e.g. "policies/privacy-policy").', 'mcp-ai-wpoos' ),
				),
				'type'         => array(
					'type'        => 'string',
					'description' => __( 'Concept type (required by OKF v0.2, e.g. "Policy", "Procedure", "Skill").', 'mcp-ai-wpoos' ),
				),
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Human-readable title.', 'mcp-ai-wpoos' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'One-line summary of the concept.', 'mcp-ai-wpoos' ),
				),
				'body'         => array(
					'type'        => 'string',
					'description' => __( 'Markdown body content.', 'mcp-ai-wpoos' ),
				),
				'tags'         => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'List of tags for categorization.', 'mcp-ai-wpoos' ),
				),
				'status'       => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'stable', 'deprecated' ),
					'description' => __( 'Lifecycle status (OKF v0.2). Omit for stable, set to draft for work-in-progress, deprecated for retired concepts.', 'mcp-ai-wpoos' ),
				),
				'stale_after'  => array(
					'type'        => 'string',
					'description' => __( 'ISO 8601 date after which the concept is considered stale (OKF v0.2, e.g. "2027-06-30").', 'mcp-ai-wpoos' ),
				),
				'resource'     => array(
					'type'        => 'string',
					'description' => __( 'Canonical URI identifying the underlying asset the concept describes (OKF v0.2). Omit for abstract concepts.', 'mcp-ai-wpoos' ),
				),
				'sources'      => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => array( 'type' => 'string' ),
							'resource'      => array( 'type' => 'string' ),
							'title'         => array( 'type' => 'string' ),
							'author'        => array( 'type' => 'string' ),
							'usage_count'   => array( 'type' => 'integer' ),
							'last_modified' => array( 'type' => 'string' ),
						),
					),
					'description' => __( 'Provenance sources (OKF v0.2 §5.1): materials the concept derives from, with optional credibility signals.', 'mcp-ai-wpoos' ),
				),
				'usage_window' => array(
					'type'        => 'object',
					'properties'  => array(
						'from' => array( 'type' => 'string' ),
						'to'   => array( 'type' => 'string' ),
					),
					'description' => __( 'Date range framing the sources\' usage_count signals (OKF v0.2 §5.1).', 'mcp-ai-wpoos' ),
				),
				'verified'     => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'by' => array( 'type' => 'string' ),
							'at' => array( 'type' => 'string' ),
						),
					),
					'description' => __( 'Verification events (OKF v0.2 §5.2). Use the actor convention: "human:<id>" for people, "process:<id>" for automated checks. A human verifier raises the trust tier to human-reviewed.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'bundle', 'concept_id', 'type', 'body' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$bundle       = sanitize_text_field( $arguments['bundle'] );
		$concept_id   = sanitize_text_field( $arguments['concept_id'] );
		$type         = sanitize_text_field( $arguments['type'] );
		$title        = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description  = isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '';
		$body         = wp_kses_post( $arguments['body'] );
		$tags         = isset( $arguments['tags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['tags'] ) : array();
		$status       = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$stale_after  = isset( $arguments['stale_after'] ) ? sanitize_text_field( $arguments['stale_after'] ) : '';
		$resource     = isset( $arguments['resource'] ) ? sanitize_text_field( $arguments['resource'] ) : '';
		$sources      = $this->sanitize_sources( isset( $arguments['sources'] ) ? $arguments['sources'] : array() );
		$usage_window = $this->sanitize_usage_window( isset( $arguments['usage_window'] ) ? $arguments['usage_window'] : array() );
		$verified     = $this->sanitize_verified( isset( $arguments['verified'] ) ? $arguments['verified'] : array() );

		if ( empty( $bundle ) || empty( $concept_id ) || empty( $type ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle, concept_id, and type are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle, true );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		// Auto-generated bundles (skill-knowledge) are rebuilt by the plugin
		// on upgrades; refuse direct writes so curated edits cannot be lost.
		$writable = $manager->assert_bundle_writable( $bundle );
		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		// Remember whether this write creates a brand-new bundle (so we can
		// generate its root index.md afterwards) and whether the concept file
		// already exists (for the log.md action word).
		$bundle_created  = ! is_dir( $bundle_root );
		$concept_existed = is_file( wp_normalize_path( $bundle_root . '/' . ltrim( $concept_id, '/' ) . '.md' ) );

		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );

		// Ensure the bundle directory exists (creates it on first write).
		$ensured = $writer->ensure_bundle_root();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		// Build frontmatter.
		$frontmatter = array(
			'type' => $type,
		);
		if ( ! empty( $title ) ) {
			$frontmatter['title'] = $title;
		}
		if ( ! empty( $description ) ) {
			$frontmatter['description'] = $description;
		}
		if ( ! empty( $tags ) ) {
			$frontmatter['tags'] = $tags;
		}
		if ( ! empty( $status ) ) {
			$frontmatter['status'] = $status;
		}
		if ( ! empty( $stale_after ) ) {
			$frontmatter['stale_after'] = $stale_after;
		}
		if ( ! empty( $resource ) ) {
			$frontmatter['resource'] = $resource;
		}
		if ( ! empty( $sources ) ) {
			$frontmatter['sources'] = $sources;
		}
		if ( ! empty( $usage_window ) ) {
			$frontmatter['usage_window'] = $usage_window;
		}
		if ( ! empty( $verified ) ) {
			$frontmatter['verified'] = $verified;
		}

		// OKF v0.2 provenance: generated replaces v0.1 timestamp.
		$frontmatter['generated'] = array(
			'by' => 'okf_write_concept tool',
			'at' => gmdate( 'c' ),
		);

		$result = $writer->write_concept( $concept_id, $frontmatter, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A brand-new bundle has no index.md; generate one so okf_browse and
		// progressive disclosure work immediately. Best-effort: an index
		// failure must not fail the concept write itself.
		$index_regenerated = false;
		if ( $bundle_created ) {
			$index_result      = $writer->regenerate_index( '' );
			$index_regenerated = ! is_wp_error( $index_result );
		}

		// Maintain the bundle's log.md (OKF v0.2 §9). Best-effort: a log
		// failure must not fail the concept write itself.
		$log_dir = dirname( $concept_id );
		if ( '.' === $log_dir ) {
			$log_dir = '';
		}
		$manager->append_log(
			$bundle,
			$log_dir,
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" saved.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			$concept_existed ? 'Update' : 'Creation'
		);

		return $this->format_success_response(
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" saved successfully.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			array(
				'bundle'            => esc_html( $bundle ),
				'concept_id'        => esc_html( $concept_id ),
				'type'              => esc_html( $type ),
				'bundle_created'    => $bundle_created,
				'index_regenerated' => $index_regenerated,
			)
		);
	}

	/**
	 * Sanitize the sources argument (OKF v0.2 §5.1).
	 *
	 * Accepts a list of source maps; keeps only recognized keys and coerces
	 * usage_count to a non-negative integer.
	 *
	 * @param mixed $sources Raw sources argument.
	 * @return array<int, array<string, string|int>> Cleaned source maps.
	 */
	private function sanitize_sources( $sources ) {
		$clean = array();

		if ( ! is_array( $sources ) ) {
			return $clean;
		}

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$item = array();
			foreach ( array( 'id', 'resource', 'title', 'author', 'last_modified' ) as $key ) {
				if ( isset( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $source[ $key ] );
				}
			}

			if ( isset( $source['usage_count'] ) ) {
				$item['usage_count'] = absint( $source['usage_count'] );
			}

			if ( ! empty( $item ) ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize the usage_window argument (OKF v0.2 §5.1).
	 *
	 * @param mixed $usage_window Raw usage_window argument.
	 * @return array<string, string> Cleaned window map (from/to keys only).
	 */
	private function sanitize_usage_window( $usage_window ) {
		$clean = array();

		if ( ! is_array( $usage_window ) ) {
			return $clean;
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			if ( isset( $usage_window[ $key ] ) && '' !== trim( (string) $usage_window[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $usage_window[ $key ] );
			}
		}

		return $clean;
	}

	/**
	 * Sanitize the verified argument (OKF v0.2 §5.2).
	 *
	 * @param mixed $verified Raw verified argument (a list of {by, at} maps).
	 * @return array<int, array<string, string>> Cleaned verification events.
	 */
	private function sanitize_verified( $verified ) {
		$clean = array();

		if ( ! is_array( $verified ) ) {
			return $clean;
		}

		foreach ( $verified as $verification ) {
			if ( ! is_array( $verification ) ) {
				continue;
			}

			$item = array();
			foreach ( array( 'by', 'at' ) as $key ) {
				if ( isset( $verification[ $key ] ) && '' !== trim( (string) $verification[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $verification[ $key ] );
				}
			}

			if ( ! empty( $item ) ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}
}
