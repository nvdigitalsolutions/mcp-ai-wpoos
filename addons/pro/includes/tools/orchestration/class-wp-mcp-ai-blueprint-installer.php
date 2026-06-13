<?php
/**
 * Shared Blueprint Installer — installs curated assistant blueprints.
 *
 * Reusable across all Pro toolkits. Each import tool delegates to this installer,
 * which handles file discovery, JSON parsing, duplicate detection, post insertion,
 * and meta population. Supports both the abstract CRM-style blueprint format
 * ({name, description, meta}) and the direct WordPress-style Healthcare format
 * ({post_title, post_status, post_content, meta_input}).
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared blueprint installer.
 *
 * Each toolkit's import tool calls the static methods on this class to
 * keep the file-load / parse / insert / meta logic consistent.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Blueprint_Installer {

	/**
	 * Load and parse a blueprint JSON file.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $blueprint_dir  Absolute path to the examples directory.
	 * @param  string $blueprint_slug Sanitised blueprint slug (without .json).
	 * @return array|WP_Error         Parsed data or WP_Error.
	 */
	public static function load_blueprint( $blueprint_dir, $blueprint_slug ) {
		$file = trailingslashit( $blueprint_dir ) . $blueprint_slug . '.json';

		if ( ! file_exists( $file ) ) {
			return new WP_Error(
				'blueprint_not_found',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Blueprint "%s" not found.', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local blueprint JSON, not a remote URL.
		$json = file_get_contents( $file );
		if ( false === $json ) {
			return new WP_Error(
				'blueprint_read_error',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Could not read blueprint "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'blueprint_invalid_json',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Blueprint "%s" contains invalid JSON.', 'mcp-ai-wpoos-pro' ),
					esc_html( $blueprint_slug )
				)
			);
		}

		return $data;
	}

	/**
	 * Install a blueprint as an mcp_ai_assistant post.
	 *
	 * Supports two blueprint schemas:
	 *
	 * **CRM-style (abstract):**
	 * ```json
	 * { "name": "...", "description": "...", "meta": { ... } }
	 * ```
	 *
	 * **Healthcare-style (direct WP):**
	 * ```json
	 * { "post_title": "...", "post_status": "...", "post_content": "...", "meta_input": { ... } }
	 * ```
	 *
	 * When the Healthcare-style keys are present they take precedence (the
	 * `wp_insert_post` array is built directly from them).  Otherwise the
	 * CRM-style keys are mapped onto `post_title` / `post_content` / post meta.
	 *
	 * @since  2.3.0
	 *
	 * @param  array  $data        Parsed blueprint JSON.
	 * @param  string $blueprint_slug Slug used as the `_blueprint_source` post meta value.
	 * @param  bool   $overwrite   Whether to overwrite an existing assistant with the same title.
	 * @return array|WP_Error      Success envelope or WP_Error.
	 */
	public static function install( array $data, $blueprint_slug, $overwrite = false ) {
			// ── Resolve post data from either format ──
			$is_healthcare_style = isset( $data['post_title'] );

		if ( $is_healthcare_style ) {
			// Healthcare-style: direct WordPress post fields.
			$post_title   = $data['post_title'];
			$post_content = $data['post_content'] ?? '';
			$post_status  = $data['post_status'] ?? 'publish';
			$meta_input   = $data['meta_input'] ?? array();

			// Resolve profession → primary roles for healthcare-style too.
			if ( ! empty( $data['profession'] ) && ! isset( $meta_input['_wp_mcp_ai_primary_roles'] ) ) {
				$profession_post_id = self::find_profession_post_id( $data['profession'] );
				if ( $profession_post_id ) {
					$meta_input['_wp_mcp_ai_primary_roles'] = array( $profession_post_id );
				}
			}
		} else {
			// Abstracted blueprint — two sub-formats:
			//   CRM-style:  { name, meta: { profession, available_tools, instructions, ... } }
			//   Flat/legacy: { name, profession, tools, instructions, ... }  (no meta wrapper)
			$raw_meta = $data['meta'] ?? array();

			// If no meta wrapper but top-level keys exist, normalise flat format
			// into CRM-style so remap_crm_meta_to_canonical() can process them.
			if ( empty( $raw_meta ) && ! empty( $data['name'] ) ) {
				if ( ! empty( $data['profession'] ) ) {
					$raw_meta['profession'] = $data['profession'];
				}
				if ( ! empty( $data['tools'] ) && is_array( $data['tools'] ) ) {
					$raw_meta['available_tools'] = $data['tools'];
				}
				if ( ! empty( $data['instructions'] ) ) {
					$raw_meta['instructions'] = $data['instructions'];
				}
				// Carry over version and tags for raw-meta persistence.
				if ( ! empty( $data['version'] ) ) {
					$raw_meta['version'] = $data['version'];
				}
				if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
					$raw_meta['tags'] = $data['tags'];
				}
			}

			$post_title = $data['name'] ?? ucwords( str_replace( '-', ' ', $blueprint_slug ) );
			// Use instructions as post_content when available (more useful than description).
			$post_content = ! empty( $raw_meta['instructions'] )
				? $raw_meta['instructions']
				: ( $data['description'] ?? '' );
			$post_status  = 'publish';
			// Build canonical meta from the abstracted CRM fields.
			$meta_input = self::remap_crm_meta_to_canonical( $raw_meta );
		}

			// Use WP_Query instead of deprecated get_page_by_title().
			$existing_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'title'          => $post_title,
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'no_found_rows'  => true,
				)
			);
		$existing_id        = $existing_query->have_posts() ? $existing_query->posts[0]->ID : 0;
		wp_reset_postdata();

		if ( $existing_id ) {
			if ( ! $overwrite ) {
				return new WP_Error(
					'blueprint_duplicate',
					sprintf(
						/* translators: %s: assistant name */
						__( 'An assistant named "%s" already exists. Set overwrite=true to replace it.', 'mcp-ai-wpoos-pro' ),
						esc_html( $post_title )
					)
				);
			}

			// Overwrite: update existing post.
			$assistant_id = wp_update_post(
				array(
					'ID'           => $existing_id,
					'post_title'   => $post_title,
					'post_content' => $post_content,
					'post_status'  => $post_status,
				),
				true
			);

			if ( is_wp_error( $assistant_id ) ) {
				return $assistant_id;
			}

			// Clear existing blueprint meta before repopulating.
			delete_post_meta( $existing_id, '_blueprint_source' );
		} else {
			// Create new assistant post.
			$assistant_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_assistant',
					'post_title'   => $post_title,
					'post_status'  => $post_status,
					'post_content' => $post_content,
				),
				true
			);

			if ( is_wp_error( $assistant_id ) ) {
				return $assistant_id;
			}
		}

		// ── Write post meta ──
		if ( ! empty( $meta_input ) ) {
			foreach ( $meta_input as $key => $value ) {
				update_post_meta( $assistant_id, sanitize_key( $key ), $value );
			}
		}

			// For CRM-style blueprints, also persist the raw abstracted meta keys
			// so that the blueprints page and future reads have access to the
			// original fields (channels, framework, auto_reply_enabled, etc.).
		if ( ! $is_healthcare_style && ! empty( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$sanitised_key = sanitize_key( $key );
				// Don't overwrite canonical keys that were already written above.
				if ( 0 === strpos( $sanitised_key, '_wp_mcp_ai_' ) || 0 === strpos( $sanitised_key, 'mcp_ai_' ) ) {
					continue;
				}
				update_post_meta( $assistant_id, $sanitised_key, $value );
			}
		}

			// Always store the blueprint source slug.
			update_post_meta( $assistant_id, '_blueprint_source', sanitize_key( $blueprint_slug ) );

			/**
			* Fires after a blueprint has been installed.
			*
			* @since 2.3.0
			*
			* @param int    $assistant_id   The assistant post ID.
			* @param string $blueprint_slug The blueprint slug that was installed.
			* @param array  $data           The parsed blueprint JSON data.
			*/
			do_action( 'wp_mcp_ai_blueprint_installed', $assistant_id, $blueprint_slug, $data );

			return array(
				'success'      => true,
				'message'      => sprintf(
					/* translators: 1: blueprint name, 2: assistant ID */
					__( 'Blueprint "%1$s" imported as assistant #%2$d.', 'mcp-ai-wpoos-pro' ),
					$post_title,
					$assistant_id
				),
				'blueprint'    => $blueprint_slug,
				'assistant_id' => $assistant_id,
			);
	}

	/**
	 * List available blueprint slugs in a directory.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $blueprint_dir Absolute path to the examples directory.
	 * @return string[]              Sorted list of blueprint slugs (without .json).
	 */
	public static function list_blueprints( $blueprint_dir ) {
		$dir = trailingslashit( $blueprint_dir );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '*.json' );
		$slugs = array();

		foreach ( $files as $file ) {
			$basename = basename( $file, '.json' );
			// Skip files that are not blueprints (e.g. schema files).
			if ( 'schema' === $basename ) {
				continue;
			}
			$slugs[] = $basename;
		}

		sort( $slugs );
		return $slugs;
	}

	/**
	 * Remap CRM-style abstracted blueprint meta fields to canonical
	 * WordPress post meta keys that the assistant system reads.
	 *
	 * CRM blueprints use abstracted keys like `available_tools` and
	 * `instructions`. The assistant CPT stores these under canonical
	 * meta keys (`_wp_mcp_ai_tools`, `_wp_mcp_ai_system_prompt`, etc.).
	 * This method translates between the two schemas and also injects
	 * sensible defaults for provider / model / temperature from the
	 * plugin settings when the blueprint doesn't supply them.
	 *
	 * @since  2.3.1
	 *
	 * @param  array $raw_meta The `meta` block from a CRM-style blueprint JSON.
	 * @return array           Canonical meta key→value pairs.
	 */
	private static function remap_crm_meta_to_canonical( array $raw_meta ) {
		$canonical = array();

		// ── Tools ──
		if ( ! empty( $raw_meta['available_tools'] ) && is_array( $raw_meta['available_tools'] ) ) {
			$canonical['_wp_mcp_ai_tools'] = array_map( 'sanitize_key', $raw_meta['available_tools'] );
		}

		// ── System prompt ──
		if ( ! empty( $raw_meta['instructions'] ) ) {
			$canonical['_wp_mcp_ai_system_prompt'] = wp_strip_all_tags( $raw_meta['instructions'] );
		}

		// ── Required capability ──
		if ( ! empty( $raw_meta['required_capability'] ) ) {
			$canonical['mcp_ai_required_capability'] = sanitize_key( $raw_meta['required_capability'] );
		} else {
			$canonical['mcp_ai_required_capability'] = 'edit_posts';
		}

		// ── Profession → primary roles lookup ──
		if ( ! empty( $raw_meta['profession'] ) ) {
			$profession_post_id = self::find_profession_post_id( $raw_meta['profession'] );
			if ( $profession_post_id ) {
				$canonical['_wp_mcp_ai_primary_roles'] = array( $profession_post_id );
			}
		}

		// ── Provider / model / temperature defaults from plugin settings ──
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = ! empty( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';
		$default_model    = self::resolve_default_model( $settings, $default_provider );
		$default_temp     = isset( $settings['default_temperature'] ) ? floatval( $settings['default_temperature'] ) : 0.7;

		$canonical['_wp_mcp_ai_provider']    = sanitize_key( $default_provider );
		$canonical['_wp_mcp_ai_model']       = sanitize_text_field( $default_model );
		$canonical['_wp_mcp_ai_temperature'] = $default_temp;

		return $canonical;
	}

	/**
	 * Look up a profession post ID by its slug or title.
	 *
	 * The CRM blueprint `profession` field contains a machine-readable
	 * slug (e.g. "business_development", "sdr"). We match against the
	 * post_name of mcp_ai_profession posts, falling back to a title search.
	 *
	 * @since  2.3.1
	 *
	 * @param  string $profession_slug Profession identifier from the blueprint.
	 * @return int|null                Profession post ID or null if not found.
	 */
	private static function find_profession_post_id( $profession_slug ) {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return null;
		}

		// ── CRM role slug aliases ──
		// Some profession definitions use a prefixed slug (e.g. crm_sales_manager)
		// while CRM blueprints and tool tags use a shorter form (sales_manager).
		// This mapping bridges the two until the profession definitions are reseeded
		// with the canonical slugs.
		$crm_slug_aliases = array(
			'sales_manager' => 'crm_sales_manager',
		);

		// Try exact post_name match first.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'name'           => sanitize_title( $profession_slug ),
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( $query->have_posts() ) {
			$id = $query->posts[0];
			wp_reset_postdata();
			return (int) $id;
		}
		wp_reset_postdata();

		// Fall back 1: try CRM slug alias (e.g. sales_manager → crm_sales_manager).
		if ( isset( $crm_slug_aliases[ $profession_slug ] ) ) {
			$alias_slug = $crm_slug_aliases[ $profession_slug ];
			$query      = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_profession',
					'name'           => sanitize_title( $alias_slug ),
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);

			if ( $query->have_posts() ) {
				$id = $query->posts[0];
				wp_reset_postdata();
				return (int) $id;
			}
			wp_reset_postdata();
		}

		// Fall back 2: search by title (case-insensitive LIKE).
		$readable = ucwords( str_replace( '_', ' ', $profession_slug ) );
		$query    = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'title'          => $readable,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( $query->have_posts() ) {
			$id = $query->posts[0];
			wp_reset_postdata();
			return (int) $id;
		}
		wp_reset_postdata();

		return null;
	}

	/**
	 * Resolve the default model for a given provider from saved settings.
	 *
	 * @since  2.3.1
	 *
	 * @param  array  $settings Saved plugin settings.
	 * @param  string $provider Provider slug.
	 * @return string           Model identifier.
	 */
	private static function resolve_default_model( $settings, $provider ) {
		if ( ! empty( $settings['default_model'] ) ) {
			return $settings['default_model'];
		}

		$fallbacks = array(
			'openai'      => 'gpt-4.1',
			'anthropic'   => 'claude-sonnet-4-6',
			'gemini'      => 'gemini-3.5-flash',
			'ollama'      => 'llama4',
			'lm_studio'   => 'local',
			'cloudflare'  => '@cf/meta/llama-4-scout-17b-16e-instruct',
			'huggingface' => 'meta-llama/Llama-4-8B-Instruct',
			'deepseek'    => 'deepseek-v4-pro',
			'embedded'    => 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
		);

		return isset( $fallbacks[ $provider ] ) ? $fallbacks[ $provider ] : 'gpt-4.1';
	}

	/**
	 * Get a human-readable label for a blueprint slug.
	 *
	 * @since  2.3.0
	 *
	 * @param  string $slug Blueprint slug.
	 * @return string       Human-readable label.
	 */
	public static function slug_to_label( $slug ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
