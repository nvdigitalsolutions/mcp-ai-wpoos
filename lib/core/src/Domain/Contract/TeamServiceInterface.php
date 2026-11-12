<?php
/**
 * Team Service — domain contract.
 *
 * Teams are an org primitive, not content: a tenant owns teams, a team owns
 * memberships, and a membership may carry a profession. The contract is
 * deliberately narrow so the console and tools consume either runtime the
 * same way; tenant resolution stays a runtime concern.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Team management and membership.
 *
 * @since 2.0.0
 */
interface TeamServiceInterface
{
    /**
     * List the teams a user belongs to.
     *
     * @param int $userId User ID.
     *
     * @return array<int, array{id: int, name: string, slug: string, role: string, member_count: int}>
     */
    public function listForUser(int $userId): array;

    /**
     * Get a team with its members.
     *
     * @param int $teamId Team ID.
     *
     * @return array{found: bool, team?: array<string, mixed>}
     */
    public function get(int $teamId): array;

    /**
     * Create a team. The creator becomes its owner.
     *
     * @param array{name: string, slug?: string, description?: string|null, owner_id: int} $attributes Team attributes.
     *
     * @return array{created: bool, team?: array<string, mixed>, error?: string}
     */
    public function create(array $attributes): array;

    /**
     * Update a team's mutable attributes.
     *
     * @param int                  $teamId     Team ID.
     * @param array<string, mixed> $attributes Partial attributes (name, description).
     *
     * @return array{updated: bool, team?: array<string, mixed>, error?: string}
     */
    public function update(int $teamId, array $attributes): array;

    /**
     * Add a member, or update the role of an existing one.
     *
     * @param int         $teamId        Team ID.
     * @param int         $userId        User ID.
     * @param string      $role          One of owner, admin, manager, member, viewer.
     * @param string|null $professionSlug Profession catalogue slug, or null.
     * @param int         $invitedBy     Acting user ID (0 = unknown).
     *
     * @return array{added: bool, member?: array<string, mixed>, error?: string}
     */
    public function addMember(int $teamId, int $userId, string $role = 'member', ?string $professionSlug = null, int $invitedBy = 0): array;

    /**
     * Remove a member. The last owner cannot be removed.
     *
     * @param int $teamId Team ID.
     * @param int $userId User ID.
     *
     * @return array{removed: bool, error?: string}
     */
    public function removeMember(int $teamId, int $userId): array;

    /**
     * Change a member's role. The last owner cannot be demoted.
     *
     * @param int    $teamId Team ID.
     * @param int    $userId User ID.
     * @param string $role   One of owner, admin, manager, member, viewer.
     *
     * @return array{updated: bool, member?: array<string, mixed>, error?: string}
     */
    public function changeRole(int $teamId, int $userId, string $role): array;

    /**
     * Delete a team. Membership rows are kept so a soft-deleted team can be
     * restored intact.
     *
     * @param int $teamId Team ID.
     *
     * @return array{deleted: bool, error?: string}
     */
    public function delete(int $teamId): array;
}
