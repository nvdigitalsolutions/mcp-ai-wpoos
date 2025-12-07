<?php
/**
 * Profession Service.
 *
 * Business logic layer for profession operations.
 * Separates business logic from data access (repository) and presentation (CPT).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles profession business logic and transformations.
 */
class WP_MCP_AI_Profession_Service {
	/**
	 * Profession repository instance.
	 *
	 * @var WP_MCP_AI_Profession_Repository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Profession_Repository $repository Profession repository.
	 */
	public function __construct( WP_MCP_AI_Profession_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Get all active professions.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of profession data.
	 */
	public function get_all_professions( $args = array() ) {
		$professions = $this->repository->find_all( $args );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_display( $profession_post );
		}

		return $result;
	}

	/**
	 * Get professions by category.
	 *
	 * @param string $category Category slug.
	 * @return array Array of profession data.
	 */
	public function get_professions_by_category( $category ) {
		$professions = $this->repository->find_by_category( $category );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_display( $profession_post );
		}

		return $result;
	}

	/**
	 * Get profession by slug or ID.
	 *
	 * @param string|int $profession Profession slug or ID.
	 * @return array|null Profession data or null if not found.
	 */
	public function get_profession( $profession ) {
		$profession_post = $this->repository->find_one( $profession );

		if ( ! $profession_post ) {
			return null;
		}

		return $this->transform_profession_for_assistant( $profession_post );
	}

	/**
	 * Get multiple professions by slugs or IDs.
	 *
	 * @param array $profession_ids Array of profession slugs or IDs.
	 * @return array Array of profession data indexed by slug.
	 */
	public function get_professions( array $profession_ids ) {
		$professions = $this->repository->find_many( $profession_ids );
		$result      = array();

		foreach ( $professions as $profession_post ) {
			$result[ $profession_post->post_name ] = $this->transform_profession_for_assistant( $profession_post );
		}

		return $result;
	}

	/**
	 * Transform profession post for display (dropdown, list).
	 *
	 * @param WP_Post $profession_post Profession post object.
	 * @return string Profession display name.
	 */
	protected function transform_profession_for_display( $profession_post ) {
		return $profession_post->post_title;
	}

	/**
	 * Transform profession post for assistant creation.
	 *
	 * @param WP_Post $profession_post Profession post object.
	 * @return array Profession data for assistant.
	 */
	protected function transform_profession_for_assistant( $profession_post ) {
		return array(
			'id'               => $profession_post->ID,
			'slug'             => $profession_post->post_name,
			'name'             => $profession_post->post_title,
			'description'      => $profession_post->post_content,
			'category'         => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true ),
			'role_description' => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, true ),
			'expertise'        => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true ),
			'warnings'         => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_WARNINGS, true ),
			'knowledge_base'   => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true ),
			'default_tools'    => get_post_meta( $profession_post->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true ),
		);
	}

	/**
	 * Get professions formatted for dropdown/select.
	 *
	 * @param array $args Optional query arguments.
	 * @return array Array of slug => label pairs.
	 */
	public function get_professions_for_dropdown( $args = array() ) {
		return $this->get_all_professions( $args );
	}

	/**
	 * Merge profession data for multiple professions.
	 * Used when creating assistants with multiple professions.
	 *
	 * @param array $profession_slugs Array of profession slugs.
	 * @return array Merged profession data.
	 */
	public function merge_profession_data( array $profession_slugs ) {
		$professions = $this->get_professions( $profession_slugs );

		$merged = array(
			'names'     => array(),
			'roles'     => array(),
			'expertise' => array(),
			'warnings'  => array(),
			'knowledge' => array(),
			'tools'     => array(),
		);

		foreach ( $professions as $profession ) {
			$merged['names'][] = $profession['name'];

			if ( ! empty( $profession['role_description'] ) ) {
				$merged['roles'][] = $profession['role_description'];
			}

			if ( ! empty( $profession['expertise'] ) && is_array( $profession['expertise'] ) ) {
				$merged['expertise'] = array_merge( $merged['expertise'], $profession['expertise'] );
			}

			if ( ! empty( $profession['warnings'] ) && is_array( $profession['warnings'] ) ) {
				$merged['warnings'] = array_merge( $merged['warnings'], $profession['warnings'] );
			}

			if ( ! empty( $profession['knowledge_base'] ) ) {
				$merged['knowledge'][] = $profession['knowledge_base'];
			}

			if ( ! empty( $profession['default_tools'] ) && is_array( $profession['default_tools'] ) ) {
				$merged['tools'] = array_merge( $merged['tools'], $profession['default_tools'] );
			}
		}

		// Deduplicate arrays.
		$merged['expertise'] = array_values( array_unique( $merged['expertise'] ) );
		$merged['warnings']  = array_values( array_unique( $merged['warnings'] ) );
		$merged['tools']     = array_values( array_unique( $merged['tools'] ) );

		return $merged;
	}

	/**
	 * Check if profession exists.
	 *
	 * @param string|int $profession Profession slug or ID.
	 * @return bool True if exists, false otherwise.
	 */
	public function profession_exists( $profession ) {
		return null !== $this->repository->find_one( $profession );
	}

	/**
	 * Get profession count by category.
	 *
	 * @return array Category => count pairs.
	 */
	public function get_category_counts() {
		return $this->repository->get_category_counts();
	}
}
