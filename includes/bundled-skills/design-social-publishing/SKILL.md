---
name: design-social-publishing
description: Publish and schedule content to social media platforms (Twitter/X, Facebook, Instagram, LinkedIn) via the MCP bridge schedule_social_post tool as primary. The Design Stack media worker API is the fallback for advanced platform management. Covers platform-specific formatting, media attachment, scheduling, and WordPress-to-social publishing workflows.
---

# Social Media Publishing

Use this skill when publishing or scheduling content to social media platforms. **MCP bridge's `schedule_social_post` is the primary publishing tool.**

## MCP Bridge First

**This skill assumes you are using the MCP bridge.** The WordPress plugin provides:

| MCP Tool | What it does |
|---|---|
| `schedule_social_post` | Schedule or immediately publish content to multiple platforms with media, hashtags, and links |

**Use this tool for all publishing.** The media worker (`http://localhost:3100/api/social/post`) is a fallback for advanced scenarios or when the MCP tool doesn't cover a specific need.

## WP-CLI Commands

Key `mcp-ai` WP-CLI commands for publishing workflows. Run via `terminal`:

| Command | Use for |
|---|---|
| `wp mcp-ai cron` | Inspect scheduled social posts and cron events |
| `wp mcp-ai health` | Check platform API credentials before publishing |
| `wp mcp-ai log` | View publishing success/failure logs |

## When to use this skill

Trigger when ANY of the following is true:

- The task mentions "post to Twitter", "share on Facebook", "publish to Instagram", or "post on LinkedIn".
- Scheduling posts for future publication.
- Integrating WordPress content with social media distribution.
- Handling social media API rate limits and errors.

## Publishing via MCP (Primary)

Use `schedule_social_post` to publish or schedule posts:

```
Parameters:
  content        — Post text/content (required, 1–5000 chars)
  platforms      — Target platforms (required): ["facebook", "instagram", "twitter", "linkedin", "tiktok", "pinterest"]
  scheduled_time — ISO 8601 datetime, or "optimal" for best-engagement timing
  timezone       — Timezone for scheduled time (default: WordPress timezone)
  media_urls     — Array of image/video URLs to attach
  hashtags       — Array of hashtag strings
  link           — URL to include in the post
  recurrence     — "none" (default), "daily", "weekly", "monthly"
  recurrence_end — End date for recurring posts (ISO 8601)
  get_optimal_timing — Set true to get optimal times without scheduling
```

### Examples

**Basic post to Twitter and LinkedIn:**
```
content: "Just launched our new brand identity!"
platforms: ["twitter", "linkedin"]
```

**Post with image, hashtags, and link:**
```
content: "Check out our latest case study on brand design"
platforms: ["twitter", "linkedin", "facebook"]
media_urls: ["https://example.com/hero-image.jpg"]
hashtags: ["#BrandDesign", "#CaseStudy"]
link: "https://example.com/case-study"
```

**Schedule for optimal time:**
```
content: "Weekly design tips thread 🧵"
platforms: ["twitter"]
scheduled_time: "optimal"
```

**Schedule for specific time:**
```
content: "Product launch announcement!"
platforms: ["instagram", "facebook", "linkedin"]
scheduled_time: "2026-08-15T09:00:00Z"
timezone: "America/New_York"
```

**Recurring weekly post:**
```
content: "Monday Motivation: Design inspiration of the week"
platforms: ["instagram"]
recurrence: "weekly"
recurrence_end: "2026-12-31T00:00:00Z"
```

**Check optimal timing without scheduling:**
```
get_optimal_timing: true
```
Returns suggested posting times per platform based on audience engagement patterns.

## Platform capabilities

| Platform | Text | Image | Video | Carousel | Scheduling |
|---|---|---|---|---|---|
| **Twitter/X** | ✅ 280 chars | ✅ up to 4 | ✅ 140s | ❌ | ✅ |
| **Facebook** | ✅ 63K chars | ✅ | ✅ | ✅ | ✅ |
| **Instagram** | ✅ 2.2K | ✅ | ✅ (reels) | ✅ | ✅ (business) |
| **LinkedIn** | ✅ 3K | ✅ | ✅ | ❌ | ✅ |
| **TikTok** | ✅ 2.2K | ✅ | ✅ | ❌ | ✅ |
| **Pinterest** | ✅ 500 | ✅ | ✅ | ❌ | ✅ |

## Platform-specific formatting

### Twitter/X

- Max 280 characters (URLs count as 23 chars regardless of length).
- Threads: reply to your own tweet for multi-part content.
- Media: up to 4 images or 1 video per tweet.
- Hashtags: 1–2 maximum, placed at end.

### Facebook

