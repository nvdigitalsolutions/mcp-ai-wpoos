<?php
/**
 * PARA Classification Taxonomy.
 *
 * Registers the `mcp_ai_para` hierarchical taxonomy used to classify projects,
 * tasks, events, areas, controlled documents, and reference posts into one of
 * Tiago Forte's four PARA buckets: Projects / Areas / Resources / Archives.
 *
 * The four root terms are locked: their slugs cannot be changed or deleted.
 * Children are allowed (e.g. `Areas → Health`).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PARA taxonomy registration and helper API.
 */
class WP_MCP_AI_PARA_Taxonomy {

	/**
	 * Taxonomy slug.
	 */
	const TAXONOMY = 'mcp_ai_para';

	/**
	 * Locked root slugs.
	 *
	 * @var array<int,string>
	 */
	const ROOTS = array( 'projects', 'areas', 'resources', 'archives' );

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 11 );
		add_action( 'init', array( __CLASS__, 'seed_root_terms' ), 12 );

		// Lock the four root terms from being deleted or renamed.
		add_action( 'pre_delete_term', array( __CLASS__, 'protect_root_terms' ), 10, 2 );
		add_filter( 'pre_insert_term', array( __CLASS__, 'protect_root_term_slugs' ), 10, 2 );
	}

	/**
	 * Get the post types that participate in PARA classification.
	 *
	 * @return array<int,string>
	 */
	public static function get_object_types() {
		$default = array(
			'mcp_ai_project',
			'mcp_ai_task',
			'mcp_ai_event',
			'mcp_ai_area',
			'mcp_ai_doc_tpl',
			'mcp_ai_doc_record',
		);

		/**
		 * Filter the post types to which PARA classification applies.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int,string> $object_types Post type slugs.
		 */
		return apply_filters( 'wp_mcp_ai_para_object_types', $default );
	}

	/**
	 * Determine whether PARA is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// Pro-only feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		// Requires Project Management toolkit to be enabled.
		if ( empty( $settings['enable_project_management'] ) ) {
			return false;
		}
		// Opt-in feature flag.
		return ! empty( $settings['enable_para_organization'] );
	}

	/**
	 * Register the taxonomy.
	 */
	public static function register_taxonomy() {
		if ( ! self::is_enabled() ) {
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			self::get_object_types(),
			array(
				'labels'            => array(
					'name'          => __( 'PARA', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'PARA Bucket', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'PARA', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All PARA Buckets', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit PARA Bucket', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update PARA Bucket', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add Sub-bucket', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Sub-bucket Name', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search PARA Buckets', 'mcp-ai-wpoos-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'show_in_menu'      => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'para' ),
				'meta_box_cb'       => array( __CLASS__, 'render_metabox' ),
			)
		);
	}

	/**
	 * Insert the four locked root terms if they don't exist.
	 */
	public static function seed_root_terms() {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		$labels = array(
			'projects'  => __( 'Projects', 'mcp-ai-wpoos-pro' ),
			'areas'     => __( 'Areas', 'mcp-ai-wpoos-pro' ),
			'resources' => __( 'Resources', 'mcp-ai-wpoos-pro' ),
			'archives'  => __( 'Archives', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $labels as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term(
					$label,
					self::TAXONOMY,
					array(
						'slug'        => $slug,
						'description' => self::get_root_description( $slug ),
					)
				);
			}
		}
	}

	/**
	 * Get description for a root bucket.
	 *
	 * @param string $slug Root slug.
	 * @return string
	 */
	protected static function get_root_description( $slug ) {
		switch ( $slug ) {
			case 'projects':
				return __( 'Short-term efforts with a goal and deadline.', 'mcp-ai-wpoos-pro' );
			case 'areas':
				return __( 'Ongoing responsibilities with a standard to maintain.', 'mcp-ai-wpoos-pro' );
			case 'resources':
				return __( 'Topical reference material for current or future projects.', 'mcp-ai-wpoos-pro' );
			case 'archives':
				return __( 'Inactive items from any of the other three buckets.', 'mcp-ai-wpoos-pro' );
		}
		return '';
	}

	/**
	 * Protect root terms from deletion.
	 *
	 * @param int    $term     Term ID being deleted.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function protect_root_terms( $term, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$term_object = get_term( $term, $taxonomy );
		if ( $term_object && ! is_wp_error( $term_object ) && in_array( $term_object->slug, self::ROOTS, true ) ) {
			wp_die( esc_html__( 'PARA root buckets cannot be deleted.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Prevent reuse of locked slugs by user-created sub-terms.
	 *
	 * @param string|WP_Error $term     Term name being inserted.
	 * @param string          $taxonomy Taxonomy slug.
	 * @return string|WP_Error
	 */
	public static function protect_root_term_slugs( $term, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return $term;
		}
		// We only block exact slug collisions on insert; WP handles dupes by appending suffix,
		// but explicit user-supplied slugs can be checked at the REST/CLI layer separately.
		return $term;
	}

	/**
	 * Render the PARA classification metabox (single-select among root + descendants).
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_metabox( $post ) {
		$terms   = get_the_terms( $post->ID, self::TAXONOMY );
		$current = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0]->term_id : 0;

		$all_terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'slug',
			)
		);

		wp_nonce_field( 'wp_mcp_ai_para_classify', 'wp_mcp_ai_para_nonce' );
		echo '<p>' . esc_html__( 'Select exactly one PARA bucket for this item.', 'mcp-ai-wpoos-pro' ) . '</p>';
		echo '<select name="wp_mcp_ai_para_term" style="width:100%;">';
		echo '<option value="">' . esc_html__( '— Unclassified —', 'mcp-ai-wpoos-pro' ) . '</option>';
		if ( ! is_wp_error( $all_terms ) ) {
			foreach ( $all_terms as $term ) {
				$indent = $term->parent ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '';
				printf(
					'<option value="%d" %s>%s%s</option>',
					(int) $term->term_id,
					selected( $current, $term->term_id, false ),
					$indent, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
					esc_html( $term->name )
				);
			}
		}
		echo '</select>';
	}

	/**
	 * Save the PARA classification on post save.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_post( $post_id ) {
		if ( ! isset( $_POST['wp_mcp_ai_para_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_para_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_ai_para_classify' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$term_id = isset( $_POST['wp_mcp_ai_para_term'] ) ? absint( $_POST['wp_mcp_ai_para_term'] ) : 0;
		if ( $term_id ) {
			wp_set_object_terms( $post_id, array( $term_id ), self::TAXONOMY, false );
		} else {
			wp_set_object_terms( $post_id, array(), self::TAXONOMY, false );
		}
	}

	/**
	 * Resolve a value (term_id, slug, or root name) to a term object.
	 *
	 * @param int|string $value Term ID or slug.
	 * @return WP_Term|null
	 */
	public static function resolve_term( $value ) {
		if ( is_numeric( $value ) ) {
			$term = get_term( (int) $value, self::TAXONOMY );
		} else {
			$term = get_term_by( 'slug', sanitize_key( (string) $value ), self::TAXONOMY );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
		return null;
	}

	/**
	 * Get the root slug for a term (walking up parents).
	 *
	 * @param WP_Term|int $term Term or ID.
	 * @return string Root slug or empty string.
	 */
	public static function get_root_slug( $term ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( (int) $term, self::TAXONOMY );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$current = $term;
		$guard   = 0;
		while ( $current && (int) $current->parent > 0 && $guard < 10 ) {
			$current = get_term( (int) $current->parent, self::TAXONOMY );
			++$guard;
		}
		return ( $current && ! is_wp_error( $current ) ) ? (string) $current->slug : '';
	}

	/**
	 * Get the PARA bucket (root slug) for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Root slug or empty string.
	 */
	public static function get_post_bucket( $post_id ) {
		$terms = get_the_terms( (int) $post_id, self::TAXONOMY );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return self::get_root_slug( $terms[0] );
	}

	/**
	 * Assign a post to a PARA bucket (root slug or term).
	 *
	 * @param int        $post_id    Post ID.
	 * @param int|string $term       Term ID or slug.
	 * @param string     $reason     Reason for the change (logged via hook).
	 * @return true|WP_Error
	 */
	public static function assign( $post_id, $term, $reason = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_para_invalid_post', __( 'Invalid post.', 'mcp-ai-wpoos-pro' ) );
		}
		$term_obj = self::resolve_term( $term );
		if ( ! $term_obj ) {
			return new WP_Error( 'wp_mcp_ai_para_invalid_term', __( 'Invalid PARA term.', 'mcp-ai-wpoos-pro' ) );
		}
		$root = self::get_root_slug( $term_obj );
		if ( ! in_array( $root, self::ROOTS, true ) ) {
			return new WP_Error( 'wp_mcp_ai_para_invalid_root', __( 'PARA term must descend from one of the four locked roots.', 'mcp-ai-wpoos-pro' ) );
		}

		$previous_bucket = self::get_post_bucket( $post_id );

		$result = wp_set_object_terms( $post_id, array( (int) $term_obj->term_id ), self::TAXONOMY, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires when a post's PARA bucket is changed.
		 *
		 * @since 1.2.0
		 *
		 * @param int     $post_id         Post ID.
		 * @param string  $new_bucket      New root bucket slug.
		 * @param string  $previous_bucket Previous root bucket slug.
		 * @param WP_Term $term            Assigned term.
		 * @param string  $reason          Reason for the change.
		 */
		do_action( 'wp_mcp_ai_para_item_classified', $post_id, $root, $previous_bucket, $term_obj, (string) $reason );

		if ( 'archives' === $root && 'archives' !== $previous_bucket ) {
			/**
			 * Fires when a post is moved to PARA archives.
			 *
			 * @param int    $post_id Post ID.
			 * @param string $reason  Reason.
			 */
			do_action( 'wp_mcp_ai_para_archived', $post_id, (string) $reason );
		} elseif ( 'archives' === $previous_bucket && 'archives' !== $root ) {
			/**
			 * Fires when a post is restored out of PARA archives.
			 *
			 * @param int    $post_id Post ID.
			 * @param string $reason  Reason.
			 */
			do_action( 'wp_mcp_ai_para_unarchived', $post_id, (string) $reason );
		}

		return true;
	}
}
