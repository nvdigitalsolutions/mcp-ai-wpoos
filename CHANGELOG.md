
---

### 📄 `CHANGELOG.md`
```markdown
# WP OOS – Changelog

## [1.0.0] – 2025-10-23
### Changed
- Expanded chat interaction logging to keep structured message content while trimming oversized payloads.
- Front-end chat client now preserves assistant replies that contain non-text content like images or tool results.

## [0.9.0] – 2025-10-21
### Added
- Initial beta release
- AI Assistant custom post type
- OpenAI GPT-4o-mini integration
- REST chat endpoint `/wp-json/mcp-ai/v1/chat`
- Tool registry with default tools
- WooCommerce & JetEngine conditional tools
- Admin settings for API key
- Shortcode `[mcp_ai_chat assistant="ID"]`

### Notes
- Stable for development & testing.
- Production hardening will follow post-feedback.
