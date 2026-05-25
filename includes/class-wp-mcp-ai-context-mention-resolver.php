<?php
/**
 * Context Mention Resolver — @-mention autocomplete and context injection.
 *
 * Resolves @-mention references (e.g., @post:hello-world, @tool:web_search)
 * to their full context payloads for injection into the LLM system prompt.
 * Mirrors Zed's @-mention context system.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Context_Mention_Resolver
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Context_Mention_Resolver {

	/**
	 * Registered mention types and their resolvers.
	 *
	 * Each type has:
	 * - label: Human-readable group name
	 * - resolver: Callable that takes (query, limit) and returns array of {id, title, type, excerpt}
	 * - context_provider: Callable that takes (type, id) and returns context string for LLM
	 *
	 * @since 1.7.0
	 * @var array
	 */
	private $types = array();

	/**
	 * Constructor — registers default mention types.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->register_default_types();
	}

	// ──────────────────────────────────────────────
	// Mention Type Registration
	// ──────────────────────────────────────────────

	/**
	 * Register default WordPress entity mention types.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function register_default_types() {
		// Posts and custom post types.
		$this->register_type(
			'post',
			array(
				'label'    => __( 'Posts', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_posts' ),
				'provider' => array( $this, 'get_post_context' ),
			)
		);

		// Tools.
		$this->register_type(
			'tool',
			array(
				'label'    => __( 'Tools', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_tools' ),
				'provider' => array( $this, 'get_tool_context' ),
			)
		);

		// Skills.
		$this->register_type(
			'skill',
			array(
				'label'    => __( 'Skills', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_skills' ),
				'provider' => array( $this, 'get_skill_context' ),
			)
		);

		// Threads.
		$this->register_type(
			'thread',
			array(
				'label'    => __( 'Threads', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_threads' ),
				'provider' => array( $this, 'get_thread_context' ),
			)
		);

		// Media files.
		$this->register_type(
			'file',
			array(
				'label'    => __( 'Files', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_files' ),
				'provider' => array( $this, 'get_file_context' ),
			)
		);

		// Users.
		$this->register_type(
			'user',
			array(
				'label'    => __( 'Users', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_users' ),
				'provider' => array( $this, 'get_user_context' ),
			)
		);

		// Taxonomy terms.
		$this->register_type(
			'term',
			array(
				'label'    => __( 'Terms', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_terms' ),
				'provider' => array( $this, 'get_term_context' ),
			)
		);

		// Plugin/WordPress settings.
		$this->register_type(
			'setting',
			array(
				'label'    => __( 'Settings', 'mcp-ai-wpoos' ),
				'resolver' => array( $this, 'resolve_settings' ),
				'provider' => array( $this, 'get_setting_context' ),
			)
		);

		/**
		 * Fires after default mention types are registered.
		 *
		 * Use this to register custom mention types from addons.
		 *
		 * @since 1.7.0
		 *
		 * @param WP_MCP_AI_Context_Mention_Resolver $resolver The resolver instance.
		 */
		do_action( 'wp_mcp_ai_context_mention_types_registered', $this );
	}

	/**
	 * Register a custom mention type.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type   Type slug (used as prefix: @type:value).
	 * @param array  $config { label, resolver, provider }.
	 * @return void
	 */
	public function register_type( $type, $config ) {
		$type = sanitize_key( $type );

		$this->types[ $type ] = array(
			'label'    => isset( $config['label'] ) ? sanitize_text_field( $config['label'] ) : ucfirst( $type ),
			'resolver' => isset( $config['resolver'] ) ? $config['resolver'] : null,
			'provider' => isset( $config['provider'] ) ? $config['provider'] : null,
		);
	}

	/**
	 * Get all registered mention types.
	 *
	 * @since 1.7.0
	 * @return array
	 */
	public function get_registered_types() {
		return array_keys( $this->types );
	}

	// ──────────────────────────────────────────────
	// Autocomplete / Suggest
	// ──────────────────────────────────────────────

	/**
	 * Search for mention suggestions across types.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query      Search query.
	 * @param array  $type_filter Optional array of type slugs to limit search.
	 * @param int    $limit      Maximum results per type.
	 * @return array             Grouped results by type: { type: { label, items: [...] } }
	 */
	public function suggest( $query, $type_filter = array(), $limit = 10 ) {
		$query   = sanitize_text_field( $query );
		$limit   = min( 50, max( 1, absint( $limit ) ) );
		$results = array();

		// If query contains a prefix (e.g., "post:hello"), extract it.
		$prefix = '';
		$search = $query;

		if ( preg_match( '/^([a-z_]+):(.+)$/', $query, $matches ) ) {
			$prefix = sanitize_key( $matches[1] );
			$search = sanitize_text_field( $matches[2] );
		}

		$types_to_search = empty( $type_filter ) ? array_keys( $this->types ) : array_intersect( $type_filter, array_keys( $this->types ) );

		// If prefix is specified and valid, only search that type.
		if ( ! empty( $prefix ) && isset( $this->types[ $prefix ] ) ) {
			$types_to_search = array( $prefix );
		}

		foreach ( $types_to_search as $type ) {
			$config = $this->types[ $type ];

			if ( ! is_callable( $config['resolver'] ) ) {
				continue;
			}

			$items = call_user_func( $config['resolver'], $search, $limit );

			if ( ! empty( $items ) ) {
				$results[ $type ] = array(
					'label' => $config['label'],
					'items' => $items,
				);
			}
		}

		return $results;
	}

	/**
	 * Resolve a mention to its full context payload.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param mixed  $id   Entity identifier.
	 * @return string|null Context string for LLM injection, or null if not found.
	 */
	public function resolve_context( $type, $id ) {
		$type = sanitize_key( $type );

		if ( ! isset( $this->types[ $type ] ) ) {
			return null;
		}

		$provider = $this->types[ $type ]['provider'];

		if ( ! is_callable( $provider ) ) {
			return null;
		}

		return call_user_func( $provider, $type, $id );
	}

	// ──────────────────────────────────────────────
	// Default Resolvers
	// ──────────────────────────────────────────────

	/**
	 * Resolve posts by title/content search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_posts( $query, $limit ) {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			's'              => $query,
			'orderby'        => 'relevance',
		);

		$posts = get_posts( $args );

		return array_map(
			function ( $post ) {
				return array(
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'type'    => $post->post_type,
					'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 ),
				);
			},
			$posts
		);
	}

	/**
	 * Get post context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param int    $id   Post ID.
	 * @return string
	 */
	private function get_post_context( $type, $id ) {
		$post = get_post( absint( $id ) );

		if ( ! $post ) {
			return '';
		}

		return sprintf(
			"--- BEGIN POST CONTEXT ---\nTitle: %s\nType: %s\nStatus: %s\nDate: %s\nAuthor: %s\n\nContent:\n%s\n--- END POST CONTEXT ---",
			esc_html( $post->post_title ),
			esc_html( $post->post_type ),
			esc_html( $post->post_status ),
			esc_html( $post->post_date ),
			esc_html( get_the_author_meta( 'display_name', $post->post_author ) ),
			wp_strip_all_tags( $post->post_content )
		);
	}

	/**
	 * Resolve tools by name/slug search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_tools( $query, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_tools();
		$results   = array();

		foreach ( $all_tools as $slug => $tool ) {
			$name = isset( $tool['name'] ) ? $tool['name'] : $slug;
			$desc = isset( $tool['description'] ) ? $tool['description'] : '';

			if (
				empty( $query ) ||
				false !== stripos( $slug, $query ) ||
				false !== stripos( $name, $query ) ||
				false !== stripos( $desc, $query )
			) {
				$results[] = array(
					'id'      => $slug,
					'title'   => $name,
					'type'    => 'tool',
					'excerpt' => wp_trim_words( $desc, 20 ),
				);

				if ( count( $results ) >= $limit ) {
					break;
				}
			}
		}

		return $results;
	}

	/**
	 * Get tool context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param string $slug Tool slug.
	 * @return string
	 */
	private function get_tool_context( $type, $slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return '';
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( sanitize_key( $slug ) );

		if ( ! $tool ) {
			return '';
		}

		$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();

		return sprintf(
			"--- BEGIN TOOL CONTEXT ---\nTool: %s\nDescription: %s\nParameters: %s\n--- END TOOL CONTEXT ---",
			esc_html( $slug ),
			esc_html( isset( $definition['description'] ) ? $definition['description'] : '' ),
			isset( $definition['parameters'] ) ? esc_html( wp_json_encode( $definition['parameters'] ) ) : 'none'
		);
	}

	/**
	 * Resolve skills by name/description search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_skills( $query, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			return array();
		}

		$registry   = WP_MCP_AI_Skill_Registry::get_instance();
		$all_skills = $registry->get_all_skills();
		$results    = array();

		foreach ( $all_skills as $slug => $skill ) {
			$name = isset( $skill['name'] ) ? $skill['name'] : $slug;
			$desc = isset( $skill['description'] ) ? $skill['description'] : '';

			if (
				empty( $query ) ||
				false !== stripos( $slug, $query ) ||
				false !== stripos( $name, $query ) ||
				false !== stripos( $desc, $query )
			) {
				$results[] = array(
					'id'      => $slug,
					'title'   => $name,
					'type'    => 'skill',
					'excerpt' => wp_trim_words( $desc, 20 ),
				);

				if ( count( $results ) >= $limit ) {
					break;
				}
			}
		}

		return $results;
	}

	/**
	 * Get skill context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param string $slug Skill slug.
	 * @return string
	 */
	private function get_skill_context( $type, $slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			return '';
		}

		$registry = WP_MCP_AI_Skill_Registry::get_instance();
		$skill    = $registry->get_skill( sanitize_key( $slug ) );

		if ( ! $skill ) {
			return '';
		}

		return sprintf(
			"--- BEGIN SKILL CONTEXT ---\nSkill: %s\nDescription: %s\n--- END SKILL CONTEXT ---",
			esc_html( $slug ),
			esc_html( isset( $skill['description'] ) ? $skill['description'] : '' )
		);
	}

	/**
	 * Resolve threads by title search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_threads( $query, $limit ) {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'mcp_ai_threads';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, title, status FROM `{$table}` WHERE user_id = %d AND title LIKE %s AND status = 'active' ORDER BY updated_at DESC LIMIT %d",
				$user_id,
				'%' . $wpdb->esc_like( $query ) . '%',
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'id'      => (int) $row['id'],
					'title'   => $row['title'],
					'type'    => 'thread',
					'excerpt' => '',
				);
			},
			$rows
		);
	}

	/**
	 * Get thread context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param int    $id   Thread ID.
	 * @return string
	 */
	private function get_thread_context( $type, $id ) {
		if ( ! class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			return '';
		}

		$manager = new WP_MCP_AI_Thread_Manager();
		$context = $manager->get_thread_context( absint( $id ), 50 );

		if ( empty( $context ) ) {
			return '';
		}

		$output = "--- BEGIN THREAD CONTEXT ---\n";
		foreach ( $context as $msg ) {
			$output .= sprintf( "[%s]: %s\n", esc_html( $msg['role'] ), esc_html( $msg['content'] ) );
		}
		$output .= '--- END THREAD CONTEXT ---';

		return $output;
	}

	/**
	 * Resolve media files by title/filename search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_files( $query, $limit ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			's'              => $query,
			'orderby'        => 'relevance',
		);

		$attachments = get_posts( $args );

		return array_map(
			function ( $attachment ) {
				return array(
					'id'      => $attachment->ID,
					'title'   => $attachment->post_title ? $attachment->post_title : basename( get_attached_file( $attachment->ID ) ),
					'type'    => $attachment->post_mime_type,
					'excerpt' => $attachment->post_excerpt,
				);
			},
			$attachments
		);
	}

	/**
	 * Get file context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param int    $id   Attachment ID.
	 * @return string
	 */
	private function get_file_context( $type, $id ) {
		$attachment = get_post( absint( $id ) );

		if ( ! $attachment ) {
			return '';
		}

		$file_url = wp_get_attachment_url( $attachment->ID );

		return sprintf(
			"--- BEGIN FILE CONTEXT ---\nFile: %s\nType: %s\nURL: %s\n--- END FILE CONTEXT ---",
			esc_html( $attachment->post_title ),
			esc_html( $attachment->post_mime_type ),
			esc_url( $file_url )
		);
	}

	/**
	 * Resolve users by display name / login search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_users( $query, $limit ) {
		$args = array(
			'search'  => '*' . $query . '*',
			'number'  => $limit,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		);

		$users = get_users( $args );

		return array_map(
			function ( $user ) {
				return array(
					'id'      => $user->ID,
					'title'   => $user->display_name,
					'type'    => 'user',
					'excerpt' => $user->user_email,
				);
			},
			$users
		);
	}

	/**
	 * Get user context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param int    $id   User ID.
	 * @return string
	 */
	private function get_user_context( $type, $id ) {
		$user = get_userdata( absint( $id ) );

		if ( ! $user ) {
			return '';
		}

		return sprintf(
			"--- BEGIN USER CONTEXT ---\nUser: %s\nEmail: %s\nRole: %s\n--- END USER CONTEXT ---",
			esc_html( $user->display_name ),
			esc_html( $user->user_email ),
			esc_html( implode( ', ', $user->roles ) )
		);
	}

	/**
	 * Resolve taxonomy terms by name search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_terms( $query, $limit ) {
		$args = array(
			'taxonomy'   => get_taxonomies( array( 'public' => true ), 'names' ),
			'search'     => $query,
			'number'     => $limit,
			'hide_empty' => false,
		);

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_map(
			function ( $term ) {
				return array(
					'id'      => sprintf( '%s:%d', $term->taxonomy, $term->term_id ),
					'title'   => $term->name,
					'type'    => 'term:' . $term->taxonomy,
					'excerpt' => $term->description,
				);
			},
			$terms
		);
	}

	/**
	 * Get term context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type Mention type.
	 * @param string $id   Term identifier (taxonomy:term_id).
	 * @return string
	 */
	private function get_term_context( $type, $id ) {
		$parts = explode( ':', $id, 2 );

		if ( count( $parts ) < 2 ) {
			return '';
		}

		$taxonomy = sanitize_key( $parts[0] );
		$term_id  = absint( $parts[1] );

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		return sprintf(
			"--- BEGIN TERM CONTEXT ---\nTerm: %s\nTaxonomy: %s\nDescription: %s\n--- END TERM CONTEXT ---",
			esc_html( $term->name ),
			esc_html( $term->taxonomy ),
			esc_html( $term->description )
		);
	}

	/**
	 * Resolve WordPress settings by option key search.
	 *
	 * @since 1.7.0
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array
	 */
	private function resolve_settings( $query, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name ASC LIMIT %d",
				'%' . $wpdb->esc_like( $query ) . '%',
				'%transient%',
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'id'      => $row['option_name'],
					'title'   => $row['option_name'],
					'type'    => 'setting',
					'excerpt' => '',
				);
			},
			$rows
		);
	}

	/**
	 * Get setting context for LLM.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type       Mention type.
	 * @param string $option_name Option name.
	 * @return string
	 */
	private function get_setting_context( $type, $option_name ) {
		$value = get_option( sanitize_key( $option_name ), null );

		return sprintf(
			"--- BEGIN SETTING CONTEXT ---\nOption: %s\nValue: %s\n--- END SETTING CONTEXT ---",
			esc_html( $option_name ),
			esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) )
		);
	}
}
