# Tool Inventory - Complete List

**Status:** ✅ VERIFIED  
**Date:** December 24, 2025  
**Total Tools:** 144 unique tools (118 base + 26 Pro)

---

## Summary Statistics

| Category | Count |
|----------|-------|
| **Total Tool Files** | 171 |
| **Base Tool Classes** | 118 |
| **Validated Tool Variants** | 24 |
| **Pro Tool Classes** | 26 |
| **Helper/Trait Files** | 3 |
| **Unique Tools (Base + Pro)** | 144 |

---

## File Breakdown

### Base Tools Directory: `includes/tools/`
- **Total files**: 145
- **Tool class files**: 141
  - **Original tools**: 118
  - **Validated variants**: 24 (overlapping with original)
- **Non-tool files**: 3
  - `class-wp-mcp-ai-tool-image-base.php` (base class)
  - `tools-init.php` (registration)
  - `trait-wp-mcp-ai-tool-restrict-from-chat-client.php` (trait)
- **Helper files**: 1
  - `remove-background.php` (utility function, not a tool)

### Pro Tools Directory: `addons/pro/includes/tools/`
- **Total files**: 26
- **Tool class files**: 26
- All Pro tools are unique (no base equivalents)

---

## Tool Categories

### Content & Publishing Tools
- Core post/page management
- Content search and retrieval
- Media library operations
- Publishing workflows

### AI Generation Tools
- Image generation (OpenAI, Gemini)
- Video generation (Sora, Veo)
- Audio/speech generation
- Music generation
- Text embeddings

### Media Processing Tools
- Image manipulation (crop, resize, rotate, convert)
- Background removal
- Image analysis (alt text, captions)
- Video analysis
- Audio transcription

### E-commerce Tools
- WooCommerce product management
- Product scraping
- Price lookup (Pro)
- Product actualization (Pro)

### Integration Tools
- JetEngine items and routes
- JetFormBuilder forms and submissions
- Elementor templates
- Rank Math SEO
- Newsletter management
- WPCode snippets (Pro)

### Data & Analytics Tools
- Chart creation
- HuggingFace dataset operations (11 tools)
- Token counting and analysis
- Usage analytics
- Content moderation

### External API Tools
- Weather data (Open-Meteo)
- Geospatial data (GDACS, NHC storms)
- Humanitarian data (ReliefWeb)
- Google Maps (geocoding, places)
- Vision AI (Google Cloud)

### System & Maintenance Tools
- Cron job management (4 tools)
- Cache management (3 tools)
- Site health and security
- System logs
- Update status
- Environment status

### Authentication & Security Tools
- JWT token generation
- Auth0 token generation
- Site security checks
- Content moderation

### AI Platform Tools
- OpenAI batch operations (4 tools)
- Vector store management (4 tools)
- Model information and listing
- Assistant management

### Communication Tools
- Email sending (group email, Mailjet)
- Telegram messaging
- WhatsApp messaging (Pro)
- SMS scheduling (Pro - Notify.lk)

### Batch Processing Tools
- Batch content embedding
- Batch image processing
- Crawl4AI job management

### Profession & Assistant Tools
- Profession management (5 tools)
- Assistant creation and management

### Mesh Networking Tools
- Remote site queries
- Intelligent mesh queries

### Project Management Tools (Pro)
- Task management (4 tools)
- Project management (3 tools)
- Event management (4 tools)
- Calendar views

### Quiz Tools (Pro)
- Quiz management (4 tools)
- Quiz submissions and results

### Advanced Media Tools (Pro)
- Jukebox music generation
- Video frame extraction
- Background removal

---

## Validation Status

### Validated Tools (23 completed, 29% of planned 78)
Tools that have been migrated to use Symfony Validator:

#### Batch 1-3 (11 tools)
1. save-post
2. create-cron-job
3. search-content
4. create-assistant
5. get-recent-posts
6. get-system-logs
7. create-chart
8. send-group-email
9. create-woo-product
10. get-user-info
11. create-post

#### Batch 4 (12 tools)
12. transcribe-openai-audio
13. generate-image-alt-text
14. generate-image-caption
15. generate-openai-speech
16. generate-music
17. generate-gemini-image
18. generate-veo-video
19. generate-openai-image
20. web-search
21. edit-gemini-image
22. scrape-product
23. run-crawl4ai-job

### Planned for Validation
Total: 78 tools (high-priority subset)
Remaining: 55 tools

**Note:** Not all 144 tools are planned for validation. The validation project focuses on the 78 most frequently used and critical tools.

---

## Base Version vs Full Version

The plugin operates in two modes:

### Base Version
- **Tools Available**: 118 base tools
- **No Dependencies**: Core WordPress only
- **Use Case**: Standard installations, essential AI features

### Full Version (with Pro addon)
- **Tools Available**: 144 total (118 base + 26 Pro)
- **Dependencies**: Optional third-party plugins (WooCommerce, JetEngine, etc.)
- **Use Case**: Enterprise installations, advanced features

Control with constant:
```php
define( 'WP_MCP_AI_BASE_VERSION', true ); // Base only
define( 'WP_MCP_AI_BASE_VERSION', false ); // Full version
```

---

## Tool Registration

All tools are registered through:
- **Base tools**: `includes/tools/tools-init.php`
- **Validated tools**: `includes/tools/validated-tools-init.php` (if exists)
- **Pro tools**: `addons/pro/includes/tools/pro-tools-init.php`

---

## Maintenance Notes

### When Adding New Tools
1. Create tool class file in appropriate directory
2. Register in corresponding init file
3. Add tests in `tests/` directory
4. Update this inventory document
5. Update tool-reference.md with description
6. Consider for validation migration if high-priority

### When Removing Tools
1. Remove class file
2. Remove registration entry
3. Update this inventory document
4. Update tool-reference.md
5. Add deprecation notice to CHANGELOG.md

### Monthly Review Checklist
- [ ] Verify tool count matches actual files
- [ ] Check for deprecated tools
- [ ] Update validation progress
- [ ] Review Pro tool additions
- [ ] Update documentation

---

## Known Issues

### Documentation Gaps
- Some Pro tools may not be fully documented in tool-reference.md
- Tool grouping document may need updating for new tools

### Future Improvements
- Automated tool count validation in CI/CD
- Tool documentation generator script
- Dynamic tool inventory from codebase
- Tool deprecation tracking system

---

## References

- [Tool Reference Documentation](./tool-reference.md)
- [Tool Grouping](./tool-grouping.md)
- [Validated Tools Status](./VALIDATED_TOOLS_STATUS.md)
- [Batch 5 Kickoff Plan](../../implementation-history/2025/implementations/batches/BATCH_5_KICKOFF_PLAN.md)

---

**Last Verified:** December 24, 2025  
**Next Review:** January 24, 2026  
**Maintained By:** Development Team
