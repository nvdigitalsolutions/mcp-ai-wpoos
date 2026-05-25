# Template Compatibility Matrix

> **Status:** Phase 0 — initial curated list. Last reviewed: **May 2026**.
>
> This document catalogues known-compatible third-party React templates
> (primarily from [Envato Elements](https://elements.envato.com/web-templates/react))
> that have been successfully imported via `bin/import-react-template.mjs`.

---

## How to use this matrix

Each row represents a template that has been tested with the import pipeline.
The **Adaptation Notes** column records any template-specific quirks or
workarounds needed beyond the standard adapter pipeline.

To add a row: import the template via `npm run import-template`, document any
manual steps, and submit a PR updating this file.

---

## Admin Dashboards (Tier A — manifest-driven)

| Template Name | Envato ID | Category | React | UI Library | Router | Bundle | Adaptation Notes | Status |
|--------------|-----------|----------|-------|------------|--------|--------|------------------|--------|
| *Material Dashboard React* | TBD | Admin/CRM | 18.x | MUI v5 | react-router-dom v6 | ~380 KB | MUI ThemeProvider scoping needed; replace hardcoded JWT with WP nonce | ⏳ Pending |
| *Datta Able React* | TBD | Admin | 18.x | Bootstrap 5 | react-router-dom | ~420 KB | Global Bootstrap CSS needs `.nvoos-{slug}-root` prefix | ⏳ Pending |
| *Berry Free React Admin* | TBD | Admin | 18.x | MUI v5 | react-router-dom v6 | ~340 KB | Similar to Material Dashboard pattern | ⏳ Pending |
| *Horizon UI* | TBD | Admin/Dashboard | 18.x | Chakra UI | react-router-dom | ~310 KB | Chakra CSSReset conflicts with wp-admin | ⏳ Pending |
| *Mantis React* | TBD | Admin | 18.x | MUI v5 | react-router-dom v6 | ~400 KB | Large bundle — needs code splitting | ⏳ Pending |
| *Flexy React* | TBD | Admin | 18.x | MUI v5 | react-router-dom | ~360 KB | Standard MUI pattern | ⏳ Pending |

## CRM / Business Apps (Tier A — manifest-driven)

| Template Name | Envato ID | Category | React | UI Library | Router | Bundle | Adaptation Notes | Status |
|--------------|-----------|----------|-------|------------|--------|--------|------------------|--------|
| *CRM Dashboard React* | TBD | CRM | 18.x | Tailwind | react-router-dom | ~250 KB | Already uses fetch API — adapter works well | ⏳ Pending |
| *Project Management React* | TBD | PM | 18.x | Ant Design | react-router-dom v6 | ~520 KB | Ant Design CSS conflicts — needs scoping | ⏳ Pending |
| *Analytics Dashboard React* | TBD | Analytics | 18.x | Recharts + Tailwind | react-router-dom | ~290 KB | Good match for analytics toolkit manifest | ⏳ Pending |

## E-commerce (Tier A — manifest-driven)

| Template Name | Envato ID | Category | React | UI Library | Router | Bundle | Adaptation Notes | Status |
|--------------|-----------|----------|-------|------------|--------|--------|------------------|--------|
| *E-commerce Dashboard* | TBD | E-commerce | 18.x | Tailwind + Headless UI | react-router-dom | ~270 KB | REST endpoints map well to WooCommerce | ⏳ Pending |
| *Shop Dashboard React* | TBD | E-commerce | 18.x | MUI v5 | react-router-dom v6 | ~350 KB | Mock data in `src/mock/` — needs API adapter | ⏳ Pending |

---

## Tier Compatibility Quick Reference

| Tier | Bundle Limit | Common Template Types | Best Adapter Path |
|------|-------------|----------------------|-------------------|
| **A** (data-CRUD) | ≤ 200 KB gzip | Admin dashboards, CRM, analytics, ecommerce, financial | mount → auth → build → api → manifest |
| **B** (canvas) | ≤ 1600 KB gzip | Whiteboard apps, diagram editors, node-graph tools | mount → auth → build (separate addon) |
| **C** (documents) | ≤ 500 KB gzip | Rich-text editors, document generators, site builders | mount → auth → build (separate addon) |
| **D** (specialist) | ≤ 900 KB gzip | Media editors, video production, imaging tools | mount → auth → build (separate addon) |
| **E** (chat) | ≤ 350 KB gzip | Chat interfaces, messaging UIs | Use chat-spa addon instead |
| **F** (imported) | ≤ 400 KB gzip | Any imported external template | Full adapter pipeline |

---

## Template Selection Checklist

When evaluating an Envato template for import, verify:

- [ ] **License**: MIT, Apache-2.0, or BSD on the template page
- [ ] **React version**: 18.x or 19.x (check `package.json` in preview)
- [ ] **No premium-only dependencies** (AG-Grid Enterprise, Syncfusion, Kendo UI, DevExtreme — these are not MIT)
- [ ] **Standard router**: react-router-dom (preferred), @tanstack/react-router, or none
- [ ] **Source included**: Some Envato templates only ship compiled JS — avoid those
- [ ] **No remote CDN scripts**: All JS/CSS must be local (or you must self-host)
- [ ] **Reasonable bundle**: Under 400 KB gzip source estimate for Tier F, or plan a separate addon

---

## Contributing a Template

1. Purchase/download a template from Envato Elements
2. Run the analyzer: `npm run import-template:analyze -- --source /path/to/template`
3. If the analysis looks promising, run the import:
   ```bash
   npm run import-template -- \
     --source /path/to/template \
     --slug my-new-addon \
     --title "My New Addon" \
     --auto-fix \
     --verbose
   ```
4. Document any manual steps in the "Adaptation Notes" column
5. Update this matrix with the results
6. Open a PR with the matrix update + addon code
