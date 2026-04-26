# Quiz System

> Quiz authoring and submission system with KaTeX-rendered math, JetEngine CCT integration,
> and analytics tools.

| | |
|---|---|
| **Activation setting** | `enable_quiz_system` |
| **Admin location** | NV oOS → Settings → Pro Features → Quiz System |
| **Custom Post Type** | `mcp_ai_quiz` (+ submissions CCT) |
| **Math rendering** | KaTeX v0.16.27 |

---

## What it provides

| Component | Class |
|---|---|
| Quiz CPT | `WP_MCP_AI_Quiz_CPT` (`class-wp-mcp-ai-quiz-cpt.php`) |
| Submissions CCT (JetEngine) | `WP_MCP_AI_JetEngine_Quizzes_CCT` |
| Research & Add admin page | `WP_MCP_AI_Quiz_Research_Page` |
| Settings admin page | `WP_MCP_AI_Quiz_Settings_Page` |

### Tools (selected)

- `create_quiz`, `delete_quiz` — quiz CRUD
- `get_quiz_submissions`, `get_quiz_results`, `get_quiz_analytics` — review and reporting
- LaTeX/math equations are rendered via KaTeX both server-side and client-side.

### Use cases

- STEM courses and assessments with rich mathematical notation.
- Internal certification or onboarding quizzes that need analytics.
- Lead-magnet quizzes embedded in marketing pages.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Quiz System** under **NV oOS → Settings → Pro Features**.
3. (Optional) Activate JetEngine to enable the submissions CCT for richer queries and
   front-end rendering.

---

## Related docs

- [Pro Toolkits index](README.md)
- [ECA Management](eca-management.md) — student-side activity tracking that pairs well with quizzes
