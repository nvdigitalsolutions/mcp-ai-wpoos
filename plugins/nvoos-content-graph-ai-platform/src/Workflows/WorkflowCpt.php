<?php
/**
 * Workflow CPT — `mcp_ai_workflow` post type and graph meta storage (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Workflow_CPT`:
 * byte-identical CPT args (hidden UI in-menu registration, `manage_options`
 * capabilities, title/editor/revisions/custom-fields supports), graph /
 * version / tags meta keys with the same sanitize + auth callbacks, graph
 * read/write helpers with malformed-JSON fallback, the portable
 * export/import JSON contract (`schema_version` 1.0), and semver bumping.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Standalone-only hook registration via `Plugin::registerWorkflowCpts()`
 *    (the base loader owns the same `init` registration at priority 12 in
 *    monolith installs; double registration would collide on the shared
 *    CPT slugs).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Workflow CPT manager.
 *
 * @since 2.1.0
 */
class WorkflowCpt {

	/** CPT slug. */
	const CPT = 'mcp_ai_workflow';

	/** Meta key for graph JSON. */
	const META_GRAPH = '_wp_mcp_ai_workflow_graph';

	/** Meta key for semver version. */
	const META_VERSION = '_wp_mcp_ai_workflow_version';

	/** Meta key for tags JSON array. */
	const META_TAGS = '_wp_mcp_ai_workflow_tags';

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public static function register_cpt(): void {
		$labels = array(
			'name'               => __( 'Workflows', 'nvoos-content-graph-ai-platform' ),
			'singular_name'      => __( 'Workflow', 'nvoos-content-graph-ai-platform' ),
			'add_new'            => __( 'Add New', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'       => __( 'Add New Workflow', 'nvoos-content-graph-ai-platform' ),
			'edit_item'          => __( 'Edit Workflow', 'nvoos-content-graph-ai-platform' ),
			'new_item'           => __( 'New Workflow', 'nvoos-content-graph-ai-platform' ),
			'view_item'          => __( 'View Workflow', 'nvoos-content-graph-ai-platform' ),
			'search_items'       => __( 'Search Workflows', 'nvoos-content-graph-ai-platform' ),
			'not_found'          => __( 'No workflows found', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash' => __( 'No workflows found in Trash', 'nvoos-content-graph-ai-platform' ),
			'menu_name'          => __( 'Workflows', 'nvoos-content-graph-ai-platform' ),
		);

		register_post_type(
			self::CPT,
			array(
				'labels'             => $labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'capability_type'    => 'post',
				'capabilities'       => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'delete_posts'       => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'map_meta_cap'       => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'has_archive'        => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/**
	 * Register post meta for the workflow CPT.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_post_meta(
			self::CPT,
			self::META_GRAPH,
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => 'JSON-encoded workflow graph (nodes + edges).',
				'sanitize_callback' => 'wp_slash',
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_post_meta(
			self::CPT,
			self::META_VERSION,
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => 'Semver version string (e.g. "1.0.0").',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_post_meta(
			self::CPT,
			self::META_TAGS,
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => 'JSON-encoded array of string tags.',
				'sanitize_callback' => 'wp_slash',
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Get the parsed graph for a workflow post.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return array Associative array with `nodes` and `edges` keys.
	 */
	public static function get_graph( $post_id ) {
		$post_id = absint( $post_id );
		$raw     = get_post_meta( $post_id, self::META_GRAPH, true );

		if ( empty( $raw ) ) {
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		$decoded = json_decode( wp_unslash( $raw ), true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		return array(
			'nodes' => isset( $decoded['nodes'] ) && is_array( $decoded['nodes'] ) ? $decoded['nodes'] : array(),
			'edges' => isset( $decoded['edges'] ) && is_array( $decoded['edges'] ) ? $decoded['edges'] : array(),
		);
	}

	/**
	 * Persist a workflow graph to post meta.
	 *
	 * @param int   $post_id Workflow post ID.
	 * @param array $graph   Associative array with `nodes` and `edges`.
	 * @return bool True on success.
	 */
	public static function save_graph( $post_id, $graph ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$post_id = absint( $post_id );

		$sanitized = array(
			'nodes' => isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array(),
			'edges' => isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? $graph['edges'] : array(),
		);

		$json   = wp_json_encode( $sanitized );
		$result = update_post_meta( $post_id, self::META_GRAPH, wp_slash( $json ) );

		return false !== $result;
	}

	/**
	 * Export a workflow post as a portable JSON payload.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return array Portable workflow array.
	 */
	public static function export_json( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return array();
		}

		$version_raw = get_post_meta( $post_id, self::META_VERSION, true );
		$tags_raw    = get_post_meta( $post_id, self::META_TAGS, true );
		$tags        = array();

		if ( ! empty( $tags_raw ) ) {
			$decoded = json_decode( wp_unslash( $tags_raw ), true );
			if ( is_array( $decoded ) ) {
				$tags = $decoded;
			}
		}

		return array(
			'schema_version' => '1.0',
			'name'           => $post->post_title,
			'description'    => $post->post_content,
			'version'        => $version_raw ? sanitize_text_field( $version_raw ) : '1.0.0',
			'tags'           => $tags,
			'graph'          => self::get_graph( $post_id ),
			'exported_at'    => gmdate( 'c' ),
		);
	}

	/**
	 * Import a portable workflow JSON payload into a post.
	 *
	 * @param array $data         Payload from export_json().
	 * @param int   $overwrite_id Existing post ID to overwrite; 0 to create new.
	 * @return int|\WP_Error New/updated post ID, or WP_Error on failure.
	 */
	public static function import_json( $data, $overwrite_id = 0 ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return new \WP_Error( 'invalid_data', __( 'Workflow data is invalid or missing a name.', 'nvoos-content-graph-ai-platform' ) );
		}

		$overwrite_id = absint( $overwrite_id );
		$title        = sanitize_text_field( $data['name'] );
		$description  = isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '';
		$version      = isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '1.0.0';
		$graph        = isset( $data['graph'] ) && is_array( $data['graph'] ) ? $data['graph'] : array(
			'nodes' => array(),
			'edges' => array(),
		);

		$tags = array();
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			foreach ( $data['tags'] as $tag ) {
				$tags[] = sanitize_text_field( $tag );
			}
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_type'    => self::CPT,
		);

		if ( $overwrite_id > 0 ) {
			$post_data['ID'] = $overwrite_id;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::save_graph( $post_id, $graph );
		update_post_meta( $post_id, self::META_VERSION, $version );
		update_post_meta( $post_id, self::META_TAGS, wp_slash( wp_json_encode( $tags ) ) );

		return $post_id;
	}

	/**
	 * Increment the semver version of a workflow.
	 *
	 * @param int    $post_id Workflow post ID.
	 * @param string $bump    Part to bump: 'patch' (default), 'minor', 'major'.
	 * @return string New version string.
	 */
	public static function bump_version( $post_id, $bump = 'patch' ) {
		$post_id = absint( $post_id );
		$current = get_post_meta( $post_id, self::META_VERSION, true );

		if ( empty( $current ) ) {
			$current = '1.0.0';
		}

		$parts = explode( '.', $current );

		$major = isset( $parts[0] ) ? absint( $parts[0] ) : 1;
		$minor = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		$patch = isset( $parts[2] ) ? absint( $parts[2] ) : 0;

		if ( 'major' === $bump ) {
			++$major;
			$minor = 0;
			$patch = 0;
		} elseif ( 'minor' === $bump ) {
			++$minor;
			$patch = 0;
		} else {
			++$patch;
		}

		$new_version = $major . '.' . $minor . '.' . $patch;
		update_post_meta( $post_id, self::META_VERSION, $new_version );

		return $new_version;
	}
}
