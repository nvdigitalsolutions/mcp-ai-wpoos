<?php
/**
 * Org-team service (users with roles).
 *
 * The WordPress twin of the core `TeamServiceInterface` implementation on the
 * Laravel runtime (090-g Phase 3, WordPress parity 090-p W7). Same envelopes,
 * same invariants: the creator becomes owner, the last owner cannot be removed
 * or demoted, and a slug is unique per site.
 *
 * Storage is the non-public `mcp_ai_org_team` CPT plus one membership meta
 * entry per team — no custom table, and one writer for memberships so it cannot
 * drift between stores. Tenant scoping is the site (multisite: the current
 * blog), which is how every other CPT-backed surface behaves.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Org-team storage and membership rules.
 */
class WP_MCP_AI_Org_Team_Service {

	/**
	 * The constrained role set, matching the Laravel enum.
	 *
	 * @var string[]
	 */
	const ROLES = array( 'owner', 'admin', 'manager', 'member', 'viewer' );

	/**
	 * Abilities a role grants.
	 *
	 * @var array<string, string[]>
	 */
	const ABILITIES = array(
		'manage_team'         => array( 'owner', 'admin' ),
		'delete_team'         => array( 'owner' ),
		'manage_team_members' => array( 'owner', 'admin', 'manager' ),
	);

	/**
	 * Teams the user belongs to.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{id: int, name: string, slug: string, role: string, member_count: int}>
	 */
	public function list_for_user( $user_id ) {
		$user_id = absint( $user_id );
		$posts   = get_posts(
			array(
				'post_type'        => WP_MCP_AI_Org_Team_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$teams = array();

		foreach ( $posts as $post ) {
			$role = $this->role_for( $user_id, $post->ID );

			// Membership is the list criterion, matching the Laravel runtime.
			// A site administrator can open a team by id (see allows()), but the
			// list does not advertise teams they have not joined.
			if ( null === $role ) {
				continue;
			}

			$members = $this->members( $post->ID );

			$teams[] = array(
				'id'           => (int) $post->ID,
				'name'         => get_the_title( $post ),
				'slug'         => (string) $post->post_name,
				'role'         => $role,
				'member_count' => count( $members ),
			);
		}

		return $teams;
	}

	/**
	 * A team with its members.
	 *
	 * @param int $team_id Team post ID.
	 * @return array{found: bool, team?: array<string, mixed>}
	 */
	public function get( $team_id ) {
		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array( 'found' => false );
		}

		return array(
			'found' => true,
			'team'  => $this->team_record( $post, true ),
		);
	}

	/**
	 * Create a team; the creator becomes its owner.
	 *
	 * @param array{name: string, slug?: string, description?: string|null, owner_id: int} $attributes Team attributes.
	 * @return array{created: bool, team?: array<string, mixed>, error?: string}
	 */
	public function create( $attributes ) {
		$name  = trim( (string) ( $attributes['name'] ?? '' ) );
		$owner = absint( $attributes['owner_id'] ?? 0 );

		if ( '' === $name ) {
			return array(
				'created' => false,
				'error'   => 'A team name is required.',
			);
		}

		if ( $owner <= 0 ) {
			return array(
				'created' => false,
				'error'   => 'An owner is required.',
			);
		}

		$slug_input = trim( (string) ( $attributes['slug'] ?? '' ) );
		$slug_input = '' !== $slug_input ? $slug_input : $name;

		$post_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_Org_Team_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_name'    => $this->unique_slug( $slug_input ),
				'post_content' => (string) ( $attributes['description'] ?? '' ),
				'post_author'  => $owner,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array(
				'created' => false,
				'error'   => $post_id->get_error_message(),
			);
		}

		update_post_meta(
			$post_id,
			WP_MCP_AI_Org_Team_CPT::META_MEMBERS,
			array(
				array(
					'user_id'         => $owner,
					'role'            => 'owner',
					'profession_slug' => null,
					'invited_by'      => null,
					'joined_at'       => gmdate( 'c' ),
				),
			)
		);

		$team = $this->get( $post_id );

		return array(
			'created' => true,
			'team'    => $team['team'],
		);
	}

