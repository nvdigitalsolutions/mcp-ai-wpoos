# Third-Party Notices — Bundled Agent Skills (Base)

The bundled-skills directory contains Anthropic-authored skills (upstream:
[`anthropics/skills`](https://github.com/anthropics/skills)) and curated skills
sourced from third-party repositories including
[`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills)
and [`nextlevelbuilder/ui-ux-pro-max-skill`](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill).

All third-party skills in this directory are redistributed under their original
license. Where present, `SKILL.md` frontmatter includes upstream `source:` and
license metadata; this notice remains the canonical attribution record.

## Skills sourced from `Lonsdale201/wp-agent-skills`

**Upstream repository:** https://github.com/Lonsdale201/wp-agent-skills
**Pinned commit:** `8684fef5b4c33bc0cd783f9fff7770b1f7f59c57`
**License:** MIT (see below)
**Original author:** Soczó Kristóf (Lonsdale201)

The following skills (and their accompanying `reference.md` where present)
were copied from the pinned upstream commit, with only YAML frontmatter
normalised so that NV oOS's lightweight skill parser reads them in full
(multi-line folded scalars folded into a single `description:` line; YAML
list keys such as `docs:` removed). The Markdown body is byte-for-byte
identical to upstream:

- `wp-security-audit` — `wordpress/wp-security-audit/`
- `wp-security-deep` — `wordpress/wp-security-deep/`
- `wp-security-secrets` — `wordpress/wp-security-secrets/`
- `wp-i18n-audit` — `wordpress/wp-i18n-audit/`
- `wp-rest-api` — `wordpress/wp-rest-api/`
- `wp-abilities-api` — `wordpress/wp-abilities-api/`
- `wp-html-api` — `wordpress/wp-html-api/`
- `wp-utf8-text` — `wordpress/wp-utf8-text/`
- `wp-query-cache` — `wordpress/wp-query-cache/`
- `wp-action-scheduler` — `plugin-scaffold/wp-action-scheduler/`
- `wp-plugin-architecture` — `plugin-scaffold/wp-plugin-architecture/`
- `wp-plugin-assets-loading` — `plugin-scaffold/wp-plugin-assets-loading/`
- `wp-plugin-bootstrap` — `plugin-scaffold/wp-plugin-bootstrap/`
- `wp-plugin-cron` — `plugin-scaffold/wp-plugin-cron/`
- `wp-plugin-dto` — `plugin-scaffold/wp-plugin-dto/`
- `wp-plugin-hooks` — `plugin-scaffold/wp-plugin-hooks/`
- `wp-plugin-lifecycle` — `plugin-scaffold/wp-plugin-lifecycle/`
- `wp-plugin-options-storage` — `plugin-scaffold/wp-plugin-options-storage/`
- `wp-plugin-presenter` — `plugin-scaffold/wp-plugin-presenter/`
- `wp-plugin-rewrite-rules` — `plugin-scaffold/wp-plugin-rewrite-rules/`

### Upstream MIT license text

```
MIT License

Copyright (c) 2026 Lonsdale201 and wp-agent-skills contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Skills sourced from `anthropics/skills`

Anthropic-authored bundled skills (e.g. `pdf`, `docx`, `xlsx`, `pptx`,
`canvas-design`, `algorithmic-art`, `frontend-design`, `mcp-builder`,
`skill-creator`, `code-reviewer`, `web-artifacts-builder`, `webapp-testing`,
`brand-guidelines`, `theme-factory`, `slack-gif-creator`, `excalidraw-diagram`,
`internal-comms`, `doc-coauthoring`, `browser-use`, `remotion`, `valyu`,
`planetscale`, `shannon`, `karpathy-coding-principles`) originate from the
Anthropic Skills repository at https://github.com/anthropics/skills and
follow that repository's license terms.

## Skill sourced from `nextlevelbuilder/ui-ux-pro-max-skill`

**Upstream repository:** https://github.com/nextlevelbuilder/ui-ux-pro-max-skill  
**Pinned commit:** `b7e3af80f6e331f6fb456667b82b12cade7c9d35`  
**License:** MIT (see below)  
**Copyright holder:** Next Level Builder

The following files were copied from the pinned upstream commit:

- `includes/bundled-skills/ui-ux-pro-max/SKILL.md` ← `.claude/skills/ui-ux-pro-max/SKILL.md`
- `includes/bundled-skills/ui-ux-pro-max/scripts/search.py` ← `src/ui-ux-pro-max/scripts/search.py`
- `includes/bundled-skills/ui-ux-pro-max/scripts/core.py` ← `src/ui-ux-pro-max/scripts/core.py`
- `includes/bundled-skills/ui-ux-pro-max/scripts/design_system.py` ← `src/ui-ux-pro-max/scripts/design_system.py`
- `includes/bundled-skills/ui-ux-pro-max/data/*.csv` ← `src/ui-ux-pro-max/data/*.csv`
- `includes/bundled-skills/ui-ux-pro-max/data/stacks/*.csv` ← `src/ui-ux-pro-max/data/stacks/*.csv`

### Upstream MIT license text (`nextlevelbuilder/ui-ux-pro-max-skill`)

```
MIT License

Copyright (c) 2024 Next Level Builder

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
