# ArtCraft Live API Map (reverse-engineered 2026-10-11)

Base: `https://api.storyteller.ai` (env override `ARTCRAFT_API_BASE`).
Captured from the `app.getartcraft.com` web client and verified with direct
requests.

## Required request headers

```
Accept: application/json
Content-Type: application/json        (POST only)
Origin: https://app.getartcraft.com   <- REQUIRED, else Cloudflare 404s everything
Referer: https://app.getartcraft.com/ <- REQUIRED
User-Agent: browser-ish (or storyteller-client/1.0)
Cookie: session=<jwt>; visitor=<jwt>  (see SKILL.md auth section)
```

Cloudflare answers with `x-rule-id: rule-1, rule-2` when routing succeeds and
`x-backend-hostname` naming the backend pod — useful when debugging.

## Endpoints

### Session / account

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/session` | `{"success":true,"logged_in":bool,"user":{"core_info":{user_token,username,display_name,gravatar_hash,default_avatar},"user_token","username","display_name","email_gravatar_hash","onboarding":{...},"can_access_studio","maybe_feature_flags":[],"fakeyou_plan","storyteller_stream_plan","can_use_tts","can_use_w2l",…}}` |
| GET | `/v1/credits/namespace/artcraft` | `{"success":true,"free_credits","monthly_credits","banked_credits","sum_total_credits"}` |
| GET | `/v1/billing/active_subscriptions` | `{"success":true,"maybe_loyalty_program","active_subscriptions":[]}` |
| POST | `/v1/web_referrals/record` | fire-and-forget, called on page load |

### Jobs

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/jobs/session` | Full session job list — this is also the poll loop. Job shape below. |

Job object:

```json
{
  "job_token": "jinf_…",
  "request": {"inference_category": "image_generation",
              "maybe_prompt_token": "prompt_…",
              "maybe_model_type": "flux_1_schnell", "…": null},
  "status": {"status": "complete_success",           // or complete_failure|cancelled|dead|pending…
             "maybe_failure_message": null,
             "progress_percentage": 100, "attempt_count": 0},
  "maybe_result": {"entity_type": "media_file",
                   "entity_token": "m_…",
                   "media_links": {
                     "cdn_url": "https://cdn-2.fakeyou.com/media/…",
                     "maybe_thumbnail_template": "https://cdn-2.fakeyou.com/cdn-cgi/image/width={WIDTH},quality=95/media/…",
                     "maybe_video_previews": null}},
  "created_at": "…", "updated_at": "…"
}
```

### Model catalogs (GET, all return `{"success":true,"models":[...]}`)

| Path | Example model fields |
|---|---|
| `/v1/omni_gen/models/image` | `model`, `model_creator`, `full_name`, `text_prompt_supported`, `image_refs_supported`, `image_refs_max`, `aspect_ratio_options[]`, `aspect_ratio_default`, `aspect_ratio_default_when_editing`, `resolution_options[]`, `resolution_default`, `quality_options[]`, `default_quality`, `batch_size_min/max/default` |
| `/v1/omni_gen/models/video` | same shape + duration-related options |
| `/v1/omni_gen/models/audio` | same shape |
| `/v1/omni_gen/models/mesh` | same shape |
| `/v1/omni_gen/models/splat` | same shape |

Captured image models (2026-10-11): `flux_1_dev`, `flux_1_schnell` (free),
`flux_pro_1p1`, `flux_pro_1p1_ultra`, `midjourney_7`, `midjourney_7_niji`,
`midjourney_8`, `nano_banana`, `nano_banana_2`, `nano_banana_pro`,
`gpt_image_1`, `gpt_image_1p5`, `gpt_image_2`, `gpt_image_2p5_flare`,
`gpt_image_2p5_sunburst`, `seedream_4`, `seedream_4p5`, `seedream_5_lite`,
`seedream_5p0_pro`, `seedream_5p0_pro_u`.

### Cost estimation (POST, body `{"model": "<slug>"}` + optional params)

| Path | Response |
|---|---|
| `/v1/omni_gen/cost/image` | `{"success":true,"cost_in_credits","cost_in_usd_cents","is_free","is_unlimited","is_rate_limited","has_watermark","failures_are_refunded"}` |
| `/v1/omni_gen/cost/video` | same; honors `duration_seconds` (e.g. veo_3: 384 → 288 @ 6s) |
| `/v1/omni_gen/cost/audio` | same |
| `/v1/omni_gen/cost/mesh` | same |
| `/v1/omni_gen/cost/splat` | same |

