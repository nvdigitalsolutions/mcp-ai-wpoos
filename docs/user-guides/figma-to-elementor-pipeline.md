# Build a Site from Figma with NV oOS (Figma MCP × Elementor MCP)

> Turns a Figma design into a built WordPress site. The assistant reads the
> design through **Figma MCP** and builds pages on your site through
> **Elementor MCP** — every page landing as a **draft** that you publish.
>
> Requirements: NV oOS Pro (MCP Apps), a Figma account, and a WordPress site
> with Elementor 4.3+ (WP 6.8+) running the Elementor MCP.

## 1. Connect the two servers once

Two ways to run — pick one:

- **Single assistant:** attach both MCP Apps (`figma` + `elementor`) to one
  NV oOS assistant (steps below cover both).
- **Split (Figma MCP on Claude Code):** keep the Figma MCP in your other
  client (Claude Code reads the design there) and attach **only the
  Elementor MCP** to the NV oOS assistant. The design-side agent produces a
  **build manifest** and hands it over via A2A, the NV oOS MCP server
  endpoints, or simply pasting it into the chat — the assistant then builds
  without needing Figma access.

**Elementor MCP (target site):**
1. On the target site: **Elementor → Elementor MCP → Enable MCP access**,
   pick your client tab, generate the prompt. Copy the **server URL** and the
   **application password**.
2. Create a **dedicated WordPress user** with only the role the builds need
   (e.g. Editor) and use *its* application password — the assistant can never
   exceed that user's capabilities.
3. In NV oOS Pro: **Remote Sites → Add Connection → MCP Server (Elementor
   MCP / WordPress MCP Adapter)** → paste the URL → **Basic auth** with
   `username:application-password` (no spaces) → **Test Connection** →
   **Discover Tools**.

**Figma MCP (design side):**
1. NV oOS Pro → assistant editor → **MCP Apps → Add MCP App**, label
   `figma`, server URL `https://mcp.figma.com/mcp`, auth type **OAuth**, then
   complete the OAuth login flow in the MCP Apps tab.
2. **Settings → Security Center → Network & Headers → MCP App Allowed
   Hosts**: add `mcp.figma.com` and your Elementor site's host.

Attach both apps to your assistant (**Add from Remote Sites**), then run
**Test Connection** and **Discover Tools** for each. Full details and
troubleshooting: the `design-elementor-mcp-connection` skill.

## 2. Prepare the design file

Make the Figma file pipeline-ready (the full contract ships with the
`design-figma-to-elementor` skill):

- One top-level frame per WordPress page, named after the page (`Home`,
  `Contact`).
- Direct children of the frame are **sections** in order (`01 Hero`,
  `02 Features`).
- **Colors, fonts, and spacing must be Figma variables** — the assistant
  maps them to Elementor globals. Raw hex values end up hardcoded on your
  site.

## 3. Run the pipeline

Ask the assistant (single-assistant topology):

> "Build the `Home` page from my Figma design and leave it as a draft."

Or, with the split topology, have Claude Code produce the build manifest
from the Figma file and then ask the NV oOS assistant:

> "Build the approved manifest below as a draft page; do not publish."

The assistant then works through seven stages:

1. **Scope** — confirms the file, page, and target site; reports any
   design-file contract violations.
2. **Read** — pulls design context, variables, and screenshots from Figma.
3. **Plan** — presents a **build manifest** (page map, sections, widgets,
   tokens, assets). **You approve it here — nothing is written before this.**
4. **Tokenize** — applies your colors/fonts as Elementor globals.
5. **Build** — creates the page as a draft, section by section, including
   tablet/mobile settings.
6. **Verify** — compares the draft against the design and reports
   differences.
7. **Handoff** — stops. You review the draft in WordPress and **publish it
   yourself**. The assistant never publishes.

## 4. Safety model

- The assistant **only ever writes drafts** — publishing is always yours.
- No Elementor write happens before you approve the build manifest.
- The assistant inherits the WordPress role of the MCP user — keep that user
  least-privilege.
- Every run ends with a receipt: what was built, tokens applied, and usage.

## 5. Troubleshooting quick hits

| Problem | Fix |
|---|---|
| Figma tools missing in chat | Re-run Discover Tools; check the allowlist includes `mcp.figma.com` |
| Figma Test Connection fails | Complete the OAuth flow (paste-back links expire after 10 min) |
| Elementor calls denied | The remote WP user lacks the capability — fix the role on the target site |
| Page has hardcoded colors | The design used raw values — switch them to variables and re-run |

See the `design-figma-to-elementor` skill (Skills page) for the full stage
guide, manifest schema, and troubleshooting table.
