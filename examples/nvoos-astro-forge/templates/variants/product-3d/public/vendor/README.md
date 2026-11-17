# public/vendor — self-hosted third-party runtimes

This directory ships **empty on purpose**. The `product-3d` variant expects one
file here:

```
public/vendor/model-viewer.min.js
```

## Why self-hosted

A `<script src="https://cdn...">` would:

- widen the generated project's CSP (`public/_headers`) to a third-party origin,
- add a render-blocking request to someone else's uptime,
- and fail the forge's own policy posture, which is to own every origin the page
  loads from.

## Vendoring it

```bash
npm i model-viewer
cp node_modules/@google/model-viewer/dist/model-viewer.min.js public/vendor/
```

`@google/model-viewer` is Apache-2.0 — compatible with a GPL or MIT site.

## Until you do

The viewer degrades exactly as designed: `<model-viewer>` never becomes
`:defined`, so CSS keeps it hidden and the **poster image stays visible**. No
empty box, no layout shift, no broken page.
