# Vision Analysis Toolkit

The Vision Analysis Toolkit adds sensor-free image understanding to NV oOS
Pro: the **Analyze Image Objects** tool detects and counts the objects in an
image and returns a per-category breakdown with confidence scores, optional
bounding boxes, and an optional annotated copy of the image.

## Enable the Toolkit

1. Go to **NV oOS → Vision Analysis** in the WordPress admin.
2. Check **Enable Toolkit** and save.
3. Configure the detector and optional VLM settings (see below).

The toolkit is off by default. When disabled, the tool is not registered and
AI assistants cannot call it.

## The Tool: `analyze_image_objects`

Ask your assistant to "count the objects in this image" or call the tool
directly with an image source.

### Inputs

| Argument | Description |
|---|---|
| `attachment_id` / `file_id` / `url` / `image_url` / `image_data` | Image source (WordPress attachment, URL, or base64 data) |
| `mode` | `hybrid` (default), `detection`, or `vlm` |
| `provider` | `auto`, `huggingface`, `ollama`, `openai`, `anthropic`, `gemini` |
| `model` | Optional explicit model override |
| `categories` | Optional candidate labels (open-vocabulary, up to 100) |
| `min_confidence` | Confidence threshold (0–1, default 0.5) |
| `include_boxes` | Include bounding boxes in the result (default true) |
| `annotate` | Return an annotated image with boxes drawn (default false) |

### Output

```json
{
  "success": true,
  "mode": "detection",
  "provider": "huggingface",
  "model": "google/owlv2-base-patch16",
  "counts": [
    { "label": "person", "count": 3, "avg_confidence": 0.82, "boxes": [ ... ] },
    { "label": "cup", "count": 1, "avg_confidence": 0.7, "boxes": [ ... ] }
  ],
  "total_items": 4,
  "image_url": "https://.../photo.jpg",
  "message": "Found 4 items: person (3), cup (1)."
}
```

### Modes

- **`detection`** — a dedicated detector (HuggingFace OWLv2 by default, or a
  local Ollama vision model) draws boxes and the tool counts them per
  category. This is the most accurate counting path and the only mode that
  produces bounding boxes.
- **`vlm`** — a chat vision model (OpenAI, Anthropic, or Gemini) counts the
  image from a structured JSON prompt. Useful for open-world categories but
  less reliable on dense scenes.
- **`hybrid`** (default) — detection owns the counts; if a VLM is configured,
  it only renames mislabeled categories (it never recounts). If detection
  fails, the tool falls back to VLM counting.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| Detection Model (HF) | `google/owlv2-base-patch16` | HuggingFace object-detection model (OWLv2, YOLO, DETR, …) |
| Min Confidence | 0.5 | Filter detections below this threshold |
| VLM Provider | auto | Preferred chat vision provider for `vlm`/`hybrid` |
| VLM Model | *(empty)* | Explicit chat vision model |
| Annotate by Default | off | Return annotated images by default |
| Max Image Size | 5242880 (5 MB) | Payload cap; oversized images are downscaled first |

## Requirements

- **Detection:** a HuggingFace API key, or a configured Ollama instance with a
  vision-capable model (`llava`, `minicpm-v`, `gemma3`, …).
- **VLM modes:** an OpenAI, Anthropic, or Gemini API key.
- **Annotation:** the PHP GD extension.

## Privacy

When using hosted inference (HuggingFace, OpenAI, Anthropic, Gemini), image
bytes leave your site. Use a local Ollama vision model to keep images
on-premises.

## Related

- `analyze_image` (base tool) — general image description/OCR via chat vision models.
- Extended Cognition Toolkit — camera-based object detection for browser sessions.
