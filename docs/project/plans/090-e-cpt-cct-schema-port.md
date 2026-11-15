# Proposal 090-E — CPT/CCT-backed Tools: Schema Port

**Status:** shipped for two families — project management (090-f Phase 2)
and classroom/ECA (090-d cluster 3). CRM, clinical and legal are unstarted;
see §9 for the corrected tracker.
**Parent:** `090-d-selective-tool-port.md` (tier D/E tools)
**Question answered:** *can CPT/CCT-backed tools be posted to the Laravel
database, with their schema?*

**Short answer:** yes — but the unit of work is a **family schema**, not a
tool. A CPT-backed tool without its model is a tool that returns empty; the
schema *is* the port.

---

## 1. How big is this, really?

Measured with `bin/classify-tool-portability.php --match=…` over the 1,434
tools in the WP↔core gap (2026-11-12):

| Coupling | Tools |
|---|---:|
| Any CPT/CCT primitive (`get_post`, `WP_Query`, `wp_insert_post`, `update_post_meta`, `jet_engine`, `get_terms`) | **654** |
| CPT **reads** (`get_post`, `get_posts`, `WP_Query`) | 606 |
| CPT **writes** (`wp_insert_post`, `wp_update_post`, `update_post_meta`) | 289 |
| **JetEngine CCT** (`jet_engine`) | 11 |
| Taxonomies (`get_terms`, `wp_set_object_terms`) | 46 |

So 46% of the gap is storage-shaped. That is far too much to port wholesale,
and the 090-D tiers classify most of it D/E for good reason — but it is also
not a blocker: **storage is the most portable part of WordPress**, because
CPTs are just rows with a type column.

### Which families would unlock the most tools

`post_type` literals across the WordPress tool trees:

| CPT | Refs | CPT | Refs |
|---|---:|---|---:|
| `mcp_ai_eca` | 61 | `mcp_ai_project` | 18 |
| `mcp_ai_task` | 44 | `mcp_ai_place` | 18 |
| `mcp_ai_crm_activity` | 33 | `mcp_ai_student` | 17 |
| `attachment` | 33 | `mcp_ai_registration` | 17 |
| `mcp_ai_lead` | 29 | `mcp_ai_checkup` | 16 |
| `mcp_ai_member` | 24 | `mcp_ai_lf_matter` | 15 |
| `mcp_appointment` | 21 | `mcp_ai_allergy` | 15 |
| `mcp_ai_med_record` | 21 | `mcp_ai_quiz` | 13 |
| `mcp_ai_prescription` | 19 | `mcp_ai_lf_time_entry` | 13 |

`attachment` is already covered by `nvoos_files` + `MediaController` (Phase
3d), so it is not a port target.

---

## 2. Mapping

| WordPress primitive | Laravel target | Notes |
|---|---|---|
| CPT row (`wp_posts` + `post_type`) | `nvoos_posts` with a `type` column — **already exists** (Phase 3c `wp/v2` shim) | Add an Eloquent model/scope per family |
| Post meta (`get_post_meta`) | `meta` JSON column | Promote hot keys to generated columns when a query needs them |
| Serialized PHP meta | JSON, with a decode shim for legacy payloads | Some meta stores PHP arrays |
| Taxonomy + terms | `nvoos_terms` + `nvoos_term_relationships` | Many-to-many; `get_terms` reads become joins |
| JetEngine CCT | Its **own** table — CCTs are already real tables in WP | Port the column schema 1:1; no meta indirection |
| Attachments | `nvoos_files` + `MediaController` | Done |
| Post status (`publish`/`draft`/`private`) | `status` column + Eloquent scopes | Keep the same vocabulary |
| Revisions | `nvoos_post_revisions` (optional) | Only if a tool reads history |
| Capabilities | Laravel policies / gates | Map the same capability strings |
| Hooks fired by `wp_insert_post` | Explicit domain events | **Not ported automatically** — see §6 |

