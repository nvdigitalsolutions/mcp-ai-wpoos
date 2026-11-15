# ADR 0006 — Tiered number normalization

**Status:** Accepted
**Date:** 2026-11-12

## Context

Stage-1 cleanup converts spoken numbers to written ones ("five two nine" →
529). The original implementation converted **every** recognised cardinal,
unconditionally, in every app category:

| Input | Output |
|---|---|
| `one or two things` | `1 or 2 things` |
| `someone said one thing` | `someone said 1 thing` |
| `a thousand dollars` | `a $1000` |

The first two were asserted as correct behaviour by the unit tests. External
review flagged the prose case, and it is the case the inverse-text-
normalization literature warns about: the hard part of ITN is not performing a
rewrite but deciding whether one is warranted, because the same spoken form
can need opposite written forms ("ten to one" as a ratio vs. as an idiom).
Production systems optimise precision — unrecoverable digit substitutions —
rather than conversion rate.

The digit-run case is the one that earns its keep (phone numbers, ids, codes).
The rest was recall-maximising rewriting in the one part of the pipeline the
user is told is deterministic and trustworthy.

## Decision

Normalization runs in four confidence tiers, all behind one policy:

| Tier | Rule | Example | Default |
|---|---|---|---|
| T1 | Digit runs of 3+ single digits | `five two nine` → `529` | always on |
| T2 | Explicit unit present | `twenty dollars` → `$20`, `three minutes` → `3 minutes` | always on |
| T3 | Compound cardinals | `twenty three` → `23`, `one hundred and five` → `105` | on |
| T4 | Bare cardinal, no cue and no unit | `I have one` → `I have 1` | **off** |

Supporting rules:

- **Cues.** A number word immediately preceded by `page`, `line`, `version`,
  `id`, `count`, `step`, `item`, `amount`, `code`, `extension`, `port`, … is
  converted regardless of tier.
- **Idioms.** `one or two`, `one or the other`, `one of`, `one thing`,
  `two things`, `ten to one`, `one another`, `one and all`, `no one`,
  `a thousand times` are protected by byte range and never rewritten — even
  when T4 is enabled.
- **Short digit runs.** A two-word all-digit run (`five nine`) is only
  concatenated when a cue is present; otherwise summing it would produce `14`
  and concatenating it would rewrite ordinary speech.
- **Valid numerals only.** `parse_sequence` rejects sequences that are not
  English numerals. A tens word may not follow a ones word or another tens
  word, so `five thirty` (a time or a score) and `twenty thirty` stay in
  speech instead of becoming `35` and `50`.
- **Digit runs compose with scale words.** `five two nine thousand` is
  529 × 1000 = `529,000`, not `(5+2+9) × 1000 = 16,000`. The digit run is
  concatenated first, then scaled.
- **Formatting** is policy-aware: prose categories group thousands
  (`$1,000`), and a currency conversion drops a preceding `a`/`an` so
  "a thousand dollars" reads as `$1,000`, not `a $1,000`.
- **Categories.** `Code` and `Terminal` skip the rule entirely (identifiers
  matter more than digits).
- **Configuration.** `cleanup.numbers` exposes `enabled`, `bare_small`,
  `in_code`, `thousands_separator`.

## Consequences

- Real output changes for existing users: fewer prose rewrites, grouped
  thousands. The golden fixture (`meeting1.expected.txt`) was corrected twice:
  `$16000` → `$16,000` for grouping, then `$16,000` → `$529,000` because
  `five two nine thousand` was being summed as `(5+2+9) × 1000`. The second
  correction is a behavioural fix, not a formatting one; no other golden case
  changed.
- Two unit assertions were **corrected**, not worked around: `someone said one
  thing` and `a thousand dollars`.
- `one or two things` now survives untouched, and the behaviour is pinned by a
  golden test.
- A bare count stays spoken unless a cue or unit disambiguates it. This is
  deliberately conservative: under-normalising is recoverable by the user,
  over-normalising is silent.
- Normalization is idempotent, and `History` retains the raw ASR text next to
  the cleaned result, so any future rule change is inspectable after the fact.
- The rule stays deterministic and offline. Ambiguity is handled by tiers and
  an idiom list, **not** by a language model: the deterministic path is the
  guarantee that a paste always has text (ADR 0001).