	/**
	 * Update mutable attributes.
	 *
	 * @param int                  $team_id    Team post ID.
	 * @param array<string, mixed> $attributes Partial attributes (name, slug, description).
	 * @return array{updated: bool, team?: array<string, mixed>, error?: string}
	 */
	public function update( $team_id, $attributes ) {
		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array(
				'updated' => false,
				'error'   => 'Team not found.',
			);
		}

		$args = array( 'ID' => $post->ID );

		if ( array_key_exists( 'name', $attributes ) ) {
			$name = trim( (string) $attributes['name'] );

			if ( '' === $name ) {
				return array(
					'updated' => false,
					'error'   => 'A team name is required.',
				);
			}

			$args['post_title'] = $name;
		}

		if ( array_key_exists( 'description', $attributes ) ) {
			$args['post_content'] = (string) $attributes['description'];
		}

		if ( ! empty( $attributes['slug'] ) ) {
			$args['post_name'] = $this->unique_slug( (string) $attributes['slug'], (int) $post->ID );
		}

		$result = wp_update_post( $args, true );

		if ( is_wp_error( $result ) ) {
			return array(
				'updated' => false,
				'error'   => $result->get_error_message(),
			);
		}

		$team = $this->get( $post->ID );

		return array(
			'updated' => true,
			'team'    => $team['team'],
		);
	}

	/**
	 * Add a member, or update the role of an existing one.
	 *
	 * @param int         $team_id        Team post ID.
	 * @param int         $user_id        User ID.
	 * @param string      $role           One of owner, admin, manager, member, viewer.
	 * @param string|null $profession_slug Profession catalogue slug, or null.
	 * @param int         $invited_by     Acting user ID (0 = unknown).
	 * @return array{added: bool, member?: array<string, mixed>, error?: string}
	 */
	public function add_member( $team_id, $user_id, $role = 'member', $profession_slug = null, $invited_by = 0 ) {
		$role = (string) $role;

		if ( ! in_array( $role, self::ROLES, true ) ) {
			return array(
				'added' => false,
				'error' => 'Unknown role.',
			);
		}

		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array(
				'added' => false,
				'error' => 'Team not found.',
			);
		}

		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return array(
				'added' => false,
				'error' => 'A user is required.',
			);
		}

		$members = $this->members( $post->ID );

		foreach ( $members as $member ) {
			if ( (int) $member['user_id'] !== $user_id ) {
				continue;
			}

			// Re-adding is a role change; delegate so the last-owner rule holds.
			$change = $this->change_role( $post->ID, $user_id, $role );

			if ( ! $change['updated'] ) {
				return array(
					'added' => false,
					'error' => $change['error'],
				);
			}

			return array(
				'added'  => false,
				'member' => $change['member'],
				'error'  => 'User is already a member.',
			);
		}

		$slug = ( null === $profession_slug || '' === $profession_slug ) ? null : (string) $profession_slug;

		$entry = array(
			'user_id'         => $user_id,
			'role'            => $role,
			'profession_slug' => $slug,
			'invited_by'      => absint( $invited_by ) > 0 ? absint( $invited_by ) : null,
			'joined_at'       => gmdate( 'c' ),
		);

		$members[] = $entry;
		update_post_meta( $post->ID, WP_MCP_AI_Org_Team_CPT::META_MEMBERS, $members );

		return array(
			'added'  => true,
			'member' => $this->member_record( $entry, $post->ID ),
		);
	}

	/**
	 * Remove a member. The last owner cannot be removed.
	 *
	 * @param int $team_id Team post ID.
	 * @param int $user_id User ID.
	 * @return array{removed: bool, error?: string}
	 */
	public function remove_member( $team_id, $user_id ) {
		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array(
				'removed' => false,
				'error'   => 'Team not found.',
			);
		}

		$user_id = absint( $user_id );
		$members = $this->members( $post->ID );
		$index   = null;

		foreach ( $members as $position => $member ) {
			if ( (int) $member['user_id'] === $user_id ) {
				$index = $position;
				break;
			}
		}

		if ( null === $index ) {
			return array(
				'removed' => false,
				'error'   => 'Member not found.',
			);
		}

		if ( 'owner' === $members[ $index ]['role'] && $this->owner_count( $members ) <= 1 ) {
			return array(
				'removed' => false,
				'error'   => 'A team must keep at least one owner.',
			);
		}

		array_splice( $members, $index, 1 );
		update_post_meta( $post->ID, WP_MCP_AI_Org_Team_CPT::META_MEMBERS, $members );

		return array( 'removed' => true );
	}

	/**
	 * Change a member's role. The last owner cannot be demoted.
	 *
	 * @param int    $team_id Team post ID.
	 * @param int    $user_id User ID.
	 * @param string $role    One of owner, admin, manager, member, viewer.
	 * @return array{updated: bool, member?: array<string, mixed>, error?: string}
	 */
	public function change_role( $team_id, $user_id, $role ) {
		$role = (string) $role;

		if ( ! in_array( $role, self::ROLES, true ) ) {
			return array(
				'updated' => false,
				'error'   => 'Unknown role.',
			);
		}

		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array(
				'updated' => false,
				'error'   => 'Team not found.',
			);
		}

		$user_id = absint( $user_id );
		$members = $this->members( $post->ID );

		foreach ( $members as $position => $member ) {
			if ( (int) $member['user_id'] !== $user_id ) {
				continue;
			}

			if ( 'owner' === $member['role'] && 'owner' !== $role && $this->owner_count( $members ) <= 1 ) {
				return array(
					'updated' => false,
					'error'   => 'A team must keep at least one owner.',
				);
			}

			$members[ $position ]['role'] = $role;
			update_post_meta( $post->ID, WP_MCP_AI_Org_Team_CPT::META_MEMBERS, $members );

			return array(
				'updated' => true,
				'member'  => $this->member_record( $members[ $position ], $post->ID ),
			);
		}

		return array(
			'updated' => false,
			'error'   => 'Member not found.',
		);
	}

	/**
	 * Delete a team.
	 *
	 * @param int $team_id Team post ID.
	 * @return array{deleted: bool, error?: string}
	 */
	public function delete( $team_id ) {
		$post = $this->find( $team_id );

		if ( ! $post ) {
			return array(
				'deleted' => false,
				'error'   => 'Team not found.',
			);
		}

		wp_delete_post( $post->ID, true );

		return array( 'deleted' => true );
	}

	/**
	 * The membership role of a user on a team, or null when not a member.
	 *
	 * @param int $user_id User ID.
	 * @param int $team_id Team post ID.
	 * @return string|null
	 */
	public function role_for( $user_id, $team_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return null;
		}

		foreach ( $this->members( $team_id ) as $member ) {
			if ( (int) $member['user_id'] === $user_id ) {
				return (string) $member['role'];
			}
		}

		return null;
	}

	/**
	 * Whether the user may perform an ability on a team.
	 *
	 * Site administrators override every check (the same escape hatch the
	 * Laravel `TeamAccess` helper provides).
	 *
	 * @param int    $user_id User ID.
	 * @param int    $team_id Team post ID.
	 * @param string $ability One of manage_team, delete_team, manage_team_members.
	 * @return bool
	 */
	public function allows( $user_id, $team_id, $ability ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$role = $this->role_for( $user_id, $team_id );

		if ( null === $role ) {
			return false;
		}

		return in_array( $role, self::ABILITIES[ $ability ] ?? array(), true );
	}

	/**
	 * Find a published team post.
	 *
	 * @param int $team_id Team post ID.
	 * @return WP_Post|null
	 */
	private function find( $team_id ) {
		$post = get_post( absint( $team_id ) );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( WP_MCP_AI_Org_Team_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * Normalised membership list.
	 *
	 * @param int $team_id Team post ID.
	 * @return array<int, array{user_id: int, role: string, profession_slug: string|null, invited_by: int|null, joined_at: string}>
	 */
	private function members( $team_id ) {
		$raw = get_post_meta( absint( $team_id ), WP_MCP_AI_Org_Team_CPT::META_MEMBERS, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$members = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$role = (string) ( $entry['role'] ?? 'member' );

			$members[] = array(
				'user_id'         => absint( $entry['user_id'] ?? 0 ),
				'role'            => in_array( $role, self::ROLES, true ) ? $role : 'member',
				'profession_slug' => ! empty( $entry['profession_slug'] ) ? (string) $entry['profession_slug'] : null,
				'invited_by'      => ! empty( $entry['invited_by'] ) ? absint( $entry['invited_by'] ) : null,
				'joined_at'       => (string) ( $entry['joined_at'] ?? '' ),
			);
		}

		return $members;
	}

	/**
	 * Count owners in a membership list.
	 *
	 * @param array<int, array{role: string}> $members Normalised members.
	 * @return int
	 */
	private function owner_count( $members ) {
		$owners = 0;

		foreach ( $members as $member ) {
			if ( 'owner' === $member['role'] ) {
				++$owners;
			}
		}

		return $owners;
	}

	/**
	 * The console's team record shape.
	 *
	 * @param WP_Post $post         Team post.
	 * @param bool    $with_members Whether to include the member list.
	 * @return array<string, mixed>
	 */
	private function team_record( $post, $with_members = false ) {
		$members = $this->members( $post->ID );

		$record = array(
			'id'           => (int) $post->ID,
			'name'         => get_the_title( $post ),
			'slug'         => (string) $post->post_name,
			'description'  => '' !== $post->post_content ? (string) $post->post_content : null,
			'member_count' => count( $members ),
			'created_at'   => (string) get_post_time( 'c', true, $post ),
			'updated_at'   => (string) get_post_modified_time( 'c', true, $post ),
		);

		if ( $with_members ) {
			$record['members'] = array_map(
				function ( $member ) use ( $post ) {
					return $this->member_record( $member, $post->ID );
				},
				$members
			);
		}

		return $record;
	}

	/**
	 * The console's member record shape.
	 *
	 * `id` is the user ID: membership rows have no identity of their own in a
	 * meta list, and (team, user) is unique by construction.
	 *
	 * @param array{user_id: int, role: string, profession_slug: string|null, invited_by: int|null, joined_at: string} $member Normalised member.
	 * @param int                                                                                                      $team_id Team post ID.
	 * @return array<string, mixed>
	 */
	private function member_record( $member, $team_id ) {
		$user = get_userdata( (int) $member['user_id'] );

		return array(
			'id'              => (int) $member['user_id'],
			'team_id'         => (int) $team_id,
			'user_id'         => (int) $member['user_id'],
			'role'            => (string) $member['role'],
			'profession_slug' => $member['profession_slug'],
			'invited_by'      => $member['invited_by'],
			'joined_at'       => (string) $member['joined_at'],
			'name'            => $user ? (string) $user->display_name : '',
			'email'           => $user ? (string) $user->user_email : '',
		);
	}

	/**
	 * A slug that is unique within the post type.
	 *
	 * @param string $value     Desired slug (name, usually).
	 * @param int    $ignore_id Team post ID to ignore when checking.
	 * @return string
	 */
	private function unique_slug( $value, $ignore_id = 0 ) {
		$base = sanitize_title( $value );

		if ( '' === $base ) {
			$base = 'team';
		}

		$slug   = $base;
		$suffix = 2;

		while ( true ) {
			$existing = get_page_by_path( $slug, OBJECT, WP_MCP_AI_Org_Team_CPT::POST_TYPE );

			if ( ! $existing || absint( $ignore_id ) === (int) $existing->ID ) {
				return $slug;
			}

			$slug = $base . '-' . $suffix;
			++$suffix;
		}
	}
}
