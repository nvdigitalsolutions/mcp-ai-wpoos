# 129 — Outreach & CRM Hardening: Research Notes

- **Date:** 2026-11-07
- **Status:** Research complete; feeds [Proposal 129](129-outreach-crm-sellbird-hardening-proposal.md)
- **Scope:** What the NV oOS Outreach & CRM toolkits can learn from [Sellbird](https://github.com/ethanplusai/sellbird), plus the 2025–2026 industry standards those recommendations depend on.
- **Licence note:** Sellbird is **AGPL-3.0**. The Pro addon is proprietary. This document paraphrases and cites upstream material; it deliberately does **not** reproduce Sellbird source, prompts or skill files. Any implementation is independent.

---

## 1. Method

1. Read the Sellbird repository at `main` on 2026-11-07: README, `docs/*.md`, `skills/*.md`, and the core modules (`gate.py`, `signals.py`, `pipeline.py`, `main.py`, `send_window.py`, `deliverability.py`, `state.py`, `export.py`).
2. Read the NV oOS surfaces it would touch: `addons/pro/includes/tools/crm/**`, `addons/pro/includes/tools/outbound-booking/**`, `docs/project/proposals/044-outbound-appointment-booking.md`, `docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`.
3. Ran targeted web searches for the standards the recommendations rely on (deliverability, bulk-sender rules, verification, legal basis, reply benchmarks, agentic-outreach safety). Sources are listed in §7 with dates where the publisher gives one.
4. Separated **adoptable patterns** from **licence-incompatible or off-axis material**, and recorded both.

Limitations: the Sellbird assessment is a documentation + code-reading review, not a production trial. Star/fork counts and commit counts are point-in-time. Legal thresholds change annually; the figures here are for engineering defaults and must be re-verified before publication or sale.

---

## 2. Sellbird assessment

### 2.1 Facts

| Fact | Value |
|---|---|
| Name | Sellbird (formerly Harvey; renamed in 0.4.0) |
| Repository | `github.com/ethanplusai/sellbird` |
| Licence | AGPL-3.0 (releases up to 0.3.x were MIT; that code retains its MIT notice) |
| Popularity at review | 4.7k stars, 1.0k forks, 476 commits |
| Runtime | Python 3.11+, SQLite, FastAPI dashboard, local-first |
| Model access | Claude Code CLI or Codex CLI on the operator's subscription — **no API key, no per-token bill** |
| Tests | 242 (per `docs/how-it-works.md`) |
| Default state | Nothing sends without approval; autopilot is opt-in |
| Data sources | OpenStreetMap (free default), TinyFish, DataForSEO, Serper, Scavio (paid, opt-in) |

### 2.2 The four architectural bets

**Bet 1 — deterministic-first.**
The heartbeat (`sellbird/main.py`) wakes, checks quiet hours and budget, decides, acts, logs, sleeps (15 min default). The decision is a fixed priority over counts:

```
handle replies > send campaigns > write campaigns > prospect > idle (analyze)
```

Profiling and outbox draining ride along every cycle because they are free and schedule-bound. Model calls are reserved for writing, reply handling and judgement. This is the opposite of "ask the model what to do next" and it is directly portable to a WP-Cron/Action Scheduler tick.

**Bet 2 — a gate that cannot be talked out of.**
`sellbird/gate.py` runs non-LLM checks at send time. The checks observed:

| Check | Rule |
|---|---|
| Recipient syntax | RFC-ish regex |
| Recipient ↔ record match | `prospect.email` must equal the `To:` address |
| Deliverability | sequence mail requires `verified` (or `risky` when explicitly allowed) |
| Do-not-contact | address and domain matching, plus-tag aware, label-boundary safe |
| Subject/body presence | non-empty |
| Subject length | ≤ 90 chars |
| Body length | ≤ 220 words (hard ceiling; sequences aim far lower) |
| Merge tags | `{{token}}` / `{token}` leftovers reject |
| Banned phrases | spam triggers + AI tells ("as an AI", "I hope this finds you well", "game-changer", …) |
| Link budget | ≤ 1 URL |
| Postal address | required in cold mail when configured |
| HTML | reject HTML bodies; plain text only |

The module's own comment states the rationale: *instructions in the prompt are a floor, not a guarantee; verification belongs in code.* That is the thesis of this proposal.

**Bet 3 — evidence before personalisation.**
The pipeline is DISCOVER → PROFILE → ENRICH → VERIFY.

- **Discover** is the only paid stage; it estimates cost first and refuses to run without confirmation. Depth 20–30, not 100, because Google removed 100-result pages in September 2025 and deeper results are mostly directories.
- **Profile** is free: three HTTP requests per business (homepage, `robots.txt`, `sitemap.xml`, plus team/careers pages when present) and regex over the HTML already in hand. Incumbent-agency detection is confidence-scored (0.9 for "Powered by"/"Website by", 0.75 for a descriptive link, 0.35 for an unlabelled link and **not recorded** — a wrong incumbent in an email is worse than silence).
- **Enrich** reads the site's own menu to find named people; owners rank first; at most two people per company. It explicitly rejects headings, testimonial quotes, other organisations' officers, former owners and taglines as "people".
- **Verify** learns the domain's address pattern from published addresses and verifies one candidate. It tags every address `verified` / `risky` (catch-all) / `guess` / `invalid`, and **only deliverable addresses are ever sent to**. It refuses to verify an address another named person could own, because a verifier would confirm *their* mailbox. Raw SMTP probing is rejected as non-viable from a laptop (port 25 blocked; major providers accept everything from unknown IPs).

The database is treated as the asset: repeated observations over quarters (who changed agency, who started ads, whose content went stale) are worth more than the initial list.

**Bet 4 — approval is a ladder.**
Every email waits in an Outbox by default, presented one at a time (not a wall of drafts, which invites "approve all"). Autopilot is opt-in with a typed confirmation. Even in autopilot, an unapproved email is **held** when it links to a domain that isn't yours or quotes the system's own instructions. Approve-all excludes held mail. There is a kill switch (`sending pause`) and the pre-send gate still runs.

### 2.3 Signal catalogue

Sellbird documents **29 signals** in `docs/signals-and-pipeline.md` (the project-structure comment in `docs/how-it-works.md` still says 23 at review time — a docs/code drift worth noting). They group into:

- **Discovery:** SERP rank (11–30 is the target band), local pack / Maps rank, no website, unclaimed listing, review rating/count.
- **Profile:** incumbent agency, running Google/Meta ads, no online booking, no schema markup, stale blog, page count, tech stack, blocks AI crawlers, hiring role/open roles, YC/accelerator/funding.
- **People:** contact found, decision-maker title, likely owner, registry-verified.
- **Contact:** email pattern, email status, contact-form URL.

Two design choices stand out:

1. **Signals are proposed, not assumed.** The operator confirms each one; every signal shows a plain-language description, its cost, and current coverage. Nothing is collected until confirmed.
2. **A cohort is a query, not a list.** Must-have AND disqualify AND numeric ranges, counted live. The resulting campaign has an explainable reason per prospect, and the email can cite it.

### 2.4 Deliverability and legal posture

- Dedicated sending domain recommended; SPF, DKIM and DMARC required before sending.
- Warmup 2–4 weeks; ramp 10–20/day, adding ~5/day; `max_daily_sends: 50` is a ceiling, not a target.
- Bounce rate above ~3% trips a kill switch.
- Plain text, no tracking pixels, at most one link.
- CAN-SPAM: truthful subject/from, physical address, immediate opt-out; opt-out wording recognised wherever it appears, including a bare "STOP".
- GDPR/PECR: defensible legitimate interest for B2B; relevance to the role; easy objection. Optional per-recipient legal check via Precleared.
- LinkedIn: browser automation off by default and explicitly warned as ToS-risky.
- AI disclosure: answer truthfully if asked; never configure otherwise.

### 2.5 What to be sceptical about

| Claim / pattern | Assessment |
|---|---|
| "No model API bill" via subscription CLI | True for Sellbird, irrelevant to a WordPress plugin. Do not copy the dependency. |
| 29 signals | Several require paid providers or scraping that we should not bundle. Adopt the *shape*, not the bundle. |
| Agency detection confidence scoring | Excellent idea; thresholds are heuristics, not validated. Adopt the "don't record low-confidence" rule, calibrate locally. |
| Email pattern inference | Sound, but catch-all domains make verification probabilistic. `risky` must remain non-sendable by default. |
| Statistical A/B promotion | Sellbird uses a minimum-send floor, not a significance test. We should do better (see §5). |
| Docs/code drift (29 vs 23 signals) | A reminder to generate counts from code, not prose. |

---

## 3. Industry standards (2025–2026)

### 3.1 Authentication and bulk-sender rules

Google's sender guidelines (enforced since 2024, still current in 2026) require, for bulk senders (5,000+ messages/day to Gmail):

- SPF **and** DKIM **and** DMARC, with alignment;
- one-click unsubscribe (RFC 8058) for marketing/promotional mail;
- spam rate in Postmaster Tools **below 0.3%** (0.1% is the safe operating target).

Yahoo and Apple impose equivalent one-click requirements; Microsoft recommends them. Sources: Google sender guidelines FAQ and the 2026 checklists in §7.

**Implication for us:** the plugin should not assume the host's `wp_mail()` is authenticated. A readiness check that reads public DNS and blocks auto-send until SPF/DKIM/DMARC are present is cheap and high-value. The one-click header is also missing today.

### 3.2 Warmup, ramp and bounce thresholds

- New mailboxes have zero reputation; ISPs expect a gradual ramp, not hundreds on day one.
- Common guidance: warm 2–4 weeks (some vendors say 3–6), start 10–20/day, add ~5/day.
- Bounce rate should stay **below 2%**; above 5% is critical. Hard bounces should approach zero; total bounces under 2% is the recurring target.
- Spam complaint rate should stay **below 0.1%**; 0.3% is the Gmail enforcement line.

**Implication:** a per-mailbox daily cap and a warmup schedule are not "nice to have"; a single 200-email day on a cold domain can undo weeks of work.

### 3.3 Legal basis by market

| Market | B2B cold email | Engineering default |
|---|---|---|
| US (CAN-SPAM) | Opt-out regime; truthful headers, physical address, honour opt-out | Footer + immediate opt-out + unsubscribe headers |
| UK (GDPR + PECR) | Corporate subscribers generally can be emailed without prior consent; sole traders/partnerships are individuals and need consent or soft opt-in | Country-aware eligibility filter; objection line |
| Germany (UWG §7) | Prior express consent generally required, even B2B | Default to "requires consent" for `DE` |
| Canada (CASL) | Depends on whether the address was published and the message's relevance | Record basis + identification block |
| EU broadly | Legitimate interest can work for B2B, but relevance and easy objection are mandatory | Campaign-level legitimate-interest note |

Sources: ICO B2B marketing guidance; 2025–2026 practitioner summaries in §7.

**Implication:** the existing consent ledger is the right substrate, but the *first-contact eligibility decision* needs a country-aware seam and a recorded basis per campaign. Do not hardcode one global rule.

### 3.4 Copy and cadence benchmarks (2026)

Recurring figures across Instantly's 2026 benchmark report, lemlist, Woodpecker, Belkins and Backlinko-derived summaries:

| Finding | Figure | Use |
|---|---|---|
| Median cold reply rate | ~3.4% (true net-new closer to ~0.5%) | Set expectations; do not promise 10% |
| Top decile | 10%+ | Relevance + deliverability, not clever copy |
| Optimal length | 50–125 words; under 75 for email 1 | Gate soft ceiling |
| 200+ words | ~half the reply rate | Hard ceiling 220, warn above 125 |
| Sequence length | 4–7 touches | Presets |
| Cadence | ~6 touches over 14–28 days | Presets |
| Step 1 share of replies | ~58%; follow-ups ~42% | Do not stop after one |
| Single follow-up lift | +65.8% (Woodpecker) | Bump email template |
| Offer CTA vs meeting ask | ~+28% replies | Preset guidance |
| Easy-yes CTA | Outperforms a big ask | Preset guidance |
| Tracking pixels | 8–12% inbox-placement cost | No pixels on cold email |
| List size | ≤50 segments ~5.8% vs 1,000+ ~2.1% | Cohort-size guidance |

**Caveat:** these are vendor datasets with selection bias. They are directionally consistent, which is enough for defaults; they are not a guarantee for any vertical.

### 3.5 Email verification

- Catch-all domains accept all addresses, so a "valid" result is not proof of a mailbox. Treat catch-all as **risky**, not verified.
- Stale contacts decay; lists shrink ~22.5%/year (mailfloss). Re-verify on a TTL (30–90 days) and before re-engagement.
- Verification should happen at three points: import, before first send, and on re-engagement.
- Bounce rate target < 2%; above 5% is reputation damage.

**Implication:** add `_crm_email_status` with a TTL and make "verified only" the send default. Do not build a brute-force guesser; infer the pattern from published addresses and verify one candidate.

### 3.6 Agentic outreach safety

The 2025 wave of autonomous SDR agents produced a consistent failure set: prompt injection from scraped pages and replies, hallucinated personalisation, foreign links inserted into copy, and "AI tell" language. The surviving pattern is:

1. treat all external text as data, fenced in the prompt;
2. give the generator no tools or network;
3. verify the output in code before send;
4. hold anything that links outside your domains or echoes instructions;
5. keep a human approval ladder and a kill switch.

This is exactly Sellbird's `untrusted.py` + `gate.py::autopilot_review` design, and it maps onto our existing audit/consent/outbox infrastructure.

---

## 4. Gap analysis against this repository

| # | Gap | Evidence | Why it matters |
|---|---|---|---|
| G1 | No content gate | `send-lead-email.php` → `wp_mail()` after consent/DNC only | A merge-tag bug or 400-word HTML email reaches the prospect |
| G2 | No address status | No `email_status` meta in `crm/**` | "Guess" addresses are treated the same as verified ones |
| G3 | No DNS/warmup/ramp | `WP_MCP_AI_OA_Settings` has a global `daily_cap` only | A cold domain can be burned on day one |
| G4 | Site-clock window | `within_send_window()` uses `current_time()` | A New York prospect gets 02:00 mail from a Colombo site |
| G5 | No bounce loop | No bounce handling | Bad lists never self-correct |
| G6 | No RFC 8058 headers | Footer only | Bulk-sender non-compliance; no one-click |
| G7 | No signal evidence/cohorts | ICP scorer only | Cannot explain why a prospect is on the list |
| G8 | Thin step schema | `channel`, `template_id`, `wait_hours`, `branch_on_reply` | No purpose, length, link or evidence budget |
| G9 | No held state | Channel `auto` dispatches rendered output | Prompt-injected link goes out |
| G10 | Caller-driven opt-out | `process-opt-out.php` takes an identifier | A reply saying "no thanks" is not automatically honoured |
| G11 | No export | Import only | Operators cannot hand a verified list to another sequencer |
| G12 | Weak A/B guard | `min_test_sends` = 10 | Promotes noise |
| G13 | No readiness score | Auto-send can be enabled immediately | Operator has no go/no-go signal |
| G14 | No evidence/disclosure policy | No evidence meta or disclosure template | Personalisation is unverifiable; AI question has no standard answer |
| G15 | Tracking discipline implicit | Link tracker exists | Risk of carrying pixels into cold email |

The full remediation mapping is in the proposal (§3, §5).

---

## 5. Design decisions that differ from Sellbird (and why)

| Decision | Sellbird | NV oOS | Reason |
|---|---|---|---|
| Runtime | Python + SQLite | PHP + WP options/CPTs + one custom table | Platform constraint |
| Model access | Subscription CLI | Provider APIs | No CLI dependency in a plugin |
| Signal storage | SQLite tables | Custom table with post-meta fallback | Cohort queries need indexes; hosts vary |
| Gate location | One module, one process | Shared CRM class called from every send path | Multiple entry points (tools, OA, REST) |
| A/B promotion | Minimum sends | Minimum sends **and** a sequential/confidence guard | Avoid promoting noise; cheap to add |
| Legal check | Optional Precleared API | Filter seam, no vendor | Privacy, cost, provider-agnosticism |
| LinkedIn | Browser automation possible | Approval/webhook only | ToS and account risk |
| Signals | 29, provider-heavy | 12 own-site/free + filter-registered providers | Cost and licensing |
| Send window | Per-recipient TZ | Same, with explicit resolution chain and logging | Correctness and debuggability |

---

## 6. Open questions for implementation review

1. **Custom table vs post meta.** Is `dbDelta()` acceptable on the target fleet, or should P4 ship meta-only first? Recommendation: table with fallback, but confirm with the hosting matrix.
2. **Verification provider.** Do we ship a provider interface only (SMTP/MX + pattern heuristics + optional API), or bundle a default? Recommendation: interface + heuristic; no bundled paid API.
3. **Timezone source.** Country/state from the lead record is often missing. Do we infer from phone country code / domain TLD? Recommendation: explicit → country → phone → site fallback, log the resolution.
4. **Unsubscribe scope.** One-click should cover cold/marketing only. Confirm the `kind` taxonomy (`sequence`, `one_off`, `transactional`, `reply`) before wiring the header.
5. **Held-mail retention.** How long do held rows live? Recommendation: 30 days, then auto-reject with an audit row.
6. **Signal TTLs.** Which signals expire (ads, hiring, rank) vs persist (tech stack, incumbent)? Recommendation: 14 days for volatile, 90 for structural.
7. **Legal defaults by country.** Should `DE` block by default or warn? Recommendation: warn by default, block when the campaign's legal basis is `legitimate_interest` and the country is `DE`.
8. **Existing data.** Do we backfill `_crm_email_status = unknown` for all leads, or treat missing as `unknown`? Recommendation: treat missing as `unknown`; no migration write.

---

## 7. Sources

### Primary (Sellbird)

- Repository README — `https://github.com/ethanplusai/sellbird` (reviewed 2026-11-07).
- `docs/how-it-works.md` — heartbeat, project structure, philosophy, 242 tests.
- `docs/signals-and-pipeline.md` — 29 signals, cohorts, DISCOVER/PROFILE/ENRICH/VERIFY.
- `docs/approval-and-sending.md` — approval ladder, autopilot, held mail, office hours.
- `docs/deliverability.md` — CAN-SPAM, GDPR/PECR, warmup, bounce thresholds.
- `skills/README.md` — skill→agent mapping.
- `skills/email_frameworks.md` — copy rules and 2026 benchmark figures.
- `sellbird/gate.py`, `signals.py`, `pipeline.py`, `main.py`, `send_window.py`, `deliverability.py`, `export.py`, `state.py`.

### Industry

| Topic | Source | Date |
|---|---|---|
| Gmail sender guidelines / spam < 0.3% | `support.google.com/mail/answer/14229414`, `answer/81126` | current 2026 |
| Bulk-sender pass/fail checklist | reachly.co, gmass.co, redsift.com, powerdmarc.com | 2026 |
| Deliverability guide (SPF/DKIM/DMARC, warmup 3–6 weeks, bounce < 2%) | smartlead.ai | 2026 |
| 90%+ deliverability guide (bounce < 2%) | instantly.ai | 2026 |
| 21 B2B deliverability practices (warm 3 weeks) | clay.com | 2026 |
| Warmup best practices | leadhaste.com | 2026 |
| Cold email benchmarks (3.43% median; 4–7 touches; 58/42 split) | instantly.ai benchmark report; lemlist.com | 2026 |
| Length (50–125 words) and list-size data | martal.ca; woodpecker.co | 2026 |
| Email verification (catch-all, re-verify, bounce < 2%) | hunter.io; verified.email; mailfloss.com | 2025–2026 |
| UK B2B / PECR corporate vs sole trader | ico.org.uk B2B marketing guidance; leadistry.co.uk | current |
| Germany UWG §7 prior consent | overloop.com; scrap.io | 2026 |
| CASL / published-address basis | overloop.com legal guide | 2026 |

> Legal note: this document is engineering research, not legal advice. Statutory maxima (e.g. CAN-SPAM) are adjusted annually; verify the current figure before publishing marketing copy that cites one.
