# Machine principals, estate scope, the root key and the file registry (v1.2.23)

Moved out of `SKILL.md` on 2026-11-25 (the skill had reached ~97 KB against Zed's
~100 KB load limit). Applies to Proposals 152/154/159/162/189.

## A machine principal is a token that cannot be the owner (159)

`nvoos:machine-token` mints a personal access token with an explicit scope, and the
refusals are the design: `*` and `any` are rejected (that is the owner's own
authority), an assistant scope must resolve in the **issuing** estate (the scope
implies it), and the owner must hold `manage_options` or the credential could not pass
the routes it exists for. Expiry defaults to 90 days on the 60–90 day PAT baseline;
`--days=0` removes it and says so. Every mint writes an audit row naming scopes, owner
and expiry — a console run has no authenticated principal, so the row records
`minted_by: console` rather than inventing an actor.

The route guard is **default-deny** (slice 2 wired it, slice 3 added
rotate/revoke/execute). Approvals live in `nvoos_approvals` (machine columns), and the
tool manifest's annotations **veto unattended use** — a tool that declares a human is
required cannot run from a machine token. The expiry report has a channel + dedupe, is
narrowed to machine credentials, can be acknowledged, and rotation is the migration
while critical expiries escalate.

## Estate scope is a tenant column, not a lookup (152)

`nvoos_token_usage`, `nvoos_token_usage_daily`, `nvoos_harness_trace_records`,
`nvoos_activity_events` and `nvoos_files` all carry `tenant_id`; the token view and
traces filter by it. The chat assistant resolves **server-side** inside the estate — a
client that names another estate's assistant gets nothing. When adding a tenant-scoped
read, filter in SQL, not after the fact: a foreign row must be **absent**, not
forbidden (the file route sets the precedent).

## Budgets read the spend record; there is no counter to drift (154)

`nvoos_budgets` holds `window`/`limit` (+ assistant scope). `BudgetGuard` runs **before
the provider call** at both chat routes, the assistant test probe and `AgentNodeRunner`;
spend is `SUM(cost)` over the window, so a budget and the cost pages cannot disagree.
At/over the limit is **429 `budget_exceeded`** with window/spend/limit; an unreadable
store is **503 `budget_unverifiable`** — an unreadable counter under-reports and
under-reporting is over-spending. Zero means unlimited. New tables must be registered
in `RuntimeSchemaTest`.

## The root key is a second credential, so a refusal is 423 (189)

The field has always described a second password; now it is stored as a **hash** and
verified with `Hash::check` (a legacy plaintext value verifies once and is re-hashed in
place). A caller proves possession with an `X-Root-Key` header or a 15-minute session
grant. A refused write answers **423**, not 403 — the caller is authorised and is being
asked for a second credential — and every attempt is audited. `nvoos:root-key` is the
break-glass path and is deliberately **not** gated (a recovery path that needs the
thing it recovers recovers nothing). The first-key exemption is narrowed to the
credential and its flag, so a field submitted beside the key is not written for free.

**Gate `SettingsService::write()` for `/ops`, not route middleware:** Filament's
Livewire actions never reach middleware.

## Files: visibility is a column, and a private file has no URL (162)

`FileStore` used to write bytes and return `crc32(path)` without inserting a row, so
the id it returned could not be resolved (`upload_file` → `get_file_info`/`delete_file`
never worked). It now inserts the row and returns its id. Visibility is a **column**,
not a disk name, so repointing `oos.files.disks` cannot reclassify existing files. The
extension comes from the **sniffed** type and the basename is a ULID — a caller's
filename is never a path. Delete removes the row **and** the bytes.

A private file has no URL: `GET /api/v1/files/{id}` resolves the row inside the current
estate (a foreign id is absent, not 403) and serves it with `nosniff`, a sandboxing CSP
and private caching; Range is out of scope. The visibility capability is a **separate
interface**, not a parameter on `FileStoreInterface` — widening that method would fatal
every external implementer. `UploadFileTool` probes for it and defaults to **private**.

## The skill manager is a file-write surface, so its security pass ships with it

The folder name is slugified from parsed frontmatter (no separator survives), the
target is re-resolved inside the root before **every** write, size/name/description are
capped, the file is written to a temp name and renamed (a crash cannot leave a
half-parsed skill), a symlinked folder is refused rather than followed, and delete
removes the folder only when `SKILL.md` is its **only** entry — checked before the
first unlink. The manager lives on `/ops` because a skill directory is
deployment-wide, and it takes the root key.

## Quotas, professions, identity

`nvoos_tool_quotas`/`nvoos_tool_quota_usage` bound a tool per estate per day
(`nvoos_tool_flag_overrides` holds addition-only capability-flag overrides). The
profession catalogue is served from an estate overlay (`nvoos_professions`) with its
licence declared, and `save_profession` writes through it. Magic-link sign-in uses
`nvoos_magic_login_tokens`; Auth0 bearer tokens verify against `users.auth0_subject`;
first-login provisioning is allowlisted by the JIT tables.

The media worker's video contract is two-step (`/api/video/process` answers a filename;
`/api/video/download/<name>` serves the bytes) — a host contract performs both inside
`process()` so no intermediate name escapes, and every path segment is validated **by
name** (a charset check alone lets `../` through).
