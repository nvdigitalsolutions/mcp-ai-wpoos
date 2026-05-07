# NV oOS Toolkit Shell

> **Status:** Phase 0 reference implementation — manifest-driven shell.
>
> One bundle, many surfaces. Add a JSON manifest under
> `addons/pro/config/spa-manifests/<slug>.json` and the shell automatically
> exposes a list/table view backed by the toolkit's existing `mcp-ai-pro/v1`
> REST endpoints.

This addon is the canonical Phase 1 implementation of the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md). Use it
as the reference when building your own toolkit-SPA addon, or simply add a
manifest for the next Pro toolkit and reuse this bundle.

## Quick start

```bash
cd addons/toolkit-shell
npm ci
npm run build       # produces assets/dist/toolkit-shell.{js,css}
```

Embed the SPA on any page:

```
[nvoos_toolkit_app toolkit="crm"]
[nvoos_toolkit_app toolkit="crm" view="kanban" theme="dark"]
```

Or use the Gutenberg block **NV oOS Toolkit**.

## Manifest format

See `config/spa-manifests/example.json` for a minimal stub, and
`addons/pro/config/spa-manifests/crm.json` for the canonical CRM manifest
shipped with this PR.

Validation rules and the full schema live in
[`docs/addons/toolkit-spa-blueprint.md`](../../docs/addons/toolkit-spa-blueprint.md)
§11.

## REST namespace

`/wp-json/nvoos-toolkit-shell/v1/*` — see
[`includes/rest/class-nvoos-toolkit-shell-rest.php`](includes/rest/class-nvoos-toolkit-shell-rest.php).

| Route | Method | Purpose |
|-------|--------|---------|
| `/manifests` | GET | List loaded manifest summaries (auth: `read`) |
| `/manifests/{toolkit}` | GET | Get a single manifest (auth: manifest's declared `capability`) |
| `/health` | GET | Diagnostics (auth: `manage_options`) |

Domain data still flows through the Pro toolkits' own
`/wp-json/mcp-ai-pro/v1/*` endpoints. The shell never duplicates the data
plane.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-toolkit-shell.php`
2. `define( 'NVOOS_TOOLKIT_SHELL_VERSION', '…' );`
3. `"version"` in `package.json`

## Filters

| Filter | Purpose |
|--------|---------|
| `nvoos_toolkit_shell_can_render` | Return `false` to suppress shortcode output. |
| `nvoos_toolkit_shell_manifest_dirs` | Add custom directories to the manifest search path. |
| `nvoos_toolkit_shell_manifest` | Mutate a manifest just before it is returned to the SPA. |

## Credits

This addon depends on the following third-party libraries — see
[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) for full license text.

- [React](https://github.com/facebook/react) — MIT
- [React DOM](https://github.com/facebook/react) — MIT
- [esbuild](https://github.com/evanw/esbuild) (devDep) — MIT
- [TypeScript](https://github.com/microsoft/TypeScript) (devDep) — Apache-2.0

The addon is licensed GPLv3 to match the base NV oOS plugin.
