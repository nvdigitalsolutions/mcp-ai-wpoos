<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamMember;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;

/**
 * Laravel implementation of the core team contract (090-g Phase 3).
 *
 * Tenancy: every query is scoped to TenantContext::current(). The invariant
 * "a team keeps at least one owner" is enforced here rather than in the
 * schema — MySQL cannot express "at least one row in a child table" as a
 * constraint, and a check that depends on the rest of the table would race
 * under concurrent writes without a lock.
 */
class LaravelTeamService implements TeamServiceInterface
{
    public function listForUser(int $userId): array
    {
        return Team::query()
            ->where('tenant_id', TenantContext::current())
            ->whereHas('members', static fn ($query) => $query->where('user_id', $userId))
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(static function (Team $team) use ($userId): array {
                $role = $team->members()->where('user_id', $userId)->value('role');

                return [
                    'id' => (int) $team->id,
                    'name' => (string) $team->name,
                    'slug' => (string) $team->slug,
                    'role' => (string) ($role ?? 'member'),
                    'member_count' => (int) $team->members_count,
                ];
            })
            ->all();
    }

    public function get(int $teamId): array
    {
        $team = $this->find($teamId);

        if (null === $team) {
            return ['found' => false];
        }

        $team->loadCount('members');
        $team->load(['members.user']);

        return [
            'found' => true,
            'team' => array_merge($team->toRecord(), [
                'members' => $team->members
                    ->map(fn (TeamMember $member): array => $this->memberRecord($member))
                    ->values()
                    ->all(),
            ]),
        ];
    }

    public function create(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $ownerId = (int) ($attributes['owner_id'] ?? 0);

        if ('' === $name) {
            return ['created' => false, 'error' => 'A team name is required.'];
        }

        if ($ownerId <= 0) {
            return ['created' => false, 'error' => 'An owner is required.'];
        }

        $team = DB::transaction(function () use ($attributes, $name, $ownerId): Team {
            $team = new Team([
                'name' => $name,
                'slug' => $this->uniqueSlug((string) ($attributes['slug'] ?? $name)),
                'description' => $attributes['description'] ?? null,
            ]);
            $team->tenant_id = TenantContext::current();
            $team->save();

            $member = new TeamMember([
                'team_id' => $team->id,
                'user_id' => $ownerId,
                'role' => 'owner',
                'joined_at' => now(),
            ]);
            $member->tenant_id = TenantContext::current();
            $member->save();

            return $team;
        });

        return ['created' => true, 'team' => $this->get((int) $team->id)['team']];
    }

    public function update(int $teamId, array $attributes): array
    {
        $team = $this->find($teamId);

        if (null === $team) {
            return ['updated' => false, 'error' => 'Team not found.'];
        }

        if (array_key_exists('name', $attributes)) {
            $name = trim((string) $attributes['name']);

            if ('' === $name) {
                return ['updated' => false, 'error' => 'A team name is required.'];
            }

            $team->name = $name;
        }

        if (array_key_exists('description', $attributes)) {
            $team->description = $attributes['description'];
        }

        if (! empty($attributes['slug'])) {
            $team->slug = $this->uniqueSlug((string) $attributes['slug'], (int) $team->id);
        }

        $team->save();

        return ['updated' => true, 'team' => $this->get((int) $team->id)['team']];
    }

    public function addMember(int $teamId, int $userId, string $role = 'member', ?string $professionSlug = null, int $invitedBy = 0): array
    {
        if (! in_array($role, TeamMember::ROLES, true)) {
            return ['added' => false, 'error' => 'Unknown role.'];
        }

        $team = $this->find($teamId);

        if (null === $team) {
            return ['added' => false, 'error' => 'Team not found.'];
        }

        if ($userId <= 0) {
            return ['added' => false, 'error' => 'A user is required.'];
        }

        $member = TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $userId)
            ->first();

        if (null !== $member) {
            // Re-adding is a role change; delegate so the last-owner rule holds.
            $result = $this->changeRole((int) $team->id, $userId, $role);

            if (! $result['updated']) {
                return ['added' => false, 'error' => $result['error'] ?? 'Member not updated.'];
            }

            return [
                'added' => false,
                'member' => $this->memberRecord($member->fresh()),
                'error' => 'User is already a member.',
            ];
        }

        $member = new TeamMember([
            'team_id' => $team->id,
            'user_id' => $userId,
            'role' => $role,
            'profession_slug' => $professionSlug,
            'invited_by' => $invitedBy > 0 ? $invitedBy : null,
            'joined_at' => now(),
        ]);
        $member->tenant_id = TenantContext::current();
        $member->save();

        return ['added' => true, 'member' => $this->memberRecord($member->fresh())];
    }

    public function removeMember(int $teamId, int $userId): array
    {
        $team = $this->find($teamId);

        if (null === $team) {
            return ['removed' => false, 'error' => 'Team not found.'];
        }

        $member = TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $userId)
            ->first();

        if (null === $member) {
            return ['removed' => false, 'error' => 'Member not found.'];
        }

        if ('owner' === $member->role && $this->ownerCount((int) $team->id) <= 1) {
            return ['removed' => false, 'error' => 'A team must keep at least one owner.'];
        }

        $member->delete();

        return ['removed' => true];
    }

    public function changeRole(int $teamId, int $userId, string $role): array
    {
        if (! in_array($role, TeamMember::ROLES, true)) {
            return ['updated' => false, 'error' => 'Unknown role.'];
        }

        $team = $this->find($teamId);

        if (null === $team) {
            return ['updated' => false, 'error' => 'Team not found.'];
        }

        $member = TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $userId)
            ->first();

        if (null === $member) {
            return ['updated' => false, 'error' => 'Member not found.'];
        }

        if ('owner' === $member->role && 'owner' !== $role && $this->ownerCount((int) $team->id) <= 1) {
            return ['updated' => false, 'error' => 'A team must keep at least one owner.'];
        }

        $member->role = $role;
        $member->save();

        return ['updated' => true, 'member' => $this->memberRecord($member->fresh())];
    }

    public function delete(int $teamId): array
    {
        $team = $this->find($teamId);

        if (null === $team) {
            return ['deleted' => false, 'error' => 'Team not found.'];
        }

        $team->delete();

        return ['deleted' => true];
    }

    private function find(int $teamId): ?Team
    {
        return Team::query()
            ->where('tenant_id', TenantContext::current())
            ->whereKey($teamId)
            ->first();
    }

    private function ownerCount(int $teamId): int
    {
        return TeamMember::query()
            ->where('team_id', $teamId)
            ->where('role', 'owner')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRecord(TeamMember $member): array
    {
        $record = $member->toRecord();

        if ($member->relationLoaded('user') && null !== $member->user) {
            $record['name'] = (string) $member->user->name;
            $record['email'] = (string) $member->user->email;
        }

        return $record;
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'team';
        $slug = $base;
        $suffix = 2;

        while (Team::query()
            ->where('tenant_id', TenantContext::current())
            ->where('slug', $slug)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