Schema-fishing trick: an invalid `model` returns 400
`Json deserialize error: unknown variant '<x>', expected one of <full enum>` —
the error lists every valid model slug.

### Generation (POST)

| Path | Body | Response |
|---|---|---|
| `/v1/omni_gen/generate/image` | `{"model","text_prompt","idempotency_token":"<uuid4>"}` + optional `aspect_ratio`, `resolution`, `quality`, `image_batch_count`, **`image_media_tokens":["m_…"]` (image-to-image refs)**, `negative_prompt` | `{"success":true,"inference_job_token":"jinf_…"}` |

- `idempotency_token` is mandatory; missing/malformed → 400 "idempotency token
  does not match the expected format".
- Image-to-image: attach refs via `image_media_tokens` (media tokens from
  uploads/library). Verified via the desktop app's `generate_image_command`
  envelope: `{request:{prompt, model, batch_size, frontend_caller,
  frontend_subscriber_id, provider, image_media_tokens:[…]}}` — the API
  accepts the same fields (refs-capable models only; `flux_1_schnell` has no
  ref support).
- Video envelope (from `estimate_video_cost_command`):
  `{model, provider, generation_mode:{type:"text_to_video"}, aspect_ratio:
  "wide_sixteen_by_nine", resolution:"seven_twenty_p", duration_seconds:5,
  bitrate:"normal"}` — generation-mode types seen: `text_to_image`,
  `image_edit` (count:1), `text_to_video`.
- `/v1/omni_gen/generate/{video,audio,mesh,splat}` presumed same pattern
  (verified for cost/models, not yet for submit — test with a free model).
- Then poll `/v1/jobs/session` for the terminal state.

### Uploads

`POST /v1/media_files/upload/image` — **multipart/form-data** fields (in
order): `uuid_idempotency_token` (UUID), `is_intermediate_system_file`
(`"false"`), `maybe_title` (filename-ish), `maybe_visibility` (`"public"`),
`file` (the image bytes, filename + content-type). Response:
`{"success":true,"media_file_token":"m_…"}`. The same shape presumably
serves audio/video uploads (`/v1/media_files/upload/{class}` — unverified).

### Characters

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/characters/session` | `{"success":true,"characters":[],"next_cursor":null}`; character objects carry `character_token` + `name` |
| POST | `/v1/character/create` | body `{uuid_idempotency_token, character_name, character_description, image_media_token, model}` → `{"success":true,"character":{…}}`. Characters are referenced in video prompts as `@CharacterName`. |

### Library / media

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/media_files/list/user/<username>?username=<u>&filter_media_classes=image,video,audio,dimensional,mesh,splat&include_user_uploads=true&page_size=60` | `{"success":true,"results":[media…],"pagination":{"current","total_page_count"}}` |
| GET | `/v1/folders/list_all` | `{"success":true,"folders":[],"maybe_cursor":null}` |
| GET | `/v1/tags/list` | `{"success":true,…}` |
| GET | `/v1/prompt/batch?tokens=<t1>&tokens=<t2>` | `{"success":true,"prompts":[{"token","maybe_model_type","maybe_model_class","maybe_generation_provider","maybe_positive_prompt","maybe_negative_prompt","maybe_generation_mode","maybe_aspect_ratio","maybe_batch_count",…}]}` |

Media object: `token` (`m_…`), `media_class`, `media_type`, `public_bucket_path`,
`public_bucket_url`, `media_links{cdn_url,maybe_thumbnail_template,maybe_video_previews}`,
`maybe_prompt_token`, `creator_set_visibility`, `is_user_upload`, `stats{positive_rating_count,bookmark_count}`,
`created_at`, `updated_at`.

## Notes

- CDN host: `cdn-2.fakeyou.com` (downloads need no cookies).
- The desktop app (Tauri, `ai.artcraft.app`) uses this same backend; its
  Rust core additionally sends `x-build-sha` and reads `x-backend-hostname`
  from responses — neither is required for API calls from the Python server.
- Old paths that are now DEAD (return `<h1>not found</h1>`): `/v1/users/session_info`,
  `/v1/tts/*` (all — TTS is retired from the API), `/v1/voices/*`,
  `/v1/voice_designer/*`, `/v1/characters/create` (singular create is
  `/v1/character/create`), `/v1/prompts/get/*`, `/v1/studio_gen2/generate`.
