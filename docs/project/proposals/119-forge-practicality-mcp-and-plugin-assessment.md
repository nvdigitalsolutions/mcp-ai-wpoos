# Proposal 119 — Practicality, MCP surface, and the base+pro analysis track

Status: assessment + MCP surface shipped (2026-11-06) · Determinations only for the plugin track

Three questions from the jayainc session, answered with industry research and
codebase grounding. The forge MCP addition (`design_verify_visual`) ships
with this proposal; the base+pro plugin work is scoped but deliberately not
implemented here (see §3).

## §1 Is the forge approach practical? — Yes, and the industry agrees

**The architecture is the 2026 mainstream.** Headless WordPress + Astro is
the established content-site pattern: "WordPress stays the content layer,"
Astro is "the premier choice for content-centric builds" with zero-JS static
output, and the API choice is WPGraphQL-or-REST with build-time fetching
(Astro's official WordPress guide; multiple 2026 architecture guides —
InstaWP, hostwp.io, forminit). The forge's specific choices — REST-first
(never WPGraphQL), content-layer loaders at build time, webhook-triggered
rebuilds, a mandatory sanitizer — are all defensible, standard selections.

**Rebuild-not-replicate is the legally correct line.** The industry
consensus: following similar design patterns is best practice; wholesale
copying is infringement ("taking one wholesale and changing the logo is
most definitely intellectual property theft" — r/Frontend consensus,
Bliss Drive, Thomas Digital, 99designs). The forge's contract — extract the
design LANGUAGE (tokens, pairings, rhythm), carry the customer's own content,
ship an adaptation brief + licensing notes — is precisely the
inspiration→adaptation discipline the sources recommend. Pixel-cloning
Elementor markup would be both unmaintainable AND legally riskier.

**Fidelity across infinite builder markup is the real cost, and it is now
measurable.** Every widget family adds flattening cases (jayainc's aux_text
cards). The learned-vocabulary classifier (116) + structured-item extraction
(117) handle unknown markup without a forge release, and the vision layer
(118) turns "looks wrong" into a number: 60/100 with concrete divergences on
jayainc. That closes the loop: build → measure → fix → re-measure.

**Honest caveats (state them to clients):** the rebuild is design-language
parity, never pixel parity — by design and by law. Chrome (header/footer
templates) is not yet extracted. Vision models catch big changes and MISS
small ones while sounding confident ("LLMs see, but they're sightless" —
dsplce.co, 2026), so vision stays advisory and the deterministic gates
(parity score, WCAG audit, unit tests) remain the regression guards.

**Pilot economics:** jayainc (a one-pager, 0 posts) now forges to a
deployable, AA-compliant, content-fresh static site in ~3 min CI with
automatic mirror deploys. The unit economics work for small sites; the
per-site operator pass (profile curation, chrome) is the open cost.

## §2 Should the forge include an MCP server? — It already does (and now covers vision)

The forge ships a full MCP surface (Phase 4): 30-tool registry, Streamable
HTTP (`forge-mcp.mjs`) + stdio, dual-era serving, fail-closed write auth,
rate limits, SSRF guard. Industry standard for agent-facing CLIs in 2026 is
exactly this shape (ship the tool, then ship its MCP mount). What was
missing after 117/118:

- `design_apply` now reports the **section-background pass** (117) in its
  payload — no registry change needed, the data was already flowing.
- **`design_verify_visual`** (new, this proposal) — the 118 vision
  comparison over MCP: source screenshot via the capture endpoint or a
  dropped artifact, built screenshot via a dropped artifact, verdict
  (fidelity + divergences) returned. Read-only, vision env-configured,
  fail-closed errors when unconfigured.
- The CLI `verify --visual` remains the human/CI path; the MCP tool is the
  agent path. Same libs, no drift.

## §3 Should the base+pro analyze tools be enhanced? — Yes, but don't duplicate: mesh, then port cheaply

The plugin's site-creator-toolkit (`analyze_competitor_sites`,
`extract_site_design_from_mockups`, the section-builder family) and the
forge's analyzer (`lib/design.ts` → `design_apply`) overlap in intent but
differ in depth: the forge's is deterministic (roles + confidence +
unassigned pool + section backgrounds + WCAG gate), the plugin's is
LLM-descriptive. The forge fixes of the last three sessions (structured-item
classification, section theming, vision verification) have no plugin
equivalent.

**Determination — three layers, in order:**

1. **Mesh (recommended, no code):** the base+pro assistant consumes the
   forge MCP as a peer (the existing peer/mesh machinery). `design_apply`,
   `design_verify`, `design_verify_visual` become plugin capabilities by
   connection, not by port. No analyzer drift ever again.
2. **Slim deterministic port (only if self-containment is required):** the
   cheap, dependency-free parts port cleanly to PHP — the section-background
   pass (DOM walk for `elementor-top-section` ids + regex over the page CSS
   files) and the WCAG contrast gate (pure math). ~200 lines of PHP, no
   LLM. The structured-item classifier (htmlparser2-based) does NOT port
   cheaply — leave it in the forge.
3. **Do NOT port:** the vision layer (PHP would re-implement the
   OpenAI-compatible contract; the forge's is provider-agnostic) and the
   classifier. Both stay forge-side; the plugin reaches them through the
   mesh or the forge CLI.

**Also warranted (plugin-side, small):** `analyze_competitor_sites` should
report WCAG contrast failures the way the forge's token gate does — the
plugin currently describes palettes without the 4.5:1 audit, which is the
single most actionable client-facing fact.

## References

- Astro + WordPress: docs.astro.build/en/guides/cms/wordpress; InstaWP
  "Make WordPress Headless with Astro (2026)"; hostwp.io Astro–WordPress
  guide; forminit "Headless WordPress in 2026: Build-to-Deploy Guide";
  gohighlevel "Headless WordPress in 2026".
- Design adaptation vs copying: Bliss Drive; Thomas Digital; r/Frontend
  "Is it legal to copy the visual design of a website"; NYU JIPEL
  "Protecting the Look and Feel of Websites"; 99designs IP guide.
- Vision-assisted UI review: digitalapplied.com "Screenshot-Driven UI
  Development With Vision Models (2026)" (keep LLM review distinct from
  deterministic pixel regression); dsplce.co "LLMs see, but they're
  sightless"; TestMu AI "Smart Visual Testing with LLMs"; trilogyai on
  Percy/Applitools vs LLM prototypes; dev.to mobile visual regression 2026.