---

## 3. What already exists (precedent)

- `nvoos_posts` + `App\Http\Controllers\Wp\ContentController` — the `wp/v2`
  shim from Phase 3c. The table and the read path are there.
- `nvoos_files` + `app/Http/Controllers/MediaController.php` — attachments.
- `nvoos_paper_records`, `nvoos_toolkit_records`, `nvoos_schedules`,
  `nvoos_approvals`, `nvoos_triggers` — four other entity tables already
  modelled the Laravel way.

So this is **not a new architecture**; it is applying the existing one to the
CPT families, one at a time.

---

## 4. Ownership — pick one per deployment, never mirror

| Mode | Who writes | When it is right |
|---|---|---|
| **1. Laravel owns** | Laravel only; WP reads via API or is retired | Headless/Laravel-hosted deployments; the family is new |
| **2. WordPress owns, Laravel proxies** | WP only; Laravel calls Remote Sites/MCP | The family must run against a live WP (Woo, JetEngine, media) |
| **3. Mirror / dual-write** | Both | **Never.** Two writers, ID divergence, meta loss, no conflict resolution |

The failure mode to avoid is “port the schema so both can write”. If a site
needs WordPress for a family, use mode 2 for that family.

---

## 5. Unit of work: the family

A family port is **schema + model + tools + migration + fixtures**, delivered
together:

1. **Document the schema** — post type, meta keys, taxonomies, statuses and
   which tools read/write each.
2. **Migration + model** — `nvoos_posts` row type (or a dedicated table for a
   CCT), JSON `meta`, pivot for terms.
3. **Read tools first** — list/get/count; they are the console's need and the
   easiest parity test.
4. **Write tools second** — create/update/delete, with the same validation and
   the canonical envelope.
5. **Import path** — reuse the toolkit import/paste pattern for bulk load;
   a migration script for existing WP rows.
6. **Parity fixtures** — one per tool, same arguments → same shape as WP.
7. **Re-run the classifier** — the family's slugs leave the gap; the rest stay.

**Acceptance for a family:** the console's family pages render on Laravel
against seeded data, list and write tools pass parity fixtures, and the
migration is idempotent.

---

## 6. What a storage port does **not** give you

- **Hooks.** `wp_insert_post` fires actions that other plugins listen to
  (notifications, indexing, workflows). Laravel gets explicit domain events
  instead; anything relying on a third-party hook must be re-wired or declared
  unsupported.
- **Block editor / shortcode rendering.** Content is data; rendering is a
  separate concern.
- **Revisions, autosaves, trash semantics** — only if a tool needs them.
- **JetEngine's field UI, dynamic visibility, listings** — CCT *data* ports;
  the JetEngine feature set does not.

---

## 7. Sequencing (by tools unlocked per schema)

| Order | Family | CPT(s) | Why |
|---|---|---|---|
| 1 | **Project management** | `mcp_ai_task` (44) + `mcp_ai_project` (18) | One model, 62 tool refs, no regulated data, schema already documented by the `design-project-management` skill |
| 2 | **CRM** | `mcp_ai_lead` (29) + `mcp_ai_crm_activity` (33) | Highest business value; `design-crm` documents the fields |
| 3 | **ECA / education** | `mcp_ai_eca` (61) + `mcp_ai_student`/`registration`/`checkup` | Largest tool count, but a vertical-specific schema |
| 4 | **Clinical** | `mcp_ai_med_record`/`prescription`/`allergy` | Needs a privacy review before it is modelled at all |
| 5 | **Law firm** | `mcp_ai_lf_matter`/`lf_time_entry` | Same, plus billing semantics |

Recommended first: **project management**. It is the smallest honest proof of
the pattern, and it unlocks the most tools per unit of schema.

---

## 8. Guardrails

1. **One owner per family per deployment** (§4).
2. **No schema without tools, no tools without schema** — they ship together.
3. **Keep the status/meta vocabulary identical** so fixtures and migrations
   are mechanical.
