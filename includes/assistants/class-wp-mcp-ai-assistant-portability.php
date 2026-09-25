<?php
/**
 * Assistant Portability Engine.
 *
 * Canonical export/import engine for `mcp_ai_assistant` posts. Produces and
 * consumes the versioned `nvoos-assistant` JSON bundle format used by every
 * surface in the plugin:
 *
 *   - WP-CLI:      `wp mcp-ai assistant export|import`
 *   - REST API:    `POST /mcp-ai/v1/assistants/export|import`
 *   - Admin UI:    row actions, bulk actions, and the Import/Export page
 *   - AI tools:    `export_assistant`, `import_assistant`, `duplicate_assistant`
 *   - Backup:      complements `WP_MCP_AI_Export_Provider_Assistants`
 *
 * Format detection on import accepts three shapes:
 *
 *   1. Canonical v1 bundle  — `{ format: "nvoos-assistant", assistants: [...] }`
 *   2. Legacy CLI files     — `{ version, exported, assistant: { title, meta } }`
 *      with `mcp_ai_`-stripped meta keys (re-prefixed on import).
 *   3. Blueprint JSON       — healthcare-style `{ post_title, meta_input }` or
 *      CRM-style `{ name, meta: { ... } }` (normalised with the same mapping
 *      as the Pro Blueprint Installer so a blueprint file imports in base).
 *
 * Security:
 *   - Credential hashes (`_wp_mcp_ai_credentials`) are never exported and are
 *     stripped from any import payload (defence in depth).
 *   - MCP App credentials (the `token` / `oauth_data` fields inside
 *     `_wp_mcp_ai_mcp_apps`) are redacted from exports and stripped from
 *     imports by default; on update, stored credentials are preserved for
 *     matching apps so a redacted bundle cannot blank live connections.
 *   - The meta denylist is filterable via `wp_mcp_ai_assistant_export_meta_denylist`.
 *   - Import payloads are validated against a JSON-Schema-style definition
 *     using WordPress' own `rest_validate_value_from_schema()`.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical assistant export/import engine.
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Assistant_Portability {

	/**
	 * Canonical bundle format identifier.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const FORMAT = 'nvoos-assistant';

	/**
	 * Current format version.
	 *
	 * @since 1.1.80
	 * @var int
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Meta keys never exported (credential hashes and site-specific pointers).
	 *
	 * @since 1.1.80
	 * @var array
	 */
	const DEFAULT_META_DENYLIST = array(
		'_wp_mcp_ai_credentials',
		'_wp_mcp_ai_external_action_id',
		'_wp_mcp_ai_external_action_type',
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
	);

	/**
	 * Capability required for every engine operation.
	 *
	 * @since 1.1.80
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'edit_posts';

	/**
	 * Resolve the export meta denylist.
	 *
	 * @since 1.1.80
	 *
	 * @return array List of meta keys excluded from exports.
	 */
	public static function get_meta_denylist() {
		/**
		 * Filter the assistant export meta denylist.
		 *
		 * Keys listed here are never written into an export bundle and are
		 * stripped from any import payload.
		 *
		 * @since 1.1.80
		 *
		 * @param array $denylist Meta keys to exclude.
		 */
		return (array) apply_filters( 'wp_mcp_ai_assistant_export_meta_denylist', self::DEFAULT_META_DENYLIST );
	}

	/**
	 * Whether a meta key is allowed in an export bundle.
	 *
	 * Plugin-owned keys are `_wp_mcp_ai_*`, `mcp_ai_*`, or Pro-owned
	 * `_wp_mcp_ai_pro_*`. Everything else (including core underscore keys) is
	 * excluded so bundles never leak third-party or internal data.
	 *
	 * @since 1.1.80
	 *
	 * @param string $meta_key Raw meta key.
	 * @return bool True when the key may be exported.
	 */
	public static function is_exportable_meta_key( $meta_key ) {
		if ( in_array( $meta_key, self::get_meta_denylist(), true ) ) {
			return false;
		}

		$prefixes = array( '_wp_mcp_ai_', 'mcp_ai_' );

		/**
		 * Filter the meta key prefixes eligible for export.
		 *
		 * @since 1.1.80
		 *
		 * @param array $prefixes Prefixes considered plugin-owned.
		 */
		$prefixes = (array) apply_filters( 'wp_mcp_ai_assistant_export_meta_prefixes', $prefixes );

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Export one or more assistants into the canonical bundle array.
	 *
	 * @since 1.1.80
	 *
	 * @param int[]|string $ids         Array of assistant post IDs or 'all'.
	 * @param array        $options     Optional. Export options.
	 *                                  - include_a2a (bool) Embed A2A agent cards. Default true.
	 * @return array|WP_Error Bundle array or WP_Error.
	 */
	public static function export_assistants( $ids, $options = array() ) {
		$include_a2a = isset( $options['include_a2a'] ) ? (bool) $options['include_a2a'] : true;

		if ( 'all' === $ids || empty( $ids ) ) {
			$ids = self::get_all_assistant_ids();
		} else {
			$ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
		}

		if ( empty( $ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_no_assistants',
				__( 'No assistants selected for export.', 'mcp-ai-wpoos' )
			);
		}

		$assistants = array();

		foreach ( $ids as $assistant_id ) {
			$post = get_post( $assistant_id );

			if ( ! $post || self::post_type() !== $post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_portability_invalid_assistant',
					sprintf(
						/* translators: %d: assistant post ID */
						__( 'Assistant %d not found.', 'mcp-ai-wpoos' ),
						(int) $assistant_id
					),
					array( 'status' => 404 )
				);
			}

			$assistant = array(
				'title'   => $post->post_title,
				'slug'    => $post->post_name,
				'status'  => $post->post_status,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'meta'    => self::get_export_meta( $assistant_id ),
			);

			if ( $include_a2a && class_exists( 'WP_MCP_AI_A2A_Agent_Card' ) ) {
				$card = WP_MCP_AI_A2A_Agent_Card::build_card_for_assistant( $assistant_id );
				if ( ! is_wp_error( $card ) ) {
					$assistant['a2a'] = $card;
				}
			}

			$assistants[] = $assistant;
		}

		$bundle = array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			'exported_at'    => gmdate( 'c' ),
			'exported_by'    => get_current_user_id(),
			'assistants'     => $assistants,
		);

		/**
		 * Filter the export bundle before it is serialised.
		 *
		 * @since 1.1.80
		 *
		 * @param array $bundle The complete export bundle.
		 * @param array $ids    The assistant IDs included.
		 */
		return apply_filters( 'wp_mcp_ai_assistant_export_data', $bundle, $ids );
	}

	/**
	 * Collect the exportable meta of a single assistant.
	 *
	 * @since 1.1.80
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Meta key → value pairs.
	 */
	public static function get_export_meta( $assistant_id ) {
		$all_meta = get_post_meta( $assistant_id );
		$meta     = array();

		foreach ( $all_meta as $meta_key => $meta_values ) {
			if ( ! self::is_exportable_meta_key( $meta_key ) ) {
				continue;
			}

			// Unwrap single-value arrays; keep multi-value rows as arrays.
			$meta[ $meta_key ] = 1 === count( $meta_values )
				? maybe_unserialize( $meta_values[0] )
				: array_map( 'maybe_unserialize', $meta_values );

			// MCP App credentials never leave the site: strip token material
			// from `_wp_mcp_ai_mcp_apps` while keeping the rest of each app
			// config so bundles stay round-trip faithful.
			if ( '_wp_mcp_ai_mcp_apps' === $meta_key ) {
				$meta[ $meta_key ] = self::prepare_mcp_apps_for_export( $meta[ $meta_key ] );
			}
		}

		return $meta;
	}

	/**
	 * Get every assistant post ID on the site.
	 *
	 * @since 1.1.80
	 *
	 * @return int[] Assistant post IDs.
	 */
	public static function get_all_assistant_ids() {
		$query = get_posts(
			array(
				'post_type'      => self::post_type(),
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query );
	}

	/**
	 * Parse and validate an import payload into a normalised bundle.
	 *
	 * Accepts canonical v1 bundles, legacy CLI files, and blueprint JSON.
	 *
	 * @since 1.1.80
	 *
	 * @param string $json Raw JSON payload.
	 * @return array|WP_Error Normalised bundle array or WP_Error.
	 */
	public static function parse_import( $json ) {
		$data = json_decode( (string) $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_invalid_json',
				__( 'Import payload is not valid JSON.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$bundle = self::normalise_payload( $data );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$validation = rest_validate_value_from_schema( $bundle, self::get_bundle_schema(), 'assistant_import' );

		if ( is_wp_error( $validation ) ) {
			$validation->add_data( array( 'status' => 400 ) );
			return $validation;
		}

		/**
		 * Filter the parsed import bundle before it is applied.
		 *
		 * @since 1.1.80
		 *
		 * @param array $bundle Normalised, validated import bundle.
		 */
		return apply_filters( 'wp_mcp_ai_assistant_import_data', $bundle );
	}

	/**
	 * Normalise any supported payload shape into the canonical bundle shape.
	 *
	 * @since 1.1.80
	 *
	 * @param array $data Decoded JSON payload.
	 * @return array|WP_Error Canonical bundle or WP_Error.
	 */
	protected static function normalise_payload( $data ) {
		// Canonical v1 bundle.
		if ( isset( $data['assistants'] ) && is_array( $data['assistants'] ) ) {
			return $data;
		}

		// Legacy CLI shape: { assistant: { title, status, content, meta } }.
		if ( isset( $data['assistant'] ) && is_array( $data['assistant'] ) ) {
			$legacy = $data['assistant'];

			if ( empty( $legacy['title'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_portability_missing_title',
					__( 'Legacy export is missing the assistant title.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Legacy exports store meta keys without the mcp_ai_ prefix.
			$meta = array();
			if ( isset( $legacy['meta'] ) && is_array( $legacy['meta'] ) ) {
				foreach ( $legacy['meta'] as $meta_key => $meta_value ) {
					$meta_key = sanitize_key( $meta_key );
					if ( '' !== $meta_key ) {
						$meta[ 'mcp_ai_' . $meta_key ] = $meta_value;
					}
				}
			}

			return array(
				'format'         => self::FORMAT,
				'format_version' => self::FORMAT_VERSION,
				'assistants'     => array(
					array(
						'title'   => $legacy['title'],
						'slug'    => isset( $legacy['slug'] ) ? $legacy['slug'] : '',
						'status'  => isset( $legacy['status'] ) ? $legacy['status'] : 'draft',
						'content' => isset( $legacy['content'] ) ? $legacy['content'] : '',
						'excerpt' => isset( $legacy['excerpt'] ) ? $legacy['excerpt'] : '',
						'meta'    => $meta,
					),
				),
			);
		}

		// Blueprint shape: healthcare-style { post_title, meta_input } or
		// CRM-style { name, meta: { ... } }.
		if ( isset( $data['post_title'] ) || ( isset( $data['name'] ) && ! isset( $data['assistant'] ) ) ) {
			$assistant = self::normalise_blueprint_assistant( $data );

			return array(
				'format'         => self::FORMAT,
				'format_version' => self::FORMAT_VERSION,
				'assistants'     => array( $assistant ),
			);
		}

		return new WP_Error(
			'wp_mcp_ai_portability_unknown_format',
			__( 'Import payload is not a recognised assistant export, legacy file, or blueprint.', 'mcp-ai-wpoos' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Map a single blueprint JSON object onto the canonical assistant shape.
	 *
	 * Mirrors the Pro Blueprint Installer mapping so a blueprint file imports
	 * identically in base and Pro.
	 *
	 * @since 1.1.80
	 *
	 * @param array $data Blueprint object.
	 * @return array Canonical assistant entry.
	 */
	protected static function normalise_blueprint_assistant( $data ) {
		$meta = array();

		if ( isset( $data['post_title'] ) ) {
			// Healthcare-style: direct WordPress post fields.
			$title    = $data['post_title'];
			$content  = isset( $data['post_content'] ) ? $data['post_content'] : '';
			$status   = isset( $data['post_status'] ) ? $data['post_status'] : 'publish';
			$meta_raw = isset( $data['meta_input'] ) && is_array( $data['meta_input'] ) ? $data['meta_input'] : array();
		} else {
			// CRM-style / flat: abstracted blueprint fields.
			$title    = $data['name'];
			$status   = 'publish';
			$meta_raw = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

			// Normalise the flat legacy shape into CRM-style.
			if ( empty( $meta_raw ) ) {
				foreach ( array( 'profession', 'tools', 'instructions', 'version', 'tags' ) as $flat_key ) {
					if ( ! empty( $data[ $flat_key ] ) ) {
						$meta_raw[ $flat_key ] = $data[ $flat_key ];
					}
				}
			}

			$content = isset( $meta_raw['instructions'] ) ? $meta_raw['instructions'] : ( isset( $data['description'] ) ? $data['description'] : '' );

			// Abstracted → canonical key remap.
			if ( ! empty( $meta_raw['available_tools'] ) && is_array( $meta_raw['available_tools'] ) ) {
				$meta['_wp_mcp_ai_tools'] = array_map( 'sanitize_key', $meta_raw['available_tools'] );
			} elseif ( ! empty( $meta_raw['tools'] ) && is_array( $meta_raw['tools'] ) ) {
				$meta['_wp_mcp_ai_tools'] = array_map( 'sanitize_key', $meta_raw['tools'] );
			}
			if ( ! empty( $meta_raw['instructions'] ) ) {
				$meta['_wp_mcp_ai_system_prompt'] = wp_strip_all_tags( $meta_raw['instructions'] );
			}
			if ( ! empty( $meta_raw['required_capability'] ) ) {
				$meta['mcp_ai_required_capability'] = sanitize_key( $meta_raw['required_capability'] );
			}
			if ( ! empty( $meta_raw['provider'] ) ) {
				$meta['_wp_mcp_ai_provider'] = sanitize_key( $meta_raw['provider'] );
			}
			if ( ! empty( $meta_raw['model'] ) ) {
				$meta['_wp_mcp_ai_model'] = sanitize_text_field( $meta_raw['model'] );
			}
			if ( isset( $meta_raw['temperature'] ) ) {
				$meta['_wp_mcp_ai_temperature'] = (float) $meta_raw['temperature'];
			}
			if ( ! empty( $meta_raw['memory_files'] ) && is_array( $meta_raw['memory_files'] ) ) {
				$meta['_wp_mcp_ai_memory_files'] = array_map( 'absint', $meta_raw['memory_files'] );
			}
		}

		// Persist any remaining canonical-prefixed keys verbatim.
		foreach ( $meta_raw as $meta_key => $meta_value ) {
			$meta_key = sanitize_key( $meta_key );
			if ( '' !== $meta_key && self::is_exportable_meta_key( $meta_key ) && ! isset( $meta[ $meta_key ] ) ) {
				$meta[ $meta_key ] = $meta_value;
			}
		}

		return array(
			'title'   => $title,
			'slug'    => '',
			'status'  => $status,
			'content' => $content,
			'excerpt' => isset( $data['post_excerpt'] ) ? $data['post_excerpt'] : '',
			'meta'    => $meta,
		);
	}

	/**
	 * Apply a normalised bundle to the site.
	 *
	 * @since 1.1.80
	 *
	 * @param array $bundle  Normalised bundle (see parse_import()).
	 * @param array $options Optional. Import options.
	 *                       - mode (string)   skip|overwrite|duplicate. Default skip.
	 *                       - dry_run (bool)  Validate + report without writing. Default false.
	 *                       - status_override (string) Force imported post status.
	 * @return array|WP_Error Report array or WP_Error.
	 */
	public static function import_bundle( $bundle, $options = array() ) {
		$mode            = isset( $options['mode'] ) ? sanitize_key( $options['mode'] ) : 'skip';
		$mode            = in_array( $mode, array( 'skip', 'overwrite', 'duplicate' ), true ) ? $mode : 'skip';
		$dry_run         = ! empty( $options['dry_run'] );
		$status_override = isset( $options['status_override'] ) ? sanitize_key( $options['status_override'] ) : '';
		$status_override = in_array( $status_override, array( 'draft', 'publish', 'private' ), true ) ? $status_override : '';

		$report = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'items'   => array(),
		);

		foreach ( $bundle['assistants'] as $index => $assistant ) {
			$title = sanitize_text_field( isset( $assistant['title'] ) ? $assistant['title'] : '' );

			if ( '' === $title ) {
				++$report['errors'];
				$report['items'][] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => __( 'Assistant is missing a title.', 'mcp-ai-wpoos' ),
				);
				continue;
			}

			$existing_id = self::find_existing_assistant( $assistant );
			// Duplicate mode always inserts, so it never counts as an update.
			$is_update = (bool) $existing_id && 'duplicate' !== $mode;

			if ( $existing_id && 'skip' === $mode ) {
				++$report['skipped'];
				$report['items'][] = array(
					'index'        => $index,
					'status'       => 'skipped',
					'title'        => $title,
					'assistant_id' => $existing_id,
				);
				continue;
			}

			if ( $dry_run ) {
				++$report[ $is_update ? 'updated' : 'created' ];
				$report['items'][] = array(
					'index'        => $index,
					'status'       => 'dry_run',
					'title'        => $title,
					'assistant_id' => $is_update ? $existing_id : 0,
					'action'       => $is_update ? 'update' : 'create',
				);
				continue;
			}

			$result = self::apply_single_assistant( $assistant, $existing_id, $mode, $status_override );

			if ( is_wp_error( $result ) ) {
				++$report['errors'];
				$report['items'][] = array(
					'index'   => $index,
					'status'  => 'error',
					'title'   => $title,
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$report['items'][] = array(
				'index'        => $index,
				'status'       => $is_update ? 'updated' : 'created',
				'title'        => $title,
				'assistant_id' => $result,
			);
			++$report[ $is_update ? 'updated' : 'created' ];
		}

		return $report;
	}

	/**
	 * Locate an existing assistant matching an import entry.
	 *
	 * Matches by slug first, then by exact title.
	 *
	 * @since 1.1.80
	 *
	 * @param array $assistant Canonical assistant entry.
	 * @return int Existing post ID or 0.
	 */
	protected static function find_existing_assistant( $assistant ) {
		$slug = isset( $assistant['slug'] ) ? sanitize_title( $assistant['slug'] ) : '';

		if ( '' !== $slug ) {
			$found = get_page_by_path( $slug, OBJECT, self::post_type() );
			if ( $found ) {
				return (int) $found->ID;
			}
		}

		$title = sanitize_text_field( isset( $assistant['title'] ) ? $assistant['title'] : '' );
		$query = new WP_Query(
			array(
				'post_type'      => self::post_type(),
				'title'          => $title,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$id = $query->have_posts() ? (int) $query->posts[0] : 0;
		wp_reset_postdata();

		return $id;
	}

	/**
	 * Create or update one assistant from a canonical entry.
	 *
	 * @since 1.1.80
	 *
	 * @param array  $assistant       Canonical assistant entry.
	 * @param int    $existing_id     Existing post ID or 0.
	 * @param string $mode            Import mode (overwrite|duplicate).
	 * @param string $status_override Optional status override.
	 * @return int|WP_Error New/updated post ID or WP_Error.
	 */
	protected static function apply_single_assistant( $assistant, $existing_id, $mode, $status_override ) {
		$title   = sanitize_text_field( $assistant['title'] );
		$status  = isset( $assistant['status'] ) ? sanitize_key( $assistant['status'] ) : 'draft';
		$status  = get_post_status_object( $status ) ? $status : 'draft';
		$status  = '' !== $status_override ? $status_override : $status;
		$content = isset( $assistant['content'] ) ? wp_kses_post( $assistant['content'] ) : '';
		$excerpt = isset( $assistant['excerpt'] ) ? sanitize_textarea_field( $assistant['excerpt'] ) : '';

		$post_args = array(
			'post_type'    => self::post_type(),
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
		);

		if ( $existing_id && 'duplicate' !== $mode ) {
			$post_args['ID'] = $existing_id;
			$post_id         = wp_update_post( $post_args, true );
		} else {
			$post_id = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::write_assistant_meta( $post_id, isset( $assistant['meta'] ) ? $assistant['meta'] : array(), (bool) $existing_id );

		/**
		 * Fires after a single assistant import has been applied.
		 *
		 * @since 1.1.80
		 *
		 * @param int   $post_id   The created/updated assistant post ID.
		 * @param array $assistant The canonical assistant entry applied.
		 * @param bool  $updated   Whether an existing assistant was updated.
		 */
		do_action( 'wp_mcp_ai_assistant_imported', $post_id, $assistant, (bool) $existing_id && 'duplicate' !== $mode );

		return $post_id;
	}

	/**
	 * Write imported meta onto an assistant post.
	 *
	 * On update, only the imported plugin-owned keys are replaced — existing
	 * credentials and unrelated meta on the target stay untouched.
	 *
	 * @since 1.1.80
	 *
	 * @param int   $post_id Assistant post ID.
	 * @param array $meta    Meta key → value pairs from the import payload.
	 * @param bool  $updated Whether the post already existed.
	 * @return void
	 */
	protected static function write_assistant_meta( $post_id, $meta, $updated ) {
		if ( ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $meta_key => $meta_value ) {
			$meta_key = sanitize_key( $meta_key );

			if ( '' === $meta_key || ! self::is_exportable_meta_key( $meta_key ) ) {
				continue;
			}

			// MCP App configs get structural sanitization, credential
			// stripping, and stored-credential preservation on update.
			if ( '_wp_mcp_ai_mcp_apps' === $meta_key ) {
				$meta_value = self::prepare_mcp_apps_for_import( $meta_value, $post_id, $updated );
			}

			update_post_meta( $post_id, $meta_key, self::sanitize_meta_value( $meta_key, $meta_value ) );
		}

		// Always clear the blueprint source stamp when importing over an
		// assistant so imported content is not mistaken for a curated blueprint.
		if ( $updated ) {
			delete_post_meta( $post_id, '_blueprint_source' );
		}
	}

	/**
	 * Sanitize a single imported meta value by key type.
	 *
	 * Array-valued keys pass through structurally; scalar strings are
	 * sanitized with the appropriate WordPress helper.
	 *
	 * @since 1.1.80
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Raw value from the import payload.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_meta_value( $meta_key, $meta_value ) {
		if ( is_array( $meta_value ) ) {
			return $meta_value;
		}

		switch ( $meta_key ) {
			case '_wp_mcp_ai_temperature':
				return (float) $meta_value;

			case '_wp_mcp_ai_system_prompt':
				return sanitize_textarea_field( (string) $meta_value );

			case '_wp_mcp_ai_prompt_caching':
				return (bool) $meta_value;

			case '_wp_mcp_ai_primary_roles':
			case '_wp_mcp_ai_memory_files':
				return array_map( 'absint', (array) $meta_value );

			default:
				return sanitize_text_field( (string) $meta_value );
		}
	}

	/**
	 * Prepare MCP App configs for an export bundle.
	 *
	 * Strips the `token` and `oauth_data` credential fields from every app
	 * entry while keeping the remaining config shape intact. A non-array
	 * value (a config stored as a string, which the MCP Apps subsystem never
	 * reads anyway) is replaced with an empty array under redaction so it can
	 * never leak credentials. Opt out only for explicitly trusted migrations.
	 *
	 * @since 1.1.85
	 *
	 * @param mixed $apps Raw `_wp_mcp_ai_mcp_apps` value.
	 * @return mixed Credential-redacted value (array, or the original value
	 *               when redaction is disabled).
	 */
	public static function prepare_mcp_apps_for_export( $apps ) {
		/**
		 * Filter whether MCP App credentials are redacted from export bundles.
		 *
		 * @since 1.1.85
		 *
		 * @param bool $redact Whether to strip `token` / `oauth_data` from
		 *                     `_wp_mcp_ai_mcp_apps` entries. Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_assistant_export_redact_mcp_app_tokens', true ) ) {
			return $apps;
		}

		return self::redact_mcp_app_credentials( is_array( $apps ) ? $apps : array() );
	}

	/**
	 * Strip credential fields from MCP App config entries.
	 *
	 * Shared by the export and import paths. Removes `token` and `oauth_data`
	 * (OAuth access/refresh material) from each entry; every identity and
	 * behaviour field (`label`, `server_url`, `auth_type`, `header_name`,
	 * `enabled`, `timeout`, `verify_ssl`) survives. A missing `token` is
	 * equivalent to an empty credential throughout the MCP Apps subsystem —
	 * the metabox treats a blank token as "preserve the stored value".
	 *
	 * @since 1.1.85
	 *
	 * @param array $apps MCP App config array.
	 * @return array Config array with credential fields removed.
	 */
	public static function redact_mcp_app_credentials( array $apps ) {
		foreach ( $apps as $index => $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}

			unset( $apps[ $index ]['token'], $apps[ $index ]['oauth_data'] );
		}

		return $apps;
	}

	/**
	 * Prepare imported MCP App configs for storage.
	 *
	 * Three layers:
	 *
	 * 1. **Structural sanitization** of each entry (mirrors the Pro registry's
	 *    `sanitize_app_config()` without the Pro class dependency — the base
	 *    engine must stay standalone).
	 * 2. **Credential stripping** — `token` / `oauth_data` never arrive via an
	 *    import payload, the same rule as `_wp_mcp_ai_credentials` (defence in
	 *    depth). Opt out with the import redaction filter.
	 * 3. **Update preservation** — overwriting an assistant with a redacted
	 *    bundle must not blank its live connections, so stored credentials are
	 *    restored for matching apps (identity: `server_url` + `auth_type` +
	 *    `header_name`, falling back to the positional index), mirroring the
	 *    MCP Apps metabox save behaviour.
	 *
	 * @since 1.1.85
	 *
	 * @param mixed $mcp_apps Raw `_wp_mcp_ai_mcp_apps` value from the payload.
	 * @param int   $post_id  Target assistant post ID.
	 * @param bool  $updated  Whether the post already existed.
	 * @return array Sanitized, credential-stripped config array.
	 */
	protected static function prepare_mcp_apps_for_import( $mcp_apps, $post_id, $updated ) {
		$apps = is_array( $mcp_apps ) ? array_values( $mcp_apps ) : array();

		foreach ( $apps as $index => $app ) {
			if ( ! is_array( $app ) ) {
				unset( $apps[ $index ] );
				continue;
			}

			$auth_type = isset( $app['auth_type'] ) && in_array( $app['auth_type'], array( 'none', 'bearer', 'basic', 'header', 'oauth' ), true )
				? $app['auth_type']
				: 'none';

			$apps[ $index ] = array(
				'label'          => isset( $app['label'] ) ? sanitize_text_field( $app['label'] ) : '',
				'server_url'     => isset( $app['server_url'] ) ? esc_url_raw( $app['server_url'] ) : '',
				'auth_type'      => $auth_type,
				'token'          => isset( $app['token'] ) ? sanitize_text_field( $app['token'] ) : '',
				'header_name'    => isset( $app['header_name'] ) ? sanitize_text_field( $app['header_name'] ) : '',
				'connection_ref' => isset( $app['connection_ref'] ) ? sanitize_key( $app['connection_ref'] ) : '',
				'enabled'        => isset( $app['enabled'] ) ? (bool) $app['enabled'] : true,
				'timeout'        => isset( $app['timeout'] ) ? max( 1, min( 120, absint( $app['timeout'] ) ) ) : 30,
				'verify_ssl'     => isset( $app['verify_ssl'] ) ? (bool) $app['verify_ssl'] : true,
			);

			// OAuth token data rides along only for OAuth apps.
			if ( 'oauth' === $auth_type && ! empty( $app['oauth_data'] ) && is_array( $app['oauth_data'] ) ) {
				$apps[ $index ]['oauth_data'] = array(
					'access_token'  => isset( $app['oauth_data']['access_token'] ) ? sanitize_text_field( $app['oauth_data']['access_token'] ) : '',
					'refresh_token' => isset( $app['oauth_data']['refresh_token'] ) ? sanitize_text_field( $app['oauth_data']['refresh_token'] ) : '',
					'token_type'    => isset( $app['oauth_data']['token_type'] ) ? sanitize_text_field( $app['oauth_data']['token_type'] ) : 'Bearer',
					'expires_in'    => isset( $app['oauth_data']['expires_in'] ) ? absint( $app['oauth_data']['expires_in'] ) : 3600,
					'scope'         => isset( $app['oauth_data']['scope'] ) ? sanitize_text_field( $app['oauth_data']['scope'] ) : '',
					'issued_at'     => isset( $app['oauth_data']['issued_at'] ) ? absint( $app['oauth_data']['issued_at'] ) : time(),
				);
			}

			// Entries without a usable endpoint are dropped, matching the Pro
			// registry's save behaviour — unless they carry a global connection
			// reference (the URL is resolved at chat time).
			if ( '' === $apps[ $index ]['server_url'] && '' === $apps[ $index ]['connection_ref'] ) {
				unset( $apps[ $index ] );
			}
		}

		$apps = array_values( $apps );

		/**
		 * Filter whether MCP App credentials are stripped from import payloads.
		 *
		 * Disable only when migrating credentials between explicitly trusted
		 * deployments.
		 *
		 * @since 1.1.85
		 *
		 * @param bool $strip Whether to strip `token` / `oauth_data` from
		 *                    incoming `_wp_mcp_ai_mcp_apps` entries. Default true.
		 */
		if ( apply_filters( 'wp_mcp_ai_assistant_import_redact_mcp_app_tokens', true ) ) {
			$apps = self::redact_mcp_app_credentials( $apps );
		}

		// On update, restore stored credentials for matching apps so a
		// redacted bundle cannot blank live connections.
		if ( $updated && $post_id ) {
			$existing = get_post_meta( $post_id, '_wp_mcp_ai_mcp_apps', true );
			$existing = is_array( $existing ) ? array_values( $existing ) : array();

			foreach ( $apps as $index => $app ) {
				if ( ! is_array( $app ) || 'none' === $app['auth_type'] ) {
					continue;
				}

				$stored = self::find_stored_mcp_app( $app, $existing, $index );
				if ( null === $stored ) {
					continue;
				}

				if ( empty( $app['token'] ) && ! empty( $stored['token'] ) ) {
					$apps[ $index ]['token'] = $stored['token'];
				}
				if ( empty( $app['oauth_data'] ) && ! empty( $stored['oauth_data'] ) ) {
					$apps[ $index ]['oauth_data'] = $stored['oauth_data'];
				}
			}
		}

		return $apps;
	}

	/**
	 * Locate the stored MCP App matching an incoming entry.
	 *
	 * Matches on the connection identity (`server_url` + `auth_type` +
	 * `header_name` — the same triple the registry uses for status snapshots)
	 * and falls back to the positional index for reordered bundles.
	 *
	 * @since 1.1.85
	 *
	 * @param array $app           Incoming sanitized app entry.
	 * @param array $existing_apps Stored app configs.
	 * @param int   $index         Position of the incoming entry.
	 * @return array|null Matching stored entry or null.
	 */
	protected static function find_stored_mcp_app( $app, $existing_apps, $index ) {
		if ( ! empty( $app['server_url'] ) ) {
			foreach ( $existing_apps as $stored ) {
				if ( ! is_array( $stored ) ) {
					continue;
				}

				if (
					isset( $stored['server_url'] ) && $stored['server_url'] === $app['server_url'] &&
					( isset( $stored['auth_type'] ) ? $stored['auth_type'] : 'none' ) === $app['auth_type'] &&
					( isset( $stored['header_name'] ) ? $stored['header_name'] : '' ) === $app['header_name']
				) {
					return $stored;
				}
			}
		}

		return isset( $existing_apps[ $index ] ) && is_array( $existing_apps[ $index ] )
			? $existing_apps[ $index ]
			: null;
	}

	/**
	 * JSON-Schema-style definition for import validation.
	 *
	 * Kept flat (no $ref) so rest_validate_value_from_schema() can validate
	 * the whole bundle without an external JSON Schema library.
	 *
	 * @since 1.1.80
	 *
	 * @return array Schema array.
	 */
	public static function get_bundle_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'format'         => array(
					'type' => 'string',
				),
				'format_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'plugin_version' => array(
					'type' => 'string',
				),
				'exported_at'    => array(
					'type' => 'string',
				),
				'exported_by'    => array(
					'type' => 'integer',
				),
				'assistants'     => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 500,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'   => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'slug'    => array(
								'type' => 'string',
							),
							'status'  => array(
								'type' => 'string',
							),
							'content' => array(
								'type' => 'string',
							),
							'excerpt' => array(
								'type' => 'string',
							),
							'meta'    => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
							'a2a'     => array(
								'type' => 'object',
							),
						),
						'required'             => array( 'title' ),
						'additionalProperties' => true,
					),
				),
			),
			'required'             => array( 'assistants' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Build a standalone A2A export for one assistant.
	 *
	 * Used by the `--format=a2a` CLI flag and the `export_assistant` tool's
	 * a2a output mode.
	 *
	 * @since 1.1.80
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array|WP_Error A2A agent card or WP_Error.
	 */
	public static function export_a2a_card( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_A2A_Agent_Card' ) ) {
			return new WP_Error(
				'wp_mcp_ai_portability_a2a_unavailable',
				__( 'A2A agent card support is unavailable.', 'mcp-ai-wpoos' ),
				array( 'status' => 501 )
			);
		}

		return WP_MCP_AI_A2A_Agent_Card::build_card_for_assistant( absint( $assistant_id ) );
	}

	/**
	 * Resolve the assistant post type slug.
	 *
	 * @since 1.1.80
	 *
	 * @return string Post type slug.
	 */
	protected static function post_type() {
		return class_exists( 'WP_MCP_AI_Assistant_CPT' ) ? WP_MCP_AI_Assistant_CPT::POST_TYPE : 'mcp_ai_assistant';
	}

	/**
	 * Convert a canonical assistant entry into the Pro blueprint JSON shape.
	 *
	 * The output is accepted by WP_MCP_AI_Blueprint_Installer::install() and
	 * by the Pro blueprints admin pages, so any live assistant can be frozen
	 * into a curated blueprint.
	 *
	 * @since 1.1.80
	 *
	 * @param array $assistant Canonical assistant entry.
	 * @return array Blueprint JSON array.
	 */
	public static function to_blueprint_json( $assistant ) {
		$meta   = isset( $assistant['meta'] ) && is_array( $assistant['meta'] ) ? $assistant['meta'] : array();
		$output = array(
			'name'        => isset( $assistant['title'] ) ? $assistant['title'] : '',
			'description' => isset( $assistant['excerpt'] ) && '' !== $assistant['excerpt'] ? $assistant['excerpt'] : wp_trim_words( isset( $assistant['content'] ) ? $assistant['content'] : '', 40 ),
			'meta'        => array(),
		);

		if ( isset( $meta['_wp_mcp_ai_system_prompt'] ) ) {
			$output['meta']['instructions'] = $meta['_wp_mcp_ai_system_prompt'];
		}
		if ( isset( $meta['_wp_mcp_ai_tools'] ) && is_array( $meta['_wp_mcp_ai_tools'] ) ) {
			$output['meta']['available_tools'] = $meta['_wp_mcp_ai_tools'];
		}
		if ( isset( $meta['_wp_mcp_ai_provider'] ) ) {
			$output['meta']['provider'] = $meta['_wp_mcp_ai_provider'];
		}
		if ( isset( $meta['_wp_mcp_ai_model'] ) ) {
			$output['meta']['model'] = $meta['_wp_mcp_ai_model'];
		}
		if ( isset( $meta['_wp_mcp_ai_temperature'] ) ) {
			$output['meta']['temperature'] = $meta['_wp_mcp_ai_temperature'];
		}
		if ( isset( $meta['mcp_ai_required_capability'] ) ) {
			$output['meta']['required_capability'] = $meta['mcp_ai_required_capability'];
		}
		if ( isset( $meta['_wp_mcp_ai_memory_files'] ) && is_array( $meta['_wp_mcp_ai_memory_files'] ) ) {
			$output['meta']['memory_files'] = $meta['_wp_mcp_ai_memory_files'];
		}

		/**
		 * Filter the blueprint JSON produced from an assistant export.
		 *
		 * Pro integrations (toolkit-specific blueprint fields) hook here.
		 *
		 * @since 1.1.80
		 *
		 * @param array $output    Blueprint JSON array.
		 * @param array $assistant Canonical assistant entry.
		 */
		return apply_filters( 'wp_mcp_ai_assistant_to_blueprint_json', $output, $assistant );
	}
}
