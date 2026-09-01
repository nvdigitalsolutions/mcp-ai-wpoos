<?php
/**
 * Blueprint Registry.
 *
 * CRUD for blueprint records. Blueprints are stored as `ai_platform_template`
 * posts (the TemplateCpt — plan decision 2: reuse rather than introduce a
 * new slug) with the versioned definition JSON in post meta.
 *
 * @package NvoosContentGraphAiPlatform\Blueprints
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Blueprints;

use NvoosContentGraphAiPlatform\PostTypes\TemplateCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles blueprint record persistence and retrieval.
 */
final class BlueprintRegistry {

	/**
	 * Meta key holding the blueprint definition JSON.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const META_DEFINITION = '_nvoos_platform_blueprint_definition';

	/**
	 * Meta key holding the blueprint schema version.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const META_VERSION = '_nvoos_platform_blueprint_version';

	/**
	 * Meta key holding the blueprint kind (agent, workflow, prompt_pack).
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const META_KIND = '_nvoos_platform_blueprint_kind';

	/**
	 * Current blueprint schema version this registry reads and writes.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Create a blueprint record.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data Blueprint data with 'title', 'slug' (optional),
	 *                    'description' (optional), and 'definition' keys.
	 * @return int|\WP_Error Blueprint post ID on success, WP_Error on failure.
	 */
	public function create( array $data ) {
		$post_data = array(
			'post_type'   => TemplateCpt::POST_TYPE,
			'post_status' => 'publish',
		);

		if ( ! isset( $data['title'] ) || '' === trim( (string) $data['title'] ) ) {
			return new \WP_Error( 'blueprint_missing_title', __( 'A blueprint title is required.', 'nvoos-content-graph-ai-platform' ) );
		}
		$post_data['post_title'] = sanitize_text_field( $data['title'] );

		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['description'] );
		}

		if ( ! isset( $data['definition'] ) || ! is_array( $data['definition'] ) ) {
			return new \WP_Error( 'blueprint_missing_definition', __( 'A blueprint definition is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_DEFINITION, wp_json_encode( $data['definition'] ) );
		update_post_meta( $post_id, self::META_VERSION, isset( $data['definition']['blueprint_version'] ) ? sanitize_text_field( (string) $data['definition']['blueprint_version'] ) : self::SCHEMA_VERSION );
		update_post_meta( $post_id, self::META_KIND, isset( $data['definition']['kind'] ) ? sanitize_key( $data['definition']['kind'] ) : 'agent' );

		return $post_id;
	}

	/**
	 * Get a blueprint record by ID, slug, or WP_Post.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string|\WP_Post $blueprint Blueprint ID, slug, or post object.
	 * @return array|null Blueprint data array or null if not found.
	 */
	public function get( $blueprint ): ?array {
		$post = null;

		if ( $blueprint instanceof \WP_Post ) {
			$post = $blueprint;
		} elseif ( is_numeric( $blueprint ) ) {
			$post = get_post( absint( $blueprint ) );
		} else {
			$found = get_posts(
				array(
					'post_type'      => TemplateCpt::POST_TYPE,
					'name'           => sanitize_title( (string) $blueprint ),
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $found ) ) {
				$post = get_post( $found[0] );
			}
		}

		if ( ! $post || TemplateCpt::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$raw_definition = get_post_meta( $post->ID, self::META_DEFINITION, true );
		$definition     = is_string( $raw_definition ) ? json_decode( $raw_definition, true ) : null;

		return array(
			'id'          => $post->ID,
			'slug'        => $post->post_name,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'version'     => get_post_meta( $post->ID, self::META_VERSION, true ),
			'kind'        => get_post_meta( $post->ID, self::META_KIND, true ),
			'definition'  => is_array( $definition ) ? $definition : array(),
		);
	}

	/**
	 * List blueprint records.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Optional WP_Query overrides (kind filter via meta_query).
	 * @return array<int, array> Array of blueprint data arrays.
	 */
	public function all( array $args = array() ): array {
		$defaults = array(
			'post_type'      => TemplateCpt::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$query = new \WP_Query( wp_parse_args( $args, $defaults ) );

		$blueprints = array();
		foreach ( $query->posts as $post ) {
			$record = $this->get( $post );
			if ( null !== $record ) {
				$blueprints[] = $record;
			}
		}

		return $blueprints;
	}

	/**
	 * Update a blueprint record.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $id   Blueprint post ID.
	 * @param array $data Fields to update (title, slug, description, definition).
	 * @return int|\WP_Error Blueprint post ID on success, WP_Error on failure.
	 */
	public function update( int $id, array $data ) {
		if ( ! $this->is_blueprint( $id ) ) {
			return new \WP_Error( 'blueprint_not_found', __( 'Blueprint not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		$post_data = array( 'ID' => $id );

		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}
		if ( isset( $data['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['description'] );
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $data['definition'] ) && is_array( $data['definition'] ) ) {
			update_post_meta( $id, self::META_DEFINITION, wp_json_encode( $data['definition'] ) );
			if ( isset( $data['definition']['blueprint_version'] ) ) {
				update_post_meta( $id, self::META_VERSION, sanitize_text_field( (string) $data['definition']['blueprint_version'] ) );
			}
			if ( isset( $data['definition']['kind'] ) ) {
				update_post_meta( $id, self::META_KIND, sanitize_key( $data['definition']['kind'] ) );
			}
		}

		return $result;
	}

	/**
	 * Delete a blueprint record.
	 *
	 * @since 2.0.0
	 *
	 * @param int  $id    Blueprint post ID.
	 * @param bool $force Bypass trash (default true).
	 * @return bool True on success.
	 */
	public function delete( int $id, bool $force = true ): bool {
		if ( ! $this->is_blueprint( $id ) ) {
			return false;
		}

		$post = wp_delete_post( $id, $force );
		return false !== $post;
	}

	/**
	 * Whether a post ID refers to a blueprint record.
	 *
	 * @since 2.0.0
	 *
	 * @param int $id Post ID.
	 * @return bool True when the post is a blueprint.
	 */
	private function is_blueprint( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && TemplateCpt::POST_TYPE === $post->post_type;
	}
}