4. **Privacy review before modelling** clinical, legal, or member data.
5. **Do not port `attachment`** — `nvoos_files` already owns it.
6. **Do not port a family entirely in one PR.** Read tools, then writes, then
   import, then the bespoke admin page.

---

## 9. Status

| Item | State |
|---|---|
| Coupling measured | ✅ 654 / 1,434 |
| Mapping agreed | ✅ this document |
| First family chosen | ✅ project management (`mcp_ai_task` + `mcp_ai_project`, 62 refs) |
| Schema ported | ✅ read **and** write — registry entries, the three read tools, the six write tools, seeder (090-f Phase 2), and the `/pro/records/schema` + `wp/v2` admin surface (`ProjectManagementAdminTest`). The status here previously said "read path only"; that was stale. |
| Read-tool parity (step 6) | ✅ `tests/fixtures/pm/tools.json` — 7 cases captured from the WordPress tools, replayed by the WordPress suite and by `ProjectManagementToolParityTest` on Laravel. Ids and dates are normalised; everything else is compared verbatim |
| Write-tool parity (step 6) | ✅ `tests/fixtures/pm/writes.json` — 8 cases (both upsert branches, update, delete, for projects and tasks), captured by `tests/manual/capture-pm-writes.php` and replayed by `tests/test-pm-writes-parity.php` and `ProjectManagementWriteParityTest`. The port returns a **superset**, so the replay asserts “every recorded field matches, extras are on a four-key allowlist” rather than equality — see §9.1 |
| Import path (step 5) | ⏳ — only needed when a live WordPress site is migrated; not built speculatively |
| Classroom / ECA family | ✅ `mcp_ai_eca` + `mcp_ai_student` + `mcp_ai_eca_category` descriptors, `ClassroomSeeder`, and six tools (`list_ecas`, `get_eca`, `list_students`, `get_student`, `enroll_student_eca`, `mark_eca_attendance`). Scope is the **local mirror**: the Google Classroom / ISAMS sync stays WordPress-only. Enrolment and attendance each have their own map shape — see 090-d |
| Next family | ⏳ **CRM deferred.** §7 ordered CRM second, but its CRUD tools classify D (11–20 hard refs), so the family is a bigger lift than project management was. ECA (order 3) went first instead because 090-d cluster 3 was already open. CRM's schema is still the next commercial priority |

### 9.1 Write-parity decisions

The write corpus found three places where the WordPress tools and the port
disagreed. Two were drift in the port and were fixed; one is a deliberate
difference and is not reproduced.

| Difference | Decision |
|---|---|
| `create_project` / `create_task` said `"Project created: X"` where WordPress says `"Project created successfully."` (likewise for tasks) | **Fixed in the port.** `message` is part of what the model sees, so it belongs to the contract |
| WordPress **coerces** an unknown `status`/`priority`/`category` on create (e.g. `pending` → `todo`); the port rejected it with `invalid_argument` | **Fixed in the port.** The response echoes the stored value, so coercion is observable rather than silent, and one transcript now behaves the same on both runtimes |
| A **partial upsert** (`create_project`/`create_task` with an id but only some fields) reports the *arguments* on WordPress — including empty strings for fields it left alone — while the port returns the **stored row** | **Divergence kept.** The port's answer is the true one; reproducing the WordPress reply would make the response lie about the row. The corpus therefore fully specifies both upsert cases, and `update_project`/`update_task` (which return only an id on WordPress) carry the partial-update coverage |

Two mechanical notes for anyone editing the corpus:

- The `updated` flag normalises to `<date>` on an upsert because the key
  contains `date`; `false` casts to an empty string, falls through, and stays a
  boolean. Both shapes are recorded, so the quirk is load-bearing.
- The caller is recorded as `<user>`, not a number: user 1 in a dev database is
  not user 1 in a fresh test database, the same reason record ids are symbolic.
