# Third-Party Notices — Bundled Agent Skills (Base)

The bundled-skills directory contains both Anthropic-authored skills (already
covered by the upstream [`anthropics/skills`](https://github.com/anthropics/skills)
repository) and curated WordPress-developer skills sourced from
[`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills).

All third-party skills in this directory are redistributed under their original
license. Each individual `SKILL.md` carries `source:` and `license:` frontmatter
fields pointing back to its upstream copy.

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

## Skills sourced from `nextlevelbuilder/ui-ux-pro-max-skill`

**Upstream repository:** https://github.com/nextlevelbuilder/ui-ux-pro-max-skill
**Pinned version:** v2.5.0
**License:** MIT (see below)
**Original author:** NextLevelBuilder

The `ui-ux-pro-max` skill is a self-contained adaptation of the upstream skill's
design intelligence content (67 UI styles, 99 UX guidelines, pre-delivery checklists,
and design system workflow). The Python scripts and CSV data files are not bundled;
the Markdown body is a curated, standalone distillation of the upstream skill content.

### Upstream MIT license text

```
MIT License

Copyright (c) 2024 NextLevelBuilder

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

The remaining bundled skills (e.g. `canvas-design`, `algorithmic-art`,
`frontend-design`, `mcp-builder`, `skill-creator`, `code-reviewer`,
`web-artifacts-builder`, `webapp-testing`, `brand-guidelines`,
`theme-factory`, `slack-gif-creator`, `excalidraw-diagram`, `internal-comms`,
`doc-coauthoring`, `browser-use`, `remotion`, `valyu`, `planetscale`,
`shannon`, `karpathy-coding-principles`) originate from the Anthropic Skills
repository at https://github.com/anthropics/skills and are redistributed
under Apache-2.0 (see LICENSE.txt in this directory).

Four skills from the same upstream repository (`pdf`, `docx`, `xlsx`,
`pptx`) were removed from this distribution because they are licensed
"Proprietary" and are not compatible with the GPLv3 license of this plugin.
