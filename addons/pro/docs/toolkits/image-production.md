# Image Production Toolkit

> AI-powered image creation and manipulation: generation, variations, upscaling,
> background removal, inpainting, artistic styles, format and platform optimization.

| | |
|---|---|
| **Activation setting** | `enable_image_production_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Image Production |
| **Tools** | 15 |

---

## Tools

| Tool slug | Purpose |
|---|---|
| `generate_image_ai` | AI text-to-image generation |
| `generate_image_variations` | Variations of an existing image |
| `text_to_image_prompt_optimizer` | Improve prompts for better generations |
| `upscale_image_ai` | AI upscaling |
| `enhance_image_quality` | Denoise / sharpen / enhance |
| `colorize_image` | Colorize black-and-white images |
| `apply_artistic_style` | Style-transfer effects |
| `image_inpainting` | Fill / replace regions |
| `remove_image_background` | Background removal |
| `resize_image_smart` | Content-aware resize |
| `convert_image_format` | Format conversion |
| `compress_image` | Lossy / lossless compression |
| `optimize_for_web` | Web optimization preset |
| `generate_responsive_images` | Generate srcset variants |
| `batch_process_images` | Apply a recipe across many images |

Tool source: `addons/pro/includes/tools/image-production/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Image Production** under **NV oOS → Settings → Pro Features**.
3. Configure an image-generation provider (OpenAI Images, Stability AI, etc.) on the
   toolkit settings page.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/image-production/README.md`](../../includes/tools/image-production/README.md)
- [Media Toolkit](media.md) — pairs well for post-generation processing
