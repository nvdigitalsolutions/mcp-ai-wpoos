<?php
/**
 * Manifest-driven Pro toolkit REST controller.
 *
 * Exposes the generic toolkit contract the shared SPA consumes:
 * catalogue, manifest, settings, dashboard aggregates, entity CRUD,
 * server-side duplicate detection and dry-run bulk import. Every family is
 * data (a JSON manifest in `includes/toolkits/manifests/`), so this controller
 * is the only PHP a new toolkit needs.
 *
 * Namespace: `mcp-ai-pro/v1` (Pro-only CRUD surface).
 * Base: `/toolkit-manifests`. The schedule-anything controller owns
 * `mcp-ai-pro/v1/toolkits` (per-tenant toggles), and `register_rest_route()`
 * keys handlers by route + method, so the second registration for a route
 * silently replaces the first. Keep the two bases distinct.
 * Auth: cookie + nonce; every route requires `manage_options`.
 * Envelope: `{ success: true, data: … }` / `{ success: false, error:
 * { code, message, fields? } }` — identical to the Laravel runtime so the
 * console cannot tell them apart.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_REST_Controller' ) ) {
	/**
	 * REST adapter for the toolkit engine.
	 *
	 * @since 2.6.0
	 */
	class WP_MCP_AI_Pro_Toolkit_REST_Controller {

		/**
		 * REST namespace.
		 */
		const NAMESPACE_V1 = 'mcp-ai-pro/v1';

		/**
		 * Route base.
		 *
		 * Not `/toolkits`: the schedule-anything controller registers that route
		 * with a different payload, and duplicate route + method registrations
		 * overwrite each other in WP_REST_Server.
		 */
		const BASE = '/toolkit-manifests';

		/**
		 * Register the toolkit routes.
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			$slug   = '(?P<slug>[a-z0-9_-]+)';
			$entity = '(?P<entity>[a-z0-9_-]+)';
			$id     = '(?P<id>[^/]+)';

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::slug_args(),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/settings',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_settings' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::slug_args(),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'update_settings' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::slug_args(),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/dashboard',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'dashboard' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::slug_args(),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/research',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'research' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::slug_args(),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/entities/' . $entity,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'index_entity' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::entity_args(),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'create_entity' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::entity_args(),
					),
				)
			);

			// Literal segments before `{id}` so they win the match.
			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/entities/' . $entity . '/duplicates',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'duplicates' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array_merge(
						self::entity_args(),
						array(
							'column' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							),
							'limit'  => array(
								'type'              => 'integer',
								'default'           => 25,
								'sanitize_callback' => 'absint',
							),
						)
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/entities/' . $entity . '/import',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'import' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::entity_args(),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				self::BASE . '/' . $slug . '/entities/' . $entity . '/' . $id,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'show_entity' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::record_args(),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'update_entity' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::record_args(),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( __CLASS__, 'delete_entity' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
						'args'                => self::record_args(),
					),
				)
			);
		}

		/**
		 * Every toolkit route requires the admin capability.
		 *
		 * @return bool
		 */
		public static function check_admin_permission(): bool {
			return current_user_can( 'manage_options' );
		}

		// ------------------------------------------------------------ handlers

		/**
		 * Catalogue: every valid manifest plus the invalid ones.
		 *
		 * @return WP_REST_Response
		 */
		public static function index(): WP_REST_Response {
			$toolkits = array();

			foreach ( WP_MCP_AI_Pro_Toolkit_Manifests::manifests() as $manifest ) {
				$toolkits[] = self::summary( $manifest );
			}

			return self::ok(
				array(
					'toolkits' => $toolkits,
					'invalid'  => WP_MCP_AI_Pro_Toolkit_Manifests::errors(),
				)
			);
		}

		/**
		 * One manifest document.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function show( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			return self::ok( array( 'toolkit' => $manifest->toArray() ) );
		}

		/**
		 * Settings schema + current values.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function get_settings( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			return self::ok(
				array(
					'slug'   => $manifest->slug(),
					'schema' => $manifest->settings(),
					'values' => WP_MCP_AI_Pro_Toolkit_Manifests::settings( $manifest ),
				)
			);
		}

		/**
		 * Persist settings values.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$values = $request->get_param( 'values' );

			if ( ! is_array( $values ) ) {
				return self::error( 'invalid_payload', __( 'Send `values` as an object.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			list( $errors, $stored ) = WP_MCP_AI_Pro_Toolkit_Manifests::save_settings( $manifest, $values );

			if ( array() !== $errors ) {
				return self::error( 'validation_failed', __( 'Some settings are invalid.', 'mcp-ai-wpoos-pro' ), 422, array( 'fields' => $errors ) );
			}

			return self::ok( array( 'values' => $stored ) );
		}

		/**
		 * Dashboard aggregates.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function dashboard( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			return self::ok( WP_MCP_AI_Pro_Toolkit_Records::dashboard( $manifest ) );
		}

		/**
		 * Draft one record from a natural-language query.
		 *
		 * The draft is returned for review and never written here: the console
		 * saves it through the create endpoint, so the manifest's column
		 * validation stays the single gate into storage.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function research( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$entity_key = (string) $request->get_param( 'entity' );
			$query      = trim( (string) $request->get_param( 'query' ) );

			if ( '' === $entity_key || '' === $query ) {
				return self::error( 'validation_failed', __( '`entity` and `query` are required.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			if ( mb_strlen( $query ) > 2000 ) {
				return self::error( 'validation_failed', __( '`query` is limited to 2000 characters.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			$entity = $manifest->entity( $entity_key );

			if ( null === $entity ) {
				return self::error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			if ( ! function_exists( 'wp_mcp_ai_oos_provider_router' ) ) {
				return self::error( 'ai_unavailable', __( 'No AI provider is configured for this runtime.', 'mcp-ai-wpoos-pro' ), 503 );
			}

			$service = new Nvoos\Core\Application\Toolkit\ToolkitResearchService( wp_mcp_ai_oos_provider_router() );
			$result  = $service->draft( $manifest, $entity, $query );

			if ( null !== $result['error'] ) {
				$status = 'ai_unavailable' === $result['error'] ? 503 : 502;

				return self::error( $result['error'], (string) $result['message'], $status );
			}

			return self::ok(
				array(
					'entity'   => $entity_key,
					'draft'    => $result['draft'],
					'provider' => $result['provider'],
				)
			);
		}

		/**
		 * One page of records.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function index_entity( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$entity = (string) $request['entity'];

			if ( null === $manifest->entity( $entity ) ) {
				return self::error( 'entity_not_found', __( 'Unknown entity.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			$filters = $request->get_param( 'filter' );

			return self::ok(
				array_merge(
					WP_MCP_AI_Pro_Toolkit_Records::query(
						$manifest,
						$entity,
						array(
							'page'     => (int) $request->get_param( 'page' ),
							'per_page' => (int) $request->get_param( 'per_page' ),
							'search'   => (string) $request->get_param( 'search' ),
							'filter'   => is_array( $filters ) ? $filters : array(),
						)
					),
					// The shared console reads `entity` from the page envelope.
					array( 'entity' => $entity )
				)
			);
		}

		/**
		 * Create a record.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function create_entity( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$data = $request->get_param( 'data' );

			if ( ! is_array( $data ) ) {
				return self::error( 'invalid_payload', __( 'Send the record as a `data` object.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			$record = WP_MCP_AI_Pro_Toolkit_Records::create( $manifest, (string) $request['entity'], $data );

			if ( is_wp_error( $record ) ) {
				return self::from_wp_error( $record );
			}

			return self::ok( array( 'record' => $record ), 201 );
		}

		/**
		 * One record.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function show_entity( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$record = WP_MCP_AI_Pro_Toolkit_Records::find( $manifest, (string) $request['entity'], (string) $request['id'] );

			if ( null === $record ) {
				return self::error( 'record_not_found', __( 'Record not found.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			return self::ok( array( 'record' => $record ) );
		}

		/**
		 * Patch a record.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function update_entity( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$data = $request->get_param( 'data' );

			if ( ! is_array( $data ) ) {
				return self::error( 'invalid_payload', __( 'Send the record as a `data` object.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			$record = WP_MCP_AI_Pro_Toolkit_Records::update( $manifest, (string) $request['entity'], (string) $request['id'], $data );

			if ( is_wp_error( $record ) ) {
				return self::from_wp_error( $record );
			}

			return self::ok( array( 'record' => $record ) );
		}

		/**
		 * Delete a record.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function delete_entity( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$deleted = WP_MCP_AI_Pro_Toolkit_Records::delete( $manifest, (string) $request['entity'], (string) $request['id'] );

			if ( ! $deleted ) {
				return self::error( 'record_not_found', __( 'Record not found.', 'mcp-ai-wpoos-pro' ), 404 );
			}

			return self::ok( array( 'deleted' => true ) );
		}

		/**
		 * Server-side duplicate scan.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function duplicates( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$result = WP_MCP_AI_Pro_Toolkit_Records::duplicates(
				$manifest,
				(string) $request['entity'],
				(string) $request['column'],
				(int) $request->get_param( 'limit' )
			);

			if ( is_wp_error( $result ) ) {
				return self::from_wp_error( $result );
			}

			return self::ok( $result );
		}

		/**
		 * Bulk create, with an optional dry run.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function import( WP_REST_Request $request ): WP_REST_Response {
			list( $manifest, $error ) = self::resolve( (string) $request['slug'] );

			if ( null !== $error ) {
				return $error;
			}

			$rows = $request->get_param( 'rows' );

			if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
				return self::error( 'invalid_payload', __( 'Send `rows` as a list of record objects.', 'mcp-ai-wpoos-pro' ), 422 );
			}

			$result = WP_MCP_AI_Pro_Toolkit_Records::import(
				$manifest,
				(string) $request['entity'],
				$rows,
				(bool) $request->get_param( 'preview' ),
				null === $request->get_param( 'skip_existing' ) || (bool) $request->get_param( 'skip_existing' )
			);

			if ( is_wp_error( $result ) ) {
				return self::from_wp_error( $result );
			}

			return self::ok( $result );
		}

		// ------------------------------------------------------------ helpers

		/**
		 * Resolve a manifest or build the 404 envelope.
		 *
		 * @param string $slug Toolkit slug.
		 * @return array{0: ToolkitManifest|null, 1: WP_REST_Response|null}
		 */
		private static function resolve( string $slug ): array {
			$manifest = WP_MCP_AI_Pro_Toolkit_Manifests::find( $slug );

			if ( null === $manifest ) {
				return array(
					null,
					self::error(
						'toolkit_not_found',
						/* translators: %s: toolkit slug. */
						sprintf( __( 'Unknown toolkit "%s".', 'mcp-ai-wpoos-pro' ), $slug ),
						404
					),
				);
			}

			return array( $manifest, null );
		}

		/**
		 * Catalogue row for one manifest.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @return array<string, mixed>
		 */
		private static function summary( ToolkitManifest $manifest ): array {
			$raw = $manifest->toArray();

			return array(
				'slug'        => $manifest->slug(),
				'name'        => $manifest->name(),
				'version'     => (string) ( $raw['version'] ?? '' ),
				'description' => (string) ( $raw['description'] ?? '' ),
				'icon'        => (string) ( $raw['icon'] ?? '' ),
				'feature'     => $manifest->feature(),
				'screens'     => $manifest->screens(),
				'entities'    => array_map(
					static function ( array $entity ): array {
						return array(
							'key'   => (string) ( $entity['key'] ?? '' ),
							'label' => (string) ( $entity['label'] ?? '' ),
							'icon'  => (string) ( $entity['icon'] ?? '' ),
						);
					},
					$manifest->entities()
				),
			);
		}

		/**
		 * Route args for routes addressed by slug.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function slug_args(): array {
			return array(
				'slug' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			);
		}

		/**
		 * Route args for routes addressed by slug + entity.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function entity_args(): array {
			return array_merge(
				self::slug_args(),
				array(
					'entity' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				)
			);
		}

		/**
		 * Route args for routes addressed by slug + entity + id.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function record_args(): array {
			return array_merge(
				self::entity_args(),
				array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				)
			);
		}

		/**
		 * Success envelope.
		 *
		 * @param array<string, mixed> $data   Payload.
		 * @param int                  $status HTTP status.
		 * @return WP_REST_Response
		 */
		private static function ok( array $data, int $status = 200 ): WP_REST_Response {
			return self::envelope( true, 'data', $data, $status );
		}

		/**
		 * Error envelope.
		 *
		 * @param string               $code    Machine code.
		 * @param string               $message Human message.
		 * @param int                  $status  HTTP status.
		 * @param array<string, mixed> $extra   Extra error keys (e.g. fields).
		 * @return WP_REST_Response
		 */
		private static function error( string $code, string $message, int $status = 400, array $extra = array() ): WP_REST_Response {
			return self::envelope(
				false,
				'error',
				array_merge(
					array(
						'code'    => $code,
						'message' => $message,
					),
					$extra
				),
				$status
			);
		}

		/**
		 * Shared envelope builder.
		 *
		 * Kept as one place so the success and failure shapes cannot drift; the
		 * console's client reads exactly `data` / `error`.
		 *
		 * @param bool                 $success Whether the call succeeded.
		 * @param string               $key     `data` or `error`.
		 * @param array<string, mixed> $payload Payload.
		 * @param int                  $status  HTTP status.
		 * @return WP_REST_Response
		 */
		private static function envelope( bool $success, string $key, array $payload, int $status ): WP_REST_Response {
			return new WP_REST_Response(
				array(
					'success' => $success,
					$key      => $payload,
				),
				$status
			);
		}

		/**
		 * Map a repository WP_Error onto the envelope.
		 *
		 * @param WP_Error $error Error.
		 * @return WP_REST_Response
		 */
		private static function from_wp_error( WP_Error $error ): WP_REST_Response {
			$data   = $error->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			$extra  = array();

			if ( is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
				$extra['fields'] = $data['fields'];
			}

			return self::error( (string) $error->get_error_code(), $error->get_error_message(), $status, $extra );
		}
	}
}
