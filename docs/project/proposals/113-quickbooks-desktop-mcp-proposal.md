# Proposal 113 — QuickBooks Desktop MCP server (local, adopt-and-extend)

**Status:** Research + proposal — awaiting owner decisions (§8) before implementation.
**Depends on:** nothing in-repo. External prerequisites: QuickBooks Desktop 2026 (Enterprise 26.0) installed on the local machine, a copy of the client company file for testing.
**Goal:** give the Zed agents a local, auditable, read-first MCP surface over the client's QuickBooks Desktop company file — with guarded, reversible writes gated behind a later phase — following the same local stdio pattern already proven by the MS Access MCP setup (Proposal-free, session 2026-11-05) and the repo's existing `context_servers` convention.

---

## 1. The gap (why this exists)

The Tropicana engagement (and agency clients generally) lives on QuickBooks
Desktop company files — `TEST_SQL - 20261104.mdb` is the Access side of the
same business. Today the only ways to answer a question like "aged receivables
by vendor" or "unpaid bills with early-pay discounts" are manual: open
QuickBooks, navigate reports, copy numbers. The MS Access MCP session proved
the workflow value (connect → enumerate → query → verify, all from Zed), and
QB Desktop is the natural next surface.

Three structural facts shape the design:

- **There is no official Intuit MCP server for Desktop.** Intuit's MCP
  server targets QuickBooks Online through its cloud API only. Desktop
  integrations run on the machine that holds the company file, through the
  qbXML SDK (`QBXMLRP2` COM) or the Web Connector
  ([Intuit — QBO MCP](https://github.com/intuit/quickbooks-online-mcp-server),
  [Logistify — QB Desktop MCP landscape](https://logistify.ai/guides/quickbooks-desktop/quickbooks-desktop-mcp-server/)).
- **Desktop is sunsetting except Enterprise.** Intuit stopped new sales of
  Pro/Premier in July 2024; version-year sunsets continue (Desktop 2023 lost
  support 2026-05-31; Enterprise 23.0 discontinued 2026-05-31). "QuickBooks
  Desktop 2026" in practice means **Enterprise 26.0** — and Enterprise 2022+
  is 64-bit, so `QBXMLRP2` registers as a 64-bit COM component and our
  64-bit Python stack works directly (the ≤2021 32-bit `0x800700C1` trap
  documented in the QBBridge lineage does not apply)
  ([ClonePartner — sunset timeline](https://clonepartner.com/blog/quickbooks-desktop-discontinued-full-sunset-timeline-through-2027/),
  [Intuit — release process](https://quickbooks.intuit.com/learn-support/en-us/help-article/update-products/understand-quickbooks-desktop-release-process/L2tnnGnFE_US_en_US)).
- **Client data is financial and irreplaceable.** The old `.qbw` backups in
  `Downloads/Jaya Backup May23/` are 2011–2014 vintage; QB 2026 will offer a
  one-way file upgrade on first open. An agent posting to live books with no
  guardrails is the failure mode this proposal exists to prevent.

## 2. Research — industry standards

1. **Read-only first is the published norm for financial MCP.**
   "Read-only access to financial systems (no write operations until
   security review)" is listed as a POC-deployment security fundamental for
   accounting firms ([Arcade.dev — enterprise MCP for accounting](https://www.arcade.dev/blog/enterprise-mcp-guide-for-accounting-audit-firms/)).
   Applied here: v1 is a query-only server; the guard is enforced in the
   server (anything that is not a `*QueryRq` is refused), not left to prompt
   engineering — the `Schrex/qb-mcp` pattern, which this proposal adopts.
2. **Tiered approval workflows, not blanket allow.** Risk-tiered tool
   approvals (auto-approve reads; require human approval for writes and
   irreversible operations) is the recurring industry recommendation
   ([DataWorkers — MCP auth & audit](https://dataworkers.io/resources/mcp-server-security-auth/),
   [TrueFoundry — MCP enterprise guide](https://www.truefoundry.com/blog/mcp-security-risks-best-practices)).
   Applied here: read tools auto-approve in Zed; every write tool is set to
   require approval per call, *and* carries a server-enforced two-step
   confirm code (QBBridge lineage, §4.4).
3. **The confirm code guarantees payload integrity, not human consent.** The
   QBBridge fork documents this precisely: an HMAC over (tool, company file,
   exact request) with a one-time nonce proves "what is posted equals what
   was previewed", and the *client's* approval prompt is the human consent
   layer. Both layers are required; neither substitutes for the other
   ([Compolaga/quickbooks-desktop-mcp — two-step confirm](https://github.com/Compolaga/quickbooks-desktop-mcp)).
   Applied here: the same split — Zed's per-tool approval is the human gate;
   the HMAC code blocks silent payload swaps and replay.
4. **Everything reversible, everything logged.** Financial automation
   standards converge on append-only audit logs (who, tool, arguments,
   response, policy decision) and undoability
   ([Red Hat — MCP logging](https://www.redhat.com/en/blog/mcp-security-logging-and-runtime-security-measures),
   [CubeAPM — audit chain of custody](https://cubeapm.com/blog/mcp-server-security-monitoring-audit-logs-compliance/)).
   Applied here: every qbXML request/response is logged locally; writes are
   grouped into runs and reverted in reverse order via generated
   `TxnDelRq`/`ListDelRq` (QBBridge revert design). The server never deletes
   or modifies transactions it did not create.
5. **Sensitive-field redaction is a server duty.** Tax IDs, bank account and
   card numbers must be stripped from every result before it reaches the
   agent ([Schrex/qb-mcp README](https://github.com/Schrex/qb-mcp)).
   Applied here: redaction lives in the response mapper, with a fixture
   hygiene test that proves it (sensitive fixtures are hand-written, never
   recorded).
6. **Verify against QuickBooks' own reports, to the cent.** The gold
   standard for any QB integration is a live-file tie-out: closed-month P&L,
   A/P aging totals and per-bill open balances compared against QuickBooks
   reports with a trust stamp recording basis, dates, filters and
   completeness ([Schrex/qb-mcp — live-file tie-out](https://github.com/Schrex/qb-mcp#6-live-file-tie-out)).
   Applied here: the M3 gate is that tie-out, on a **copy** of the company
   file, before anything points at live data.
7. **Session and authorization reality is fixed, not improvable.** Intuit's
   SDK docs define the lifecycle (OpenConnection/BeginSession/ProcessRequest;
   `qbFileOpenDoNotCare` vs unattended modes) and the one-time in-product
   authorization prompt per app + per company file
   ([Intuit — connections, sessions, authorizations](https://developer.intuit.com/app/developer/qbdesktop/docs/develop/connections-sessions-and-authorizations)).
   Applied here: the plan budgets the first-connect click-through, verifies
   the recorded Integrated Applications entry (personal-data box **must**
   stay unchecked), and never relies on "even when QuickBooks isn't running".
8. **No live data in the repo, ever.** The QBBridge/Schrex projects both
   enforce "no live data on GitHub" (counts and patterns only; names/amounts
   stay local). Applied here: the same rule, stated in the repo tracker and
   enforced by review — this repo's issues and docs carry zero client
   financial values.

## 3. Options considered

| Option | What it is | Verdict |
|---|---|---|
| **A. Adopt-and-extend: Compolaga/QBBridge (MIT) as the base, write-tier gate on top** | Tracked copy of the MIT QBBridge lineage (reads + writes with two-step confirm, runs + revert, business checks, offline tests); our additions: a server-enforced `QBBRIDGE_WRITE_MODE` tier (readonly/safe/full) and the Zed operational layer. | **Recommended — implemented.** The only legally clean local base (Schrex has no licence); write machinery already present; data stays on this machine; no vendor subscription. |
| B. Conductor hosted MCP | Vendor-hosted API + agent on the QB machine; official MCP server; full object surface. | Fastest path to full CRUD, but client financials transit the vendor cloud, subscription cost, and the write-safety posture is theirs, not ours. Held as the fallback if the local server proves insufficient. |
| C. CData local MCP | Commercial driver + local MCP against the SDK. | Local data residency, but license cost and a closed-source write path; adds nothing engineering-wise over A. |
| D. Logistify read-only MCP | Hosted, read-only, 11 tools via Web Connector. | Read-only matches v1, but hosted + vendor-account dependency for something we can run locally. Rejected unless the client insists on a supported vendor. |
| E. Greenfield qbXML MCP | Write our own from scratch (same as the Access build). | Full control but rebuilds session handling, qbXML envelopes, confirm codes, revert and tests that A already ships. Rejected as duplication. |
| F. Intuit official MCP | Intuit's QBO MCP server. | QuickBooks Online only — structurally cannot reach a Desktop company file. Rejected. |
| G. Schrex/qb-mcp (read-only) as base | Query-only guard, trust stamps, replay fixtures, XSD validation. | **Rejected as the base: the repository carries no licence** (all rights reserved — verified via the GitHub API during implementation). Its ideas (trust stamps, redaction, tie-out protocol) are adopted as v1.1 additions written by us, not copied. |

## 4. Recommended design (Option A)

### 4.1 Base: `Compolaga/quickbooks-desktop-mcp` (QBBridge lineage, MIT) — implemented

Tracked copy in `examples/qb-desktop-mcp` (fork point `fee09147af`,
2026-10-25; see `UPSTREAM.md`). What the base gives us:

- **Read tools**: status, company info, accounts/balances, vendors +
  history, customers (search), items (search), open invoices, invoice
  lookup, transactions in a date range, duplicate check, currencies, runs.
- **Write tools** (add-only) with **two-step confirm** — preview first,
  HMAC `confirm_code` over (tool, company file, exact request), single-use.
- **Runs + revert** — every booking undo-logged; `qb_revert_run` deletes
  only what the server itself created, newest first.
- **Business checks** — duplicate invoice numbers; `expected_total`
  pre/post compare (mismatch deleted immediately); unknown
  customer/item/account refused; over-balance payments refused; ambiguous
  amounts refused; per-session qbXML version negotiation.
- **Offline test suite** — 133 upstream tests (confirm codes, revert
  ordering, negative paths) runnable without QuickBooks; VM harness for
  real-QB scenarios.
- **Stdio only** — same Zed `context_servers` shape as the Access server.

#### 4.1.1 Our additions (this implementation)

- **`QBBRIDGE_WRITE_MODE`** server-enforced write tier (readonly / safe /
  full, default **safe**) at the single `_two_step` chokepoint — a client
  misconfiguration cannot widen it. Safe = list adds (`qb_create_customer`,
  `qb_create_item`, `qb_add_vendor`) + `qb_revert_run`; transactional tools
  refuse until `full`.
- uv-0.12-compatible `[tool.uv] dev-dependencies` block; `uv.lock`;
  `.gitignore` (logs, XSDs, company files, runtime state).
- Zed runbook (`docs/zed-runbook.md`), NV ops readme (`README.nv.md`),
  `UPSTREAM.md`, path-filtered CI (`.github/workflows/qb-desktop-mcp-tests.yml`),
  repo scan exclusions, and the `mcp-ai-wpoos-qb-desktop` agent skill.
- 5 new gate tests (138 total green).

#### 4.1.2 v1.1 wishlist (ideas adopted from the unlicensed Schrex design, written by us)

- Trust stamps on results (filters, completeness, cap notice, basis/dates,
  voided/non-posting counts) — the tie-out protocol in §6 depends on these.
- Sensitive-field redaction mapper (tax IDs, bank/card numbers) with
  fixture-hygiene tests.
- qbXML XSD validation for `run_query`-style raw requests (XSDs
  git-ignored — Intuit licence).

### 4.2 Extensions we add (v1 scope)

- **Zed-native wiring (done)**: `qb-desktop` context server in user-level
  Zed settings (`uv run --project … qbbridge-mcp`, `enabled: false` until
  QB 2026 is installed + authorized); scan exclusions added repo-wide.
- **Agent skill (done)**: `.agents/skills/mcp-ai-wpoos-qb-desktop/` — the
  operational guide (write tiers, two-step flow, hard rules, verification).
  **Client business knowledge** (which customer is which, account mapping,
  currency conventions) deliberately stays machine-local — client data
  discipline; owner adds it when ready.
- **Starter reports (deferred)**: `run_query` presets for the tie-out
  checks land together with the v1.1 trust stamps (§4.1.2), since a
  stamped answer is the only kind worth trusting.

### 4.3 Write tier (implemented — `QBBRIDGE_WRITE_MODE`, default safe)

All write tools share the same two guardrails:

- **Two-step confirm**: write tools require `confirm_code` — HMAC over
  (tool, open company file path, exact qbXML request) + one-time nonce.
  Call without a code returns a preview and the code; any argument change
  invalidates it; codes are single-use.
- **Client-side approval**: every write tool is configured to require
  per-call approval in Zed's agent tool permissions (the same "ask, never
  allow-always" rule QBBridge prescribes for Claude Desktop). The exact
  Zed permission syntax is confirmed against Zed docs at M3 time.
- **Runs + revert**: writes append to a run (`run_id`); `qb_revert_run`
  deletes exactly what the run created (generated `TxnDelRq`/`ListDelRq`),
  in reverse order, and refuses if a different company file is open.

#### 4.3.1 Safe tier (M4 — first writes shipped)

List-object **adds only**: `qb_create_customer`, `qb_create_item`,
`qb_create_vendor`. Rationale: additive master data — no ledger impact,
no dates, no balances; a mistake deletes cleanly with `ListDelRq` and
cannot touch the books. Explicitly excluded from the safe tier: any
update/delete of existing records (changing a live customer's terms or
balance is not additive).

- Duplicate-ref check before posting (refuse an existing customer/item
  name or vendor).
- Ambiguous amounts refused (applies to any numeric field).

#### 4.3.2 Transactional tier (M5 — gated behind M4 proof)

Posting writes: `qb_create_invoice`, `qb_receive_payment`, `qb_deposit`,
`qb_write_check`, `qb_journal_entry`. These touch ledgers and balances, so
they additionally require:

- **Server-side business checks** (from the QBBridge lineage): duplicate
  invoice numbers refused; `expected_total` compared pre- and post-post
  (mismatched invoice deleted immediately); unknown customer/item/account
  refused; payments above open balance refused (also when the same invoice
  appears twice in `apply_to`).
- **Per-session owner sign-off** on live books (policy, not config):
  transactional tools stay approved-for-copy until the M4 revert loop is
  proven and the owner signs off each live session.

### 4.4 Environment facts that remove risk (verified this session)

| Fact | Consequence |
|---|---|
| Python 3.11 64-bit, pywin32 312, `mcp` 2.0.0 SDK already installed | No 32-bit toolchain needed for QB 2022+ (64-bit `QBXMLRP2`) |
| QB Desktop 2026 = Enterprise 26.0 (Pro/Premier discontinued) | 64-bit COM registration expected; verify at install |
| Old `.qbw` files are 2011–2014 vintage | Back up before first open; QB upgrade is one-way |
| Access MCP already wired in Zed | Identical `context_servers` + smoke-test playbook reused |

### 4.5 Explicitly out of scope

- Unattended access ("even when QuickBooks isn't running") — sessions
  require QuickBooks open with the company file, same Windows user, not
  elevated.
- Web Connector / remote-machine operation — the server runs where
  QuickBooks runs.
- Migrating the client off QuickBooks Desktop — a business decision, not an
  engineering one.
- Inventing report semantics — every total must tie out to a QuickBooks
  report before it is trusted.

## 5. Phases

| Phase | Content | Gate |
|---|---|---|
| **M0 — Environment + fork (done)** | Tracked copy of `Compolaga/quickbooks-desktop-mcp` (MIT) into `examples/qb-desktop-mcp` (`UPSTREAM.md` fork-point SHA); `uv` verified; `.gitignore`, scan exclusions, path-filtered CI | Offline suite green (138 tests) |
| **M1 — v1 server + write-tier gate (done)** | Base read/write surface as-is; `QBBRIDGE_WRITE_MODE` gate (readonly/safe/full, default safe) + 5 gate tests; uv-0.12 pyproject adaptation; `uv.lock` | `uv run pytest` green without QuickBooks |
| **M1.5 — QB install (owner)** | QB Desktop 2026 (Enterprise 26.0) installed; 64-bit `QBXMLRP2` verified; a company-file **copy** opened in single-user mode | `reg query` resolves; `qb_status` reaches the copy |
| **M2 — Zed wiring + first-connect auth** | Enable `qb-desktop` in Zed; authorization click-through per `docs/zed-runbook.md` §2; Integrated Applications entry verified; read tools auto-approved, write tools per-call | Agent in Zed answers a real lookup question |
| **M3 — Live tie-out (copy)** | Closed-month P&L, A/P aging, per-bill open balances vs QuickBooks reports on a **copy**; mismatch protocol | All figures to the cent; tie-out table recorded (counts only) |
| **M4 — Safe writes on the copy** | `qb_create_customer/item/vendor` + revert (already server-gated to safe tier); write → verify → revert round-trip | Create → verify → revert → gone, on the copy |
| **M5 — Transactional writes (gated)** | `QBBRIDGE_WRITE_MODE=full` only after owner sign-off; invoice/payment/deposit/check/journal with the existing business checks | Invoice → verify → revert round-trip on the copy; per-session owner sign-off on live books |

## 6. Test plan (highlights)

- **Offline**: replay-transport tests for every tool (success, cap/truncation,
  voided/non-posting, redaction); fixture hygiene tests (no sensitive values
  in recorded fixtures); a refusal test proving the query-only guard blocks
  an `*AddRq`.
- **On the machine (copy file)**: smoke test (status, company info, one
  query per tool family), then the §5 M3 tie-out protocol — every total
  checked against QuickBooks' own report, basis/dates/filters in the stamp.
- **Failure contract**: error taxonomy table (not running / wrong file /
  unauthorized / busy / SDK missing / schema error / QB error codes) —
  each mapped to a typed message and remediation step, per the Schrex
  troubleshooting table.
- **No live data rule**: GitHub/tracker receives counts and patterns only;
  names, amounts, ref numbers never leave the machine.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Agent posts to live books | v1 is query-only (server guard); v2 writes need per-call approval + HMAC confirm + revert |
| Old company file damaged by upgrade | Backup before first open; all v1/v2 testing on copies |
| 32/64-bit COM mismatch | Verify `QBXMLRP2` registration bitness at install; fallback is a 32-bit Python runtime for the server only (QBBridge pattern) |
| First-connect dialog automation (the "type yes" bug) | Documented click-through runbook; single manual authorization; entry verified in Integrated Applications |
| `run_query` misuse (raw qbXML) | Query-only guard limits raw passthrough to `*QueryRq`; presets cover the common reports |
| Zed tool-permission default is `allow` (repo settings) | Write tools explicitly overridden to per-call approval at M4; reads stay auto-approved |
| Vendor lock-in / data residency | None — local stdio server, logs local, no third party in the path |

## 8. Owner decisions requested

1. **Base repo**: **decided — `Compolaga/quickbooks-desktop-mcp` (MIT, QBBridge lineage).**
   `Schrex/qb-mcp` was rejected as the base because it ships **no licence**
   (GitHub API: no LICENSE file — all rights reserved); its ideas (trust
   stamps, redaction, tie-out protocol) are adopted as our own v1.1
   additions (§4.1.2), not copied code. The Compolaga base already carries
   writes + confirm + revert, matching the safe-first write decision.
2. **Write tier**: **decided — in scope, safe-first.** M4 ships list adds
   only (customer/item/vendor); transactional tools (invoice, payment,
   deposit, check, journal entry) follow in M5 behind the M4 proof gate
   and per-session owner sign-off on live books. Open sub-decision: does
   M5 keep all five transactional tools or a subset first (e.g. invoice +
   payment only)?
3. **Client file**: which company file (and which copy) is the test target,
   and who is authorized to click the first-connect approval as Admin?
4. **Repo placement**: **decided — monorepo, `examples/qb-desktop-mcp` — implemented.**
   Tracked copy (no submodule/subtree); fork-point SHA in `UPSTREAM.md`;
   path-filtered CI (`.github/workflows/qb-desktop-mcp-tests.yml`, offline
   suite only); `.gitignore` for logs, `xsd/`, company files; scan
   exclusions added to the repo `file_scan_exclusions`; `README.nv.md`
   marks the folder a Python/uv foreign-stack project.
5. **Skill scope**: **decided — in-repo operational skill, client knowledge machine-local.**
   `.agents/skills/mcp-ai-wpoos-qb-desktop/` carries the ops guide; the
   client business knowledge (account mapping, currency conventions) stays
   on the QB machine under the no-live-data rule.

## 9. References

- [Schrex/qb-mcp — read-only QB Desktop MCP (qbXML over COM)](https://github.com/Schrex/qb-mcp)
- [Compolaga/quickbooks-desktop-mcp — QBBridge fork, two-step confirm + revert](https://github.com/Compolaga/quickbooks-desktop-mcp)
- [Intuit — QB Desktop SDK: connections, sessions, authorizations](https://developer.intuit.com/app/developer/qbdesktop/docs/develop/connections-sessions-and-authorizations)
- [Intuit — QB Desktop API reference (qbXML)](https://developer.intuit.com/app/developer/qbdesktop/docs/api-reference/qbdesktop)
- [Intuit — QBO MCP server (Online only)](https://github.com/intuit/quickbooks-online-mcp-server)
- [Logistify — QB Desktop MCP landscape 2026](https://logistify.ai/guides/quickbooks-desktop/quickbooks-desktop-mcp-server/)
- [ClonePartner — QB Desktop sunset timeline](https://clonepartner.com/blog/quickbooks-desktop-discontinued-full-sunset-timeline-through-2027/)
- [Arcade.dev — MCP runtime security for accounting](https://www.arcade.dev/blog/enterprise-mcp-guide-for-accounting-audit-firms/)
- [DataWorkers — MCP auth & tiered approvals](https://dataworkers.io/resources/mcp-server-security-auth/)
- [Red Hat — MCP logging & runtime security](https://www.redhat.com/en/blog/mcp-security-logging-and-runtime-security-measures)
- Repo precedent: MS Access MCP setup + smoke test (session 2026-11-05);
  `context_servers` convention in user-level Zed settings.
