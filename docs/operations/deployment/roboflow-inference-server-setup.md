# Roboflow Inference Server Setup — RF-DETR Deployment Guide

**Plugin:** NV oOS (mcp-ai-wpoos) — Pro Vision Analysis toolkit (RF-DETR, v1.1.90)
**Model:** [RF-DETR](https://github.com/roboflow/rf-detr) (Apache 2.0 — nano/small/medium/large, all segmentation sizes, keypoint preview; XL/2XL are PML 1.0)
**Date:** September 2026

---

## Overview

RF-DETR is a state-of-the-art real-time detection transformer (DINOv2 backbone, ICLR 2026, arXiv:2511.09554) exposed through the plugin's Roboflow Inference service. One HTTP client covers three trust tiers:

| Tier | API URL | API key | Image bytes |
|---|---|---|---|
| **Self-hosted** (recommended) | `http://<host>:9001` | none required | stay on your network |
| **Dedicated deployment** | your single-tenant URL | required | go to your dedicated deployment |
| **Serverless Cloud API** | `https://serverless.roboflow.com` | required | go to Roboflow |

The plugin never ships Python or model weights — the Inference server is an
external sidecar, exactly like the media worker.

---

## 1. Self-hosted (Docker, CPU or GPU)

### CPU (works for nano/small on modest hardware)

```bash
docker run -d --name roboflow-inference \
  -p 9001:9001 \
  roboflow/roboflow-inference-server-cpu:latest
```

### GPU (NVIDIA)

```bash
docker run -d --name roboflow-inference \
  -p 9001:9001 \
  --gpus all \
  roboflow/roboflow-inference-server-gpu:latest
```

### Compose snippet (alongside the plugin)

```yaml
services:
  inference:
    image: roboflow/roboflow-inference-server-cpu:latest
    container_name: roboflow-inference
    ports:
      - "9001:9001"
    restart: unless-stopped
```

Verify:

```bash
curl http://localhost:9001/docs
curl -X POST http://localhost:9001/infer/rfdetr-small \
  -H "Content-Type: application/json" \
  -d '{"image":{"type":"url","value":"https://media.roboflow.com/dog.jpg"},"confidence":0.5}'
```

### Model downloads on first use

Self-hosted Inference pulls checkpoint weights from Roboflow on the first
inference per model. Warm the models you plan to use (e.g. `rfdetr-small`,
`rfdetr-seg-small`, `rfdetr-keypoint-preview`) before pointing production
traffic at the server, or run with outbound internet access for the lazy
pull.

---

## 2. Plugin configuration

**Pro → Vision Analysis → RF-DETR (Roboflow)**

| Setting | Self-hosted | Serverless / dedicated |
|---|---|---|
| Inference Endpoint | `http://<host>:9001` (loopback/private hosts only) | `https://serverless.roboflow.com` or your dedicated URL |
| Roboflow API Key | leave empty | your key (sent as the raw `Authorization` header) |
| Detection Model | `rfdetr-small` (default) — nano → large trade accuracy for latency | same |
| Catalog Model | optional fine-tuned model for `rfdetr_catalog_search` | same |
| Allow PML-licensed Models | n/a | enables `rfdetr-xlarge` / `rfdetr-2xlarge` (PML 1.0 — review the license first) |

Enforcement rules (fail-closed):

- Serverless/dedicated without a key → the tool refuses (`wp_mcp_ai_roboflow_missing_api_key`); no request fires.
- Non-loopback endpoints must use HTTPS.
- The endpoint URL passes the plugin SSRF guard (`WP_MCP_AI_URL_Guard`) on every request.

---

## 3. Fine-tuned catalog models

1. Train an RF-DETR model on the Roboflow platform (or locally with
   `pip install rfdetr` — see the [fine-tuning guide](https://blog.roboflow.com/train-rf-detr-on-a-custom-dataset/)).
2. Deploy it to your Inference server (self-hosted pulls it by
   `workspace/project/version`) or a dedicated deployment.
3. Set **Catalog Model** to `myworkspace/myproject/3` (or an alias).
4. Call `rfdetr_catalog_search` — detections carry the checkpoint class names.

---

## 4. Trust, licensing, and privacy notes

- **Apache 2.0** aliases (`rfdetr-nano|small|medium|large`, `rfdetr-seg-*`,
  `rfdetr-keypoint-preview`) are enabled by default.
- **PML 1.0** aliases (`rfdetr-xlarge`, `rfdetr-2xlarge`) require the admin
  consent toggle — PML restricts commercial use.
- Self-hosting keeps user image bytes on-premises; the serverless tier sends
  them to Roboflow. Tool descriptions state which tier is active.
- YOLO alternatives are intentionally out of scope for shipped recipes
  (AGPL-3.0 distribution-hostile for user-hosted inference).

---

## 5. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `wp_mcp_ai_roboflow_http_error` (connection refused) | Inference container not running / wrong port | `docker ps`, verify `:9001` reachability from the PHP host |
| `wp_mcp_ai_roboflow_insecure_endpoint` | plain-HTTP endpoint outside local/private ranges | use HTTPS for non-local hosts |
| `wp_mcp_ai_roboflow_pml_not_enabled` | XL/2XL alias without consent | enable the PML toggle or pick an Apache alias |
| Slow first call | checkpoint download in progress | warm the model once before production traffic |
| `wp_mcp_ai_roboflow_api_error` 404 | wrong model reference | verify alias or `workspace/project/version` against the server's `/docs` |
