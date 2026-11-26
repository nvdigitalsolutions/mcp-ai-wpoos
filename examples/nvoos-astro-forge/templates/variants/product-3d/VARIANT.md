# `product-3d` template variant

An **additive overlay** on the base `blog` template — not a second template.
`templates/blog` stays the single source of truth, so a change to the base
reaches this variant for free.

```bash
nvoos-forge <site> --target ./site --variant product-3d
```

## What it adds

| File | Job |
|---|---|
| `src/lib/product-model.ts` | `resolveProductModel(doc)` — a pure resolver; null when a product has no usable poster |
| `src/components/ModelViewer.astro` | poster-first `<model-viewer>`, zero hydration |
| `public/vendor/README.md` | how to self-host the runtime |

## Authoring

One field on the product document:

```json
{
	"model3d": {
		"src": "/models/aurora.glb",
		"poster": "/models/aurora.svg",
		"alt": "Aurora 3-seater sofa, three-quarter view",
		"ar": false
	}
}
```

`poster` falls back to the product's first image; `alt` falls back to the
product name. `src` and `poster` must be root-relative or `https` — a
protocol-relative (`//`) or any other scheme is rejected by the resolver,
mirroring the sanitizer's rule.

## Wiring it into the product page

```astro
---
import ModelViewer from '../../components/ModelViewer.astro';
import { resolveProductModel } from '../../lib/product-model';

const model = resolveProductModel( product );
---

{ model && <ModelViewer { ...model } /> }
```

Render nothing when `model` is null — the page keeps its normal product image.

## Budget

Textures first, then draw calls, then triangles. A PDP model should stay under
**2 MiB**: compress with KTX2/Basis textures and Draco or meshopt geometry
(`gltf-transform optimize`) before committing the GLB.

## Degradation

If `public/vendor/model-viewer.min.js` is missing or blocked, the element never
becomes `:defined`, CSS keeps it hidden, and the poster remains — no empty box,
no layout shift.

## Why a variant and not a template

A second template would fork every route, layout and token file the forge
maintains, and the two copies would drift. The overlay adds only what the
viewer needs; the base template keeps every fix.
