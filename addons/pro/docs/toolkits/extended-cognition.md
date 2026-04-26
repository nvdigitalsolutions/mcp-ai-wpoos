# Extended Cognition Toolkit

> Sensor inputs (camera, microphone, screen, motion) for AI agents — grounded in
> Clark & Chalmers' (1998) extended-mind theory. Lets an assistant "see," "hear," and
> remember sensory context with the user's explicit permission.

| | |
|---|---|
| **Activation setting** | `enable_extended_cognition_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Extended Cognition |
| **Tools** | 7 |
| **Available since** | Pro v1.0.0 |
| **Status** | ⚠️ **Records biometric / environmental data — review your jurisdiction's privacy laws (GDPR, CCPA, BIPA, etc.) before enabling.** |

---

## What it provides

| Tool slug | Purpose |
|---|---|
| `ext_cog_capture_visual` | Capture a still image from the user's camera |
| `ext_cog_capture_audio` | Capture an audio clip from the microphone |
| `ext_cog_capture_screen` | Capture the user's current screen |
| `ext_cog_get_motion_context` | Read accelerometer / gyroscope / orientation if available |
| `ext_cog_analyze_sensory_input` | Run an AI analysis pass over a captured artifact |
| `ext_cog_remember_sensory_context` | Persist a captured artifact + analysis to a sensor session |
| `ext_cog_manage_sensor_permissions` | Grant / revoke per-session sensor permissions |

Each capture tool is gated on:

1. The toolkit being enabled in plugin settings.
2. A live, user-granted browser-level permission (`navigator.mediaDevices.*` etc.).
3. The session-level permission record managed by `ext_cog_manage_sensor_permissions`.

---

## Components

| Component | Class / file |
|---|---|
| Sensor session model | `WP_MCP_AI_Ext_Cog_Sensor_Session` (`includes/class-wp-mcp-ai-ext-cog-sensor-session.php`) |
| REST controller | `WP_MCP_AI_Ext_Cog_REST` (`includes/rest/class-wp-mcp-ai-ext-cog-rest.php`) |
| Admin settings | `WP_MCP_AI_Ext_Cog_Settings` (`includes/admin/class-wp-mcp-ai-ext-cog-settings.php`) |
| Helper | `wp_mcp_ai_pro_is_extended_cognition_enabled()` |

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Extended Cognition Toolkit** under **NV oOS → Settings → Pro Features**.
3. Visit **NV oOS → Settings → Extended Cognition** to configure retention, allowed
   sensor types, and per-role permissions.

---

## Privacy & compliance — required reading

This toolkit can record biometric and environmental data. Before enabling on any site:

- **Consent.** Use `ext_cog_manage_sensor_permissions` to record per-session consent. Do
  not bypass the browser permission prompt; surfaces that suppress it are non-compliant.
- **Retention.** Configure a short retention window in settings; the toolkit deletes
  expired captures via WP-Cron.
- **Storage.** Captures live as private attachments. Treat them like personal data: limit
  who can read them via WordPress capabilities, encrypt backups, and prefer S3-style
  buckets with server-side encryption.
- **Jurisdiction-specific notes:**
  - **GDPR / UK GDPR** — captures are likely "special category" data. Have a lawful basis
    and a Data Protection Impact Assessment.
  - **BIPA (Illinois)** — written consent is required for biometric identifiers.
  - **CCPA / CPRA (California)** — disclose collection, allow deletion.
  - **HIPAA** — if used in a clinical context, treat captures as PHI; the
    [Healthcare Imaging](healthcare-imaging.md) and [Health & Wellness](health-wellness.md)
    toolkits are better fits for clinical workflows.

The toolkit is intentionally scoped to user-facing assistive use cases (accessibility,
field-service, hands-free operation). It is **not** a covert-surveillance tool, and the
license terms prohibit such use.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`AGENTS.md`](../../../AGENTS.md) — agent context
- [`addons/pro/README.md`](../../README.md) — Pro overview & licensing
