# NV oOS Chat SPA

React SPA addon for NV oOS, scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md).

## Quick start

```bash
cd addons/chat-spa
npm ci
npm run build       # produces assets/dist/chat-spa.{js,css}
```

Add `[nvoos_chat_spa_app]` to any post or page.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-chat-spa.php`
2. `define( 'NVOOS_CHAT_SPA_VERSION', '…' );`
3. `"version"` in `package.json`

This forces `?ver=` query strings to invalidate browser caches.

## REST namespace

`/wp-json/nvoos-chat-spa/v1/*` — see [`includes/rest/class-nvoos-chat-spa-rest.php`](includes/rest/class-nvoos-chat-spa-rest.php).

## Credits

This addon is a scaffold only — no third-party SPA libraries are bundled by
default beyond React. When adding upstream packages, update:

- [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
- The root [`CREDITS.md`](../../CREDITS.md)
- This Credits section
