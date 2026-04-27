# Media Toolkit

> Hardware-accelerated image processing powered by **Sharp** (libvips). Resize, crop,
> rotate, format-convert, color-manipulate and batch-process images at production speed.

| | |
|---|---|
| **Activation setting** | `enable_media_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Media |
| **NPM** | `sharp` v0.33.5 |
| **Custom Post Type** | Media Collections, Media Templates |

---

## What it provides

Sharp-backed tools for high-throughput image work:

- Resize, crop, rotate, flip, flatten
- Format conversion (JPEG, PNG, WebP, AVIF, TIFF)
- Color manipulation, effects, filters
- Batch processing across collections
- Template-driven transformations (`apply_media_template`,
  `create_media_template`, `apply_collection_template`)

The toolkit also registers two CPTs:

| CPT slug | Purpose |
|---|---|
| `mcp_ai_media_collection` | A grouping of attachments processed as a unit |
| `mcp_ai_media_template` | A reusable transformation recipe |

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Media Toolkit** under **NV oOS → Settings → Pro Features**.
3. Ensure libvips is available on the host (Sharp auto-installs prebuilt binaries on most
   platforms; otherwise `apt install libvips-dev` / `brew install vips`).

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/media-toolkit-README.md`](../media-toolkit-README.md)
- [`addons/pro/docs/media-toolkit-architecture.md`](../media-toolkit-architecture.md)
- [`addons/pro/docs/media-toolkit-tools-guide.md`](../media-toolkit-tools-guide.md)
- [`addons/pro/docs/media-toolkit-tutorials.md`](../media-toolkit-tutorials.md)
