# Org Teams

## Purpose

Owns the tenant's **people grouping** — teams of WordPress users with a role
inside the team (`owner` … `viewer`) — and nothing else. The agent-team
catalogue (`mcp-ai/v1/teams`, a collection of professions) is a different
primitive and lives elsewhere.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 8.1+ |
| **Loaded by** | `includes/bootstrap/loader.php` — CPT on `init`, controller on `rest_api_init` |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Org_Team_CPT` | `class-wp-mcp-ai-org-team-cpt.php` | service, controller, `WP_MCP_AI_Endpoint_Capabilities` |
| `WP_MCP_AI_Org_Team_Service` | `class-wp-mcp-ai-org-team-service.php` | controller, Pro SPA config, callers that need an ability check |
| `WP_MCP_AI_REST_Org_Teams_Controller` | `../rest/class-wp-mcp-ai-rest-org-teams-controller.php` | `loader.php` |

### `WP_MCP_AI_Org_Team_CPT`

Non-public (`public => false`) CPT `mcp_ai_org_team`. Membership is stored as
one meta row, `_wp_mcp_ai_org_team_members` (`array<user_id, array{role,
profession, added_at, invited_by}>`), rather than as a term or a custom table:
a team is tenant data, not an authorization primitive.

### `WP_MCP_AI_Org_Team_Service`

```php
$service = new WP_MCP_AI_Org_Team_Service();

$service->list_for_user( $user_id );                 // teams the user belongs to
$service->create( array( 'name' => 'Studio' ) );     // caller becomes owner
$service->add_member( $team_id, $user_id, 'manager' );
$service->allows( $user_id, $team_id, 'manage_members' ); // bool
```

Constants worth knowing:

| Constant | Value |
|---|---|
| `ROLES` | `owner`, `admin`, `manager`, `member`, `viewer` |
| `ABILITIES` | Ability name → minimum role (`view`, `manage_members`, `manage_team`, `delete_team`) |

Invariants enforced here, not in the controller:

- A team always has at least one `owner`; the last owner cannot be demoted or
  removed.
- Slugs are unique per site (`unique_slug()` suffixes `-2`, `-3`, …).
- `allows()` is the single authorization answer — the controller never
  re-implements the role ladder.

## Inputs / Outputs / Neighbors

- **Reads from:** the `mcp_ai_org_team` CPT and `_wp_mcp_ai_org_team_members`
  post meta.
- **Writes to:** the same two stores only. No options, no transients.
- **Upstream callers:** `includes/rest/class-wp-mcp-ai-rest-org-teams-controller.php`,
  `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` (localizes the
  route URL), `includes/class-wp-mcp-ai-endpoint-capabilities.php` (manifest
  key `orgTeams`).
- **Downstream collaborators:** `includes/assistants/` (profession slugs are
  stored per member but resolved by the profession catalogue).
- **Events fired / listened to:** none. Deliberately hook-free for now; add a
  filter only when a real second consumer appears.

## Conventions

- Membership arrays are keyed by `user_id` (int cast) so reads are O(1) and
  order-independent; never build a list-of-rows shape here.
- Every mutation re-reads meta, mutates the array, and writes it back in one
  `update_post_meta()` — no partial writes.
- Errors are `WP_Error` with the codes asserted by the REST tests
  (`wp_mcp_ai_org_team_*`); the controller maps them to status codes.

## Tests

```bash
bash bin/run-tests-docker.sh tests/rest-api/test-rest-org-teams-controller.php
```

Covers the role ladder, the last-owner invariant, list scoping, slug
uniqueness and route registration (14 cases).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — the console endpoint contract
- [`docs/project/plans/090-p-console-p1-p2-plan.md`](../../docs/project/plans/090-p-console-p1-p2-plan.md) §9 — why a CPT

## See Also

- Upstream parent: [`includes/`](../)
- Siblings: [`../rest/`](../rest/), [`../assistants/`](../assistants/),
  [`../security/`](../security/)
