# Licence transitions into `lib/core`

`lib/core` is MIT. The plugin's `includes/` is GPL-3.0-or-later and the Pro addons are
proprietary. GPL may depend on MIT in the direction the repository already uses, but moving
*content* from a GPL file into an MIT package is a relicensing act and is recorded here.

The rule (from [Proposal 145 §7](../project/proposals/145-okf-core-extraction-and-licence-proposal.md)):
a repository is not a licence unit — a distributed artefact is. Per-file SPDX headers identify
which artefact a file belongs to, and `bin/check-license-boundary.sh` asserts the boundary.

## Requirements for an entry

1. The copyright holder must be able to relicense — first-party NV Digital code, not
   third-party GPL content.
2. Any third-party material must be referenced, not reproduced.
3. The file that moves carries a header note naming its previous licence and location.
4. The entry below records source, destination, and what was actually carried over.

---

## 2026-11-13 — Prompt cue catalogue → `Nvoos\Core\Domain\Service\Harness\PromptCueLibrary`

| | |
|---|---|
| **From** | `includes/harness/class-wp-mcp-ai-prompt-cue-library.php` (GPL-3.0-or-later) |
| **To** | `lib/core/src/Domain/Service/Harness/PromptCueLibrary.php` (MIT) |
| **Carried over** | The registry behaviour and the eight seeded cue definitions (slug, label, description, template, task classes, citation). |
| **Not carried over** | The `wp_mcp_ai_register_prompt_cues` action and the `wp_mcp_ai_discovered_cues` option registry — platform extensibility, not catalogue content. They stay in the WordPress library. |

**Why relicensing is available.** The cue text, labels, descriptions and task-class maps were
authored by NV Digital Solutions for this plugin; there is no third-party prose in them.

**Third-party material.** The `citation` fields name papers and patterns
(Wei et al. 2022; Wang et al. 2023; Lewis et al. 2020; Yao et al. 2022; Schick et al. 2023;
OWASP LLM01; NeMo Guardrails). They are bibliographic references — author, year, title,
pattern name — not reproductions of the cited text. Short titles are used descriptively, which
is ordinary scholarly practice, not extraction.

**Verification.** `tests/fixtures/harness/prompt-cues.json` records the WordPress tool's exact
output for fourteen task-class filters, including the full cue text. Both
`lib/core/tests/Unit/Tool/ListPromptCuesToolTest.php` and `tests/test-list-prompt-cues.php`
replay it, so the two catalogues cannot drift silently.

**Known duplication.** `plugins/nvoos-content-graph-ai-platform/src/Harness/PromptCueLibrary.php`
is a second GPL copy of the same library. It is not consumed by this change; consolidating the
two WordPress copies onto the core one belongs to the ecosystem-port track, which can now
compare both against the same fixture.

---

## Pending

Proposal 145's OKF extraction (parser and reader into `lib/core`) is the other intended entry.
Its header notes are in place in the ported files; the entry should be added here when that
track closes its own acceptance list.