- Long-form content works well (unlimited in practice).
- Link posts auto-generate preview cards with og:image.
- Video: native uploads outperform YouTube links in algorithm.
- Best times: Wed 11am, Thu 1–3pm.

### Instagram

- Hashtags: up to 30 (optimal 5–15), placed in first comment or end of caption.
- Carousels: up to 10 images/videos, swipeable.
- Stories: 15s per slide, vertical (9:16).
- Alt text: always add for accessibility.

### LinkedIn

- Professional tone, 1,900–2,000 characters optimal.
- No hashtags in body (place at end, 3–5 max).
- Documents (PDF carousels) outperform images.
- Best times: Tue–Thu 8–10am.

## Media worker fallback

For advanced scenarios or when debugging API connections, use the media worker:

### Check connected platforms
```javascript
const response = await fetch('http://localhost:3100/api/social/accounts');
const { platforms } = await response.json();
// [{ id: 'twitter', name: 'Twitter / X', connected: true }, ...]
```

### Direct post via media worker
```javascript
const response = await fetch('http://localhost:3100/api/social/post', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    platform: 'twitter',
    content: 'Just launched our new brand identity! 🎨✨',
    hashtags: '#BrandDesign #CreativeStudio #NewLook',
  }),
});
```

### Post with media (media worker)
```javascript
const response = await fetch('http://localhost:3100/api/social/post', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    platform: 'twitter',
    content: 'Fresh design hot off the press! 🔥',
    media_url: 'https://example.com/generated-image.jpg',
  }),
});
```

## Setup requirements (media worker / backend)

### Twitter/X API v2

```
Required env vars:
  TWITTER_API_KEY        — Consumer API key
  TWITTER_API_SECRET      — Consumer API secret
  TWITTER_ACCESS_TOKEN    — OAuth 2.0 access token
  TWITTER_ACCESS_SECRET   — OAuth 2.0 token secret

Get them at: https://developer.twitter.com/en/portal/dashboard
Free tier: 1,500 tweets/month, 1 app, 1 user.
```

### Facebook / Instagram Graph API

```
Required env vars:
  FACEBOOK_APP_ID         — App ID
  FACEBOOK_APP_SECRET     — App secret
  FACEBOOK_PAGE_TOKEN     — Long-lived page access token
  INSTAGRAM_TOKEN         — Instagram Business account token

Get them at: https://developers.facebook.com/
Requirements: Facebook Page + Instagram Business/Creator account.
```

### LinkedIn

```
Required env vars:
  LINKEDIN_CLIENT_ID      — App client ID
  LINKEDIN_CLIENT_SECRET  — App secret
  LINKEDIN_TOKEN          — OAuth 2.0 access token
  LINKEDIN_PERSON_URN     — Your LinkedIn person URN

Get them at: https://www.linkedin.com/developers/
```

## WordPress → Social workflow

1. Create "Content Calendar" custom post type with fields: platform, scheduled_date, status, media_attachment.
2. Generate visuals using `generate_gemini_image_validated` (MCP).
3. Optimize images using `resize_image` (MCP) for each platform's dimensions.
4. Use `schedule_social_post` (MCP) to publish or schedule.
5. Store response (post IDs, URLs, timestamps) as WordPress post meta.
6. Display "Published to: Twitter ✅ · LinkedIn ✅" in WP admin.

## Critical rules

- **Use `schedule_social_post` (MCP) first** — it handles multi-platform posting, scheduling, and optimal timing in one call.
- **Rate limits are real** — Twitter: 1,500/month (free), Facebook: 200 posts/day/page, LinkedIn: 100 posts/day.
- **Always check connected status** before attempting to post — handle missing keys gracefully.
- **Schedule during optimal hours** — use `get_optimal_timing: true` to find best times, or `scheduled_time: "optimal"`.
- **Store post URLs** — track what was published where for analytics.
- **Handle API errors gracefully** — expired tokens, rate limits, and media processing failures should surface actionable messages.

## Cross-references

- Run **`design-social-content`** before publishing to generate platform-optimized captions.
- Run **`design-image-generation`** to create visuals for posts — use `generate_gemini_image_validated` (MCP).
- Run **`design-image-optimization`** to size images for each platform — use `resize_image` (MCP).
- Run **`design-content-calendar`** to plan and schedule posts.
- Use **`paper_store_*`** tools with `connection_id` to persist publishing logs to specific sites.

## References

- Twitter API v2: <https://developer.twitter.com/en/docs/twitter-api>
- Facebook Graph API: <https://developers.facebook.com/docs/graph-api>
- Instagram Graph API: <https://developers.facebook.com/docs/instagram-api>
- LinkedIn Marketing API: <https://learn.microsoft.com/en-us/linkedin/marketing/>
