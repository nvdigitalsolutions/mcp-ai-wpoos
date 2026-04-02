# NVIDIA NIM Provider Setup Guide

**Last Updated:** April 2, 2026  
**Plugin Version:** 1.1.5

## Overview

NVIDIA NIM (NVIDIA Inference Microservices) provides access to optimized AI models via NVIDIA's cloud inference platform. The plugin supports 40+ models including Meta Llama, Mistral, NVIDIA Nemotron, Google Gemma, Qwen, DeepSeek, and IBM Granite families.

NVIDIA NIM uses an OpenAI-compatible API, making it a drop-in alternative for chat completions.

## Quick Start

### 1. Get an API Key

1. Visit [build.nvidia.com](https://build.nvidia.com/)
2. Sign up for or log in to your NVIDIA account
3. Generate an API key for NIM access
4. Your key will start with `nvapi-`

### 2. Configure in WordPress

**Option A: Getting Started Wizard (Recommended for new installs)**

1. Navigate to **NV oOS → Getting Started**
2. On Step 2, click the **NVIDIA NIM** tab
3. Enter your API key
4. Click **Test Connection** to verify
5. Continue with the wizard

**Option B: Settings Page**

1. Navigate to **Settings → NV oOS → Providers**
2. Click the **NVIDIA** tab
3. Check **Enable NVIDIA NIM Provider**
4. Enter your **NVIDIA API Key**
5. (Optional) Change the **Endpoint URL** for self-hosted NIM containers
6. Select a **Default Model** from the dropdown
7. Save settings

### 3. Test the Connection

Use the **Provider Diagnostics** page (**Settings → NV oOS → Provider Diagnostics**) to verify your NVIDIA NIM connection is working. The diagnostic will confirm:
- API key validity
- Endpoint connectivity
- Model availability

## Available Models

NVIDIA NIM provides access to 40+ optimized models. Popular choices include:

| Model | Context Window | Best For |
|-------|---------------|----------|
| `meta/llama-3.3-70b-instruct` | 128K | General-purpose, high quality |
| `meta/llama-3.1-8b-instruct` | 128K | Fast, cost-effective |
| `nvidia/nemotron-70b-instruct` | 32K | NVIDIA-optimized reasoning |
| `mistralai/mistral-large-2-instruct` | 128K | Multilingual, code |
| `google/gemma-3-27b-it` | 128K | Efficient, open-source |
| `qwen/qwen3-235b-a22b` | 131K | Large MoE model |
| `deepseek-ai/deepseek-r1` | 128K | Reasoning, math |

Browse the full catalog at [build.nvidia.com](https://build.nvidia.com/).

## Self-Hosted NIM Containers

NVIDIA NIM also supports self-hosted deployment via Docker containers, ideal for:

- **Privacy-sensitive data** that must stay on-premises
- **Dedicated GPU infrastructure** for consistent performance
- **Air-gapped environments** without internet access

To use a self-hosted NIM container:

1. Deploy a NIM container following [NVIDIA's documentation](https://docs.nvidia.com/nim/)
2. In **Settings → NV oOS → Providers → NVIDIA**, change the **Endpoint URL** to your container (e.g., `http://localhost:8000/v1`)
3. Your API key may still be required depending on container configuration

## Settings Reference

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Enable Provider | `enable_nvidia` | `false` | Toggle NVIDIA NIM on/off |
| API Key | `nvidia_api_key` | (empty) | Your `nvapi-` prefixed key |
| Endpoint URL | `nvidia_endpoint_url` | `https://integrate.api.nvidia.com/v1` | Cloud or self-hosted endpoint |
| Default Model | `nvidia_model` | (empty) | Model ID for default requests |

## Privacy & Data Usage

- **Cloud endpoint:** Chat messages and prompts are sent to `integrate.api.nvidia.com`. Review [NVIDIA's Privacy Policy](https://www.nvidia.com/en-us/about-nvidia/privacy-policy/) for data handling details.
- **Self-hosted NIM:** Data stays on your infrastructure — no external transmission.
- **Terms:** [NVIDIA AI Enterprise EULA](https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/)

## Related Files

- `includes/class-wp-mcp-ai-nvidia-client.php` — NVIDIA NIM API client
- `includes/infrastructure/providers/class-wp-mcp-ai-nvidia-provider-client.php` — Provider interface implementation
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` — Settings UI (NVIDIA tab)
- `includes/admin/class-wp-mcp-ai-provider-diagnostics.php` — Connection diagnostics
- `includes/admin/class-wp-mcp-ai-onboarding-wizard.php` — Getting Started wizard

## Troubleshooting

### "NVIDIA NIM provider is not enabled"
Enable the provider in **Settings → NV oOS → Providers → NVIDIA** by checking the enable checkbox.

### "NVIDIA API key is not configured"
Enter your API key in the NVIDIA provider settings. Keys start with `nvapi-`.

### "NVIDIA client class not found"
Ensure the plugin files are complete. Try deactivating and reactivating the plugin.

### Connection test fails
1. Verify your API key is correct and active at [build.nvidia.com](https://build.nvidia.com/)
2. Check that your server can reach `integrate.api.nvidia.com` (not blocked by firewall)
3. If using a self-hosted NIM, verify the container is running and accessible
