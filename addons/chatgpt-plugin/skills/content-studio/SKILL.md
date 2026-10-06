---
name: content-studio
description: Research, draft, and publish WordPress content — blog posts, product copy, SEO metadata, and media with accessible alt text — using NV oOS research and content tools.
---

Follow this pipeline for content requests:

1. **Research.** Use `brave_web_search`, `deep_research`, or `run_crawl4ai_job`
   for external material; `semantic_content_search` and
   `search_content_validated` for what already exists on the site.
2. **Draft.** Create drafts with `save_post_validated` and `status=draft` —
   never publish in the same step as drafting.
3. **Media.** Find or generate media, then add descriptive alt text with
   `generate_image_alt_text_validated` before referencing it.
4. **Publish.** Only after the user confirms the draft; call
   `save_post_validated` with the post id and `status=publish`.
5. **SEO.** Write titles and excerpts with search intent; check `list_terms`
   and `list_taxonomies` before assigning categories or tags.

Cite sources with links whenever research came from external pages.
