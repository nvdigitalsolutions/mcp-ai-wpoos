<?php
/**
 * Managers REST controller — manager grants for the Astro CMS.
 *
 * Serves `/funiq/v1/managers` and (aliased) `/nvoos-commerce/v1/managers`.
 *
 * The CMS /manage surface is gated by the `manage_astro` capability
 * (Schema::CAP_MANAGE), which activation grants to the administrator and
 * editor roles. This controller lets an existing manager promote and demote
 * users from the CMS itself using user-level capability grants — role
 * capabilities are never touched, so WordPress role management remains the
 * source of truth for site admins. Three guardrails protect the store:
 *
 * 1. Super admins (multisite network admins; on single site, users with
 *    `delete_users`) can never be modified from the CMS — site ownership
 *    stays in wp-admin. Extendable via `funiq_bridge_managers_protected_user`.
 * 2. A manager cannot remove their own access (the last-manager lockout
 *    vector: any non-self demotion always leaves at least the actor with
 *    access, so the self guard is the only one needed).
 * 3. Demoting a manager without the cap is a no-op write (idempotent), so
 *    repeated UI clicks stay safe.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Manager promotion/demotion surface for the Astro CMS.
 */
class ManagersController extends BaseController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'managers';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'search' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'   => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'limit'  => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'type'              => 'integer',
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'manage' => array(
							'required'          => true,
							'type'              => 'boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /managers — current managers, or user candidates when `search` is
	 * set (the UI's "add a manager" picker needs to offer non-managers too).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$search = (string) $request->get_param( 'search' );
		$page   = (int) $request->get_param( 'page' );
		$limit  = (int) $request->get_param( 'limit' );

		// The search arg's default (empty string) would violate a schema-level
		// minLength on every request, so the minimum is enforced here.
		if ( '' !== $search && mb_strlen( $search ) < 2 ) {
			return new WP_Error(
				'rest_invalid_param',
				'The search term must be at least 2 characters.',
				array(
					'status' => 400,
					'param'  => 'search',
				)
			);
		}

		if ( '' !== $search ) {
			$candidates = get_users(
				array(
					'search'  => '*' . $search . '*',
					'number'  => 20,
					'orderby' => 'user_registered',
					'order'   => 'DESC',
				)
			);

			$docs = array();
			foreach ( $candidates as $candidate ) {
				$docs[] = $this->user_doc( $candidate );
			}

			return rest_ensure_response(
				$this->paginatedResponse( $docs, count( $docs ), 1, count( $docs ) )
			);
		}

		$manager_ids = $this->manager_ids();
		$total       = count( $manager_ids );
		$slice       = array_slice( $manager_ids, ( $page - 1 ) * $limit, $limit );

		$docs = array();
		foreach ( $slice as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$docs[] = $this->user_doc( $user );
			}
		}

		return rest_ensure_response( $this->paginatedResponse( $docs, $total, $page, $limit ) );
	}

	/**
	 * PUT /managers/{id} — promote ({manage:true}) or demote ({manage:false}).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id     = (int) $request['id'];
		$manage = (bool) $request->get_param( 'manage' );

		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error(
				'funiq_managers_not_found',
				'User not found.',
				array( 'status' => 404 )
			);
		}

		if ( $this->is_protected( $user->ID ) ) {
			return new WP_Error(
				'funiq_managers_protected',
				'This user manages the site and cannot be changed from the CMS.',
				array( 'status' => 400 )
			);
		}

		if ( $manage ) {
			$user->add_cap( Schema::CAP_MANAGE );
		} else {
			if ( get_current_user_id() === $user->ID ) {
				return new WP_Error(
					'funiq_managers_self',
					'You cannot remove your own manager access.',
					array( 'status' => 400 )
				);
			}
			$user->remove_cap( Schema::CAP_MANAGE );
		}

		$updated = get_userdata( $id );

		return rest_ensure_response( $this->user_doc( $updated ) );
	}

	/**
	 * Whether the CMS may not modify this user (site owners stay in wp-admin).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	private function is_protected( int $user_id ): bool {
		$protected = is_super_admin( $user_id );

		/**
		 * Filters whether the Astro CMS may modify a user's manager status.
		 *
		 * @param bool $protected Whether the user is protected (default: super admin).
		 * @param int  $user_id   WordPress user ID.
		 */
		return (bool) apply_filters( 'funiq_bridge_managers_protected_user', $protected, $user_id );
	}

	/**
	 * User IDs that currently hold the manager capability.
	 *
	 * @return array<int, int>
	 */
	private function manager_ids(): array {
		$ids   = get_users(
			array(
				'fields' => 'ID',
				'number' => 500,
			)
		);
		$found = array();

		foreach ( $ids as $raw_id ) {
			$user_id = (int) $raw_id;
			// phpcs:ignore WordPress.WP.Capabilities -- Capability slug lives in Schema::CAP_MANAGE.
			if ( user_can( $user_id, Schema::CAP_MANAGE ) ) {
				$found[] = $user_id;
			}
		}

		return $found;
	}

	/**
	 * Build a manager document for a WordPress user.
	 *
	 * @param \WP_User $user WordPress user object.
	 * @return array<string, mixed>
	 */
	private function user_doc( $user ): array {
		return array(
			'id'        => (int) $user->ID,
			'email'     => $user->user_email,
			'name'      => ( '' !== $user->display_name ) ? $user->display_name : $user->user_login,
			// phpcs:ignore WordPress.WP.Capabilities -- Capability slug lives in Schema::CAP_MANAGE.
			'isManager' => user_can( $user->ID, Schema::CAP_MANAGE ),
			'protected' => $this->is_protected( $user->ID ),
			'isSelf'    => get_current_user_id() === $user->ID,
		);
	}
}
