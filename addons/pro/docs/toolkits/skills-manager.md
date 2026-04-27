# Skills Manager

> Pro UI and REST API for managing portable, file-based **agent skills** that follow the
> [agentskills.io specification](https://agentskills.io/specification). Lets administrators
> upload, edit, version, and assign skills to assistants.

| | |
|---|---|
| **Activation** | Auto-loaded with the Pro add-on |
| **Admin location** | NV oOS → Skills (top-level), Skill Research, Skill Settings |
| **Available since** | Pro v1.8.0 |

---

## What it provides

| Component | Class |
|---|---|
| Admin list / upload / editor | `WP_MCP_AI_Skill_Manager_Admin_Page` |
| Skill research admin page | `WP_MCP_AI_Skill_Research_Admin_Page` |
| Skill settings admin page | `WP_MCP_AI_Skill_Settings_Admin_Page` |
| REST controller (CRUD) | `WP_MCP_AI_Skill_Manager_REST_Controller` |

The base plugin already supports **bundled skills**. The Skills Manager Pro module adds:

- An **admin UI** for uploading, editing, and listing skills.
- A **REST API** for programmatic CRUD over skills.
- A **research admin page** that uses an NV oOS assistant to discover and propose new
  skills.
- A **settings page** for storage, validation, and capability mapping.

---

## Skill format

Skills follow the [agentskills.io](https://agentskills.io/specification) specification — a
folder containing a manifest (`SKILL.md` / `skill.yaml`), prompts, scripts, and reference
material that can be attached to any assistant. Bundled skills ship under
`includes/bundled-skills/`.

---

## Activation

The Skill Manager loads automatically when the Pro add-on initializes. The admin pages
appear under the NV oOS top-level menu.

---

## Related docs

- [Pro Toolkits index](README.md)
- [Agent Skills specification](https://agentskills.io/specification)
- [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../../docs/AGENT-MEMORY-COMPLETE-GUIDE.md) — how skills, memory, and assistants relate
