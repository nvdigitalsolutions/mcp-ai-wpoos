# Service Blueprint v1

**Status:** Implemented (Proposal 143, Phase 0)
**Validator:** `examples/nvoos-service-exchange/server/blueprint/validate.js`
**JSON Schema:** `examples/nvoos-service-exchange/blueprints/schema.json`
**Seeds:** `examples/nvoos-service-exchange/blueprints/*.json` (5 professions)

---

## 1. What a blueprint is

A **Service Blueprint** is the unit of software-as-commodity in the Exchange:
an assistant-portability bundle (the service's brain) plus a provisioning and
commerce manifest (how it is deployed, shared, and sold).

```
service-blueprint/
├── bundle       # nvoos-assistant format v1 (portability engine)
├── site         # Cloudways estate shape
├── console      # Complete Console tier (Proposal 083)
├── storefront   # reserved: astro-storefront (092) | forge (096)
├── gateway      # slug visibility + tool allowlist
├── commerce     # Stripe price pointer + trial
└── listing      # catalogue copy
```

**Non-negotiables**

1. **Credentials never travel.** `op_` tokens are minted on the destination
   site; MCP app entries carry `connection_ref` pointers only.
2. **Deep bundle validation belongs to the portability engine.** The Exchange
   validates the envelope (`format: "nvoos-assistant"`, `format_version: 1`,
   `assistants[].title`) and delegates the rest to `parse_import()` at import
   time.
3. **One blueprint ⇒ one estate.** Slugs are globally unique across estates
   and blueprints; collision is a hard error.
4. **Unknown fields are rejected** (`additionalProperties: false`) except
   `_meta`.

---

## 2. Shape

```jsonc
{
  "blueprint_version": 1,
  "name": "Clinic Operations Copilot",
  "slug": "clinic-ops",
  "tagline": "Bookings, stock and compliance for small clinics",
  "industry": "healthcare",
  "icon": "icons/clinic-ops.svg",

  "bundle": {
    "format": "nvoos-assistant",
    "format_version": 1,
    "plugin_version": "1.1.80",
    "assistants": [
      {
        "title": "Clinic Operations Copilot",
        "slug": "clinic-operations-copilot",
        "status": "publish",
        "content": "You are the operations copilot …",
        "meta": {
          "_wp_mcp_ai_tools": [ "get_environment_status" ],
          "_wp_mcp_ai_provider": "openai",
          "_wp_mcp_ai_model": "gpt-4o-mini",
          "_wp_mcp_ai_temperature": 0.2
        }
      }
    ]
  },

  "toolkits": [ "crm" ],
  "mcp_apps": [ { "connection_ref": "mcp_server:primary" } ],

  "site": {
    "region": "us",
    "size_hint": "small",
    "php": "8.1",
    "domain": null,
    "wp_manager": true
  },

  "console": { "enable": true, "template": "complete-velocity" },
  "storefront": { "enable": false, "template": null },

  "gateway": {
    "visibility": "private",
    "tool_allowlist": [ "get_environment_status" ],
    "rate_limit_profile": "standard"
  },

  "commerce": {
    "stripe_price_id": null,
    "trial_days": 14,
    "metered": false
  },

  "listing": {
    "summary": "An operations assistant for small clinics …",
    "use_cases": [ "Booking triage", "Stock alerts" ],
    "pricing_note": "Billed monthly per clinic.",
    "connect_snippets": true
  },

  "_meta": { "author": "exchange-seed", "source": "exchange-seed" }
}
```

---

## 3. Field reference

### Top level

| Field | Type | Required | Rules |
|---|---|---|---|
| `blueprint_version` | int | yes | Must be `1` |
| `name` | string | yes | 3–80 chars |
| `slug` | string | yes | `^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$`, globally unique |
| `tagline` | string | yes | 5–140 chars |
| `industry` | enum | yes | healthcare, food_beverage, professional_services, ecommerce, retail, education, real_estate, hospitality, other |
| `icon` | string | no | ≤200 chars (path/URL) |
| `bundle` | object | yes | Assistant bundle, see §3.1 |
| `toolkits` | string[] | no | ≤50 slugs `^[a-z0-9_]{2,40}$` |
| `mcp_apps` | object[] | no | `connection_ref` required; entries allow only `connection_ref`, `label`, `position` |
| `site` | object | yes | §3.2 |
| `console` | object | no | defaults `{ enable: true, template: "complete-velocity" }` |
| `storefront` | object | no | defaults `{ enable: false, template: null }` |
| `gateway` | object | yes | §3.3 |
| `commerce` | object | no | defaults `{ trial_days: 14, metered: false, stripe_price_id: null }` |
| `listing` | object | yes | §3.4 |
| `_meta` | object | no | Free-form, ≤4 KB policy; the only field exempt from the secret scan |

### 3.1 `bundle` (delegated to the portability engine)

| Field | Required | Notes |
|---|---|---|
| `format` | yes | Must be `"nvoos-assistant"` |
| `format_version` | yes | Must be `1` |
| `assistants` | yes | 1–500 items; each requires `title` (1–200 chars); `slug`, `status`, `content`, `excerpt`, `meta`, `a2a` allowed |

The Exchange does **not** re-validate assistant meta semantics; the engine's
`parse_import()` remains authoritative. Imports are version-tolerant, exports
version-pinned.

### 3.2 `site`

| Field | Type | Default | Rules |
|---|---|---|---|
| `region` | string | — | 2–40 chars |
| `size_hint` | enum | `small` | small / medium / large |
| `php` | string | `8.1` | `^8\.\d$` |
| `domain` | string\|null | `null` | Valid hostname when set; verify ownership before deploy |
| `wp_manager` | bool | `true` | Prefer WP Manager tier; SSH fallback; paste-block last |

### 3.3 `gateway`

| Field | Type | Default | Rules |
|---|---|---|---|
| `visibility` | enum | `private` | public / private |
| `tool_allowlist` | string[] | `[]` | Tool-name slugs; **non-empty required when public** |
| `rate_limit_profile` | enum | `standard` | standard / elevated |

### 3.4 `listing`

| Field | Type | Default | Rules |
|---|---|---|---|
| `summary` | string | — | 10–500 chars |
| `use_cases` | string[] | `[]` | ≤12 items, 3–160 chars each |
| `pricing_note` | string | `""` | ≤300 chars |
| `connect_snippets` | bool | `true` | Generate bridge/ChatGPT/Claude snippets |

### 3.5 Cross-field rules

1. `console.enable: true` requires `console.template: "complete-velocity"`.
2. `storefront.enable: true` requires `template: "astro-storefront" | "forge"`.
3. `gateway.visibility: "public"` requires a non-empty `tool_allowlist`.
4. `commerce.stripe_price_id` must be `null` or match `^price_[A-Za-z0-9]{8,}$`.
5. No key named `token`, `secret`, `password`, `api_key`, `apikey`,
   `client_secret`, `access_token`, `refresh_token`, `oauth_data`,
   `authorization`, `credentials`, `_wp_mcp_ai_credentials` outside `_meta`.
6. No string value may contain a credential-shaped token
   (`op_…/cred_…` with a dot-separated secret half), anywhere.
7. Duplicate `slug` against existing estates/blueprints → validation error
   (`validation_failed`, HTTP 422).

---

## 4. Lifecycle

```
seed (blueprints/*.json)
  → wizard composition (prompt, model, site, gateway, commerce)
  → POST /api/v1/estates  (validate + store)
  → advance × 9           (provisioning state machine)
  → catalogue listing     (listed + advertisable entitlement)
```

Composition is a pure merge: the wizard never mutates the seed on disk; it
produces a new blueprint object for the create call.

---

## 5. Versioning

- **Exports are version-pinned; imports are version-tolerant.** A `v2`
  blueprint will be rejected by v1 validators; the version bump procedure is:
  1. bump `BLUEPRINT_VERSION` and document the delta here;
  2. update `blueprints/schema.json` and the validator together;
  3. keep accepting v1 in `validateBlueprint` for a migration window;
  4. add seed round-trip tests before removing v1 support.
- Bundle format changes follow the portability engine's own procedure
  (`docs/assistant-import-export.md`); the Exchange only tracks
  `format_version`.

---

## 6. Seed catalogue

| Seed | Industry | Gateway | Notes |
|---|---|---|---|
| `clinic-ops` | healthcare | private | CRM toolkit |
| `kitchen-floor-operator` | food_beverage | private | F&B toolkit |
| `client-pipeline-agency` | professional_services | **public** | CRM toolkit |
| `delivery-coach` | professional_services | private | PM toolkit |
| `storefront-merchandiser` | ecommerce | **public** | Woo toolkit |

Seeds are validated and secret-scanned by
`server/blueprint/seeds.test.js`; they are loaded at boot and invalid files
are skipped with a warning (never a boot failure).

---

## 7. References

- Proposal 143 §3.2 (blueprint design) and the implementation plan §4
- `docs/assistant-import-export.md` (bundle format + denylist)
- `includes/assistants/class-wp-mcp-ai-assistant-portability.php`
  (`FORMAT`, `FORMAT_VERSION`, `get_bundle_schema()`)
- `.agents/skills/mcp-ai-wpoos-assistant-portability/SKILL.md`
