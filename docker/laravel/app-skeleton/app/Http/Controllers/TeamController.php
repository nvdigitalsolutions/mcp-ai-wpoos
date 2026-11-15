<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Support\CapabilityMap;
use App\Support\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;

/**
 * Org teams and membership (090-g Phase 3, 090-b 2.5).
 *
 * Mounted at `/api/v1/org/teams`: `mcp-ai/v1/teams` is already the WordPress
 * agent-team surface (a collection of professions), so the org primitive —
 * users with roles — gets its own namespace rather than returning a different
 * shape at the same URL.
 *
 * All reads and writes go through TeamServiceInterface so the console talks to
 * WordPress and Laravel identically. Tenant scoping lives in the service; the
 * controller never accepts a tenant from the request.
 */
class TeamController
{
    public function __construct(private readonly TeamServiceInterface $teams)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $teams = $this->teams->listForUser((int) $request->user()->id);

        return response()->json([
            'teams' => $teams,
            'total' => count($teams),
        ]);
    }

    public function show(Request $request, string $team): JsonResponse
    {
        // Non-members get 404 rather than 403 so the API does not disclose
        // that a team id exists. Site administrators override.
        $this->guard($request, (int) $team);

        $result = $this->teams->get((int) $team);

        if (! $result['found']) {
            return response()->json(['success' => false, 'message' => 'Team not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $result['team']]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $result = $this->teams->create($data + ['owner_id' => (int) $request->user()->id]);

        if (! $result['created']) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Team not created.'], 422);
        }

        return response()->json($result['team'], 201);
    }

    public function update(Request $request, string $team): JsonResponse
    {
        $this->guard($request, (int) $team, CapabilityMap::ABILITY_MANAGE_TEAM);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $result = $this->teams->update((int) $team, $data);

        if (! $result['updated']) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Team not updated.'], 404);
        }

        return response()->json(['success' => true, 'data' => $result['team']]);
    }

    public function destroy(Request $request, string $team): Response
    {
        $this->guard($request, (int) $team, CapabilityMap::ABILITY_DELETE_TEAM);

        $result = $this->teams->delete((int) $team);

        return $result['deleted']
            ? response()->noContent()
            : response()->noContent(404);
    }

    public function storeMember(Request $request, string $team): JsonResponse
    {
        $this->guard($request, (int) $team, CapabilityMap::ABILITY_MANAGE_TEAM_MEMBERS);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', TeamMember::ROLES)],
            'profession_slug' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $result = $this->teams->addMember(
            (int) $team,
            (int) $data['user_id'],
            (string) ($data['role'] ?? 'member'),
            $data['profession_slug'] ?? null,
            (int) $request->user()->id,
        );

        if ($result['added']) {
            return response()->json($result['member'], 201);
        }

        // Re-adding an existing member is idempotent: the role was updated,
        // so report the membership rather than an error.
        $alreadyMember = 'User is already a member.' === ($result['error'] ?? '');

        return response()->json(
            [
                'success' => $alreadyMember,
                'message' => $result['error'] ?? 'Member not added.',
                'data' => $result['member'] ?? null,
            ],
            $alreadyMember ? 200 : 422,
        );
    }

    public function updateMember(Request $request, string $team, string $user): JsonResponse
    {
        $this->guard($request, (int) $team, CapabilityMap::ABILITY_MANAGE_TEAM_MEMBERS);

        $data = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', TeamMember::ROLES)],
        ]);

        $result = $this->teams->changeRole((int) $team, (int) $user, (string) $data['role']);

        if (! $result['updated']) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Member not updated.'], 422);
        }

        return response()->json(['success' => true, 'data' => $result['member']]);
    }

    public function destroyMember(Request $request, string $team, string $user): JsonResponse
    {
        $this->guard($request, (int) $team, CapabilityMap::ABILITY_MANAGE_TEAM_MEMBERS);

        $result = $this->teams->removeMember((int) $team, (int) $user);

        if (! $result['removed']) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Member not removed.'], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Enforce team-scoped authorization.
     *
     * Non-members get a 404 (no existence disclosure); members who lack the
     * ability get a 403. A site administrator passes both checks.
     */
    private function guard(Request $request, int $teamId, ?string $ability = null): void
    {
        $user = $request->user();

        if (! TeamAccess::isMember($user, $teamId)) {
            abort(404);
        }

        if ($ability !== null) {
            abort_unless(TeamAccess::allows($user, $teamId, $ability), 403);
        }
    }
}
