# Credits & Acknowledgements

> **Open Operator System (NV oOS)** is built on the shoulders of a remarkable
> open-source ecosystem. This file is the canonical, authoritative index of
> every third-party resource shipped with NV oOS, so every upstream author gets
> the recognition they deserve.
>
> Last reviewed: **May 2026** · Maintained alongside `readme.txt == Credits ==`,
> `docs/THIRD_PARTY_ASSETS.md`, `includes/bundled-skills/THIRD_PARTY_NOTICES.md`,
> and `addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`.

## Project Identity

| Field | Value |
|-------|-------|
| **Project** | Open Operator System (NV oOS) |
| **Maintainer** | [NV Digital Solutions](https://nvdigitalsolutions.com/) |
| **Base plugin license** | [GPLv3 or later](LICENSE) |
| **Algorave addon license** | AGPL-3.0-or-later (bundles `@strudel/web` AGPL-3.0) |
| **Pro addon + 6 proprietary addons license** | Proprietary — © NV Digital Solutions, all rights reserved |
| **Repository** | <https://github.com/nvdigitalsolutions/mcp-ai-wpoos> |

NV oOS ships under a three-tier license model:

1. **Base plugin** (root `mcp-ai-wpoos.php`, `includes/`) — **GPLv3 or later**.
2. **Open community addon** — `addons/algorave/` is **AGPL-3.0-or-later**
   (the combined-work license is AGPL because it bundles the AGPL-3.0
   `@strudel/web` live-coding engine).
3. **Proprietary addons** — `addons/pro/`, `addons/graphify/`,
   `addons/embedded/`, `addons/cornerstone3d/`, `addons/canvas/`,
   `addons/cloud-worker/`, and `addons/fantasy-football/` are separate,
   optionally-installed components under a **proprietary** license. Their
   third-party dependencies retain their upstream licenses (mostly MIT or
   Apache-2.0); the table-column on each entry below indicates exactly which
   distribution bundles which resource.

---

## How to Read This File

* **PHP dependencies** — installed via Composer (`composer.lock` /
  `addons/pro/composer.lock`). Each row links to the upstream Git repo,
  upstream license, and the directory the package lands in inside our tree.
* **JavaScript dependencies (npm)** — declared in `package.json` /
  `addons/*/package.json`. Bundled into the plugin via esbuild or copied into
  `assets/**/vendor/` at build time.
* **Vendored JavaScript** — files committed verbatim under
  `assets/js/vendor/`, `addons/*/assets/{js/,}vendor/`, or
  `addons/pro/assets/vendor/`. Each carries its own `LICENSE` file alongside
  the bundle.
* **Bundled Agent Skills** — curated `SKILL.md` files. The two
  `THIRD_PARTY_NOTICES.md` files (linked below) carry the full per-skill
  attribution; this file does not duplicate them.
* **Fonts** — embedded fonts used by PDF generation libraries.
* **Methodology / inspiration** — projects whose ideas, schemas, or workflows
  shaped NV oOS without code being copied.

---

## PHP Dependencies — Base Plugin (`vendor/`)

Distributed under the base plugin (GPLv3). All packages are MIT-licensed and
GPL-compatible. License files live alongside each package under `vendor/<name>/`.

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `guzzlehttp/guzzle` | 7.10.0 | MIT | <https://github.com/guzzle/guzzle> |
| `guzzlehttp/promises` | 2.3.0 | MIT | <https://github.com/guzzle/promises> |
| `guzzlehttp/psr7` | 2.9.0 | MIT | <https://github.com/guzzle/psr7> |
| `league/oauth2-client` | 2.9.0 | MIT | <https://github.com/thephpleague/oauth2-client> |
| `nyholm/psr7` | 1.8.2 | MIT | <https://github.com/Nyholm/psr7> |
| `php-http/discovery` | 1.20.0 | MIT | <https://github.com/php-http/discovery> |
| `psr/cache` | 3.0.0 | MIT | <https://github.com/php-fig/cache> |
| `psr/container` | 2.0.2 | MIT | <https://github.com/php-fig/container> |
| `psr/http-client` | 1.0.3 | MIT | <https://github.com/php-fig/http-client> |
| `psr/http-factory` | 1.1.0 | MIT | <https://github.com/php-fig/http-factory> |
| `psr/http-message` | 2.0 | MIT | <https://github.com/php-fig/http-message> |
| `psr/log` | 3.0.2 | MIT | <https://github.com/php-fig/log> |
| `rahul900day/tiktoken-php` | 1.0.0 | MIT | <https://github.com/RahulDey12/tiktoken-php> |
| `ralouphie/getallheaders` | 3.0.3 | MIT | <https://github.com/ralouphie/getallheaders> |
| `symfony/cache` | v6.4.36 | MIT | <https://github.com/symfony/cache> |
| `symfony/cache-contracts` | v3.6.0 | MIT | <https://github.com/symfony/cache-contracts> |
| `symfony/deprecation-contracts` | v3.6.0 | MIT | <https://github.com/symfony/deprecation-contracts> |
| `symfony/filesystem` | v6.4.34 | MIT | <https://github.com/symfony/filesystem> |
| `symfony/http-client` | v6.4.36 | MIT | <https://github.com/symfony/http-client> |
| `symfony/http-client-contracts` | v3.6.0 | MIT | <https://github.com/symfony/http-client-contracts> |
| `symfony/polyfill-ctype` | v1.33.0 | MIT | <https://github.com/symfony/polyfill-ctype> |
| `symfony/polyfill-mbstring` | v1.33.0 | MIT | <https://github.com/symfony/polyfill-mbstring> |
| `symfony/polyfill-php83` | v1.33.0 | MIT | <https://github.com/symfony/polyfill-php83> |
| `symfony/process` | v6.4.33 | MIT | <https://github.com/symfony/process> |
| `symfony/service-contracts` | v3.6.1 | MIT | <https://github.com/symfony/service-contracts> |
| `symfony/translation-contracts` | v3.6.1 | MIT | <https://github.com/symfony/translation-contracts> |
| `symfony/validator` | v6.4.36 | MIT | <https://github.com/symfony/validator> |
| `symfony/var-exporter` | v6.4.36 | MIT | <https://github.com/symfony/var-exporter> |

PSR packages are © PHP-FIG contributors. Symfony components are
© Fabien Potencier and the Symfony contributors.

---

## PHP Dependencies — Pro Addon (`addons/pro/vendor/`)

Bundled with the Pro addon only. License files live under
`addons/pro/vendor/<name>/`.

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `composer/pcre` | 3.3.2 | MIT | <https://github.com/composer/pcre> |
| `dompdf/dompdf` | v3.1.4 | LGPL-2.1 | <https://github.com/dompdf/dompdf> |
| `dompdf/php-font-lib` | 1.0.2 | LGPL-2.1-or-later | <https://github.com/dompdf/php-font-lib> |
| `dompdf/php-svg-lib` | 1.0.2 | LGPL-3.0-or-later | <https://github.com/dompdf/php-svg-lib> |
| `dvdoug/boxpacker` | 3.12.1 | MIT | <https://github.com/dvdoug/BoxPacker> |
| `maennchen/zipstream-php` | 3.1.1 | MIT | <https://github.com/maennchen/ZipStream-PHP> |
| `markbaker/complex` | 3.0.2 | MIT | <https://github.com/MarkBaker/PHPComplex> |
| `markbaker/matrix` | 3.0.1 | MIT | <https://github.com/MarkBaker/PHPMatrix> |
| `masterminds/html5` | 2.10.0 | MIT | <https://github.com/Masterminds/html5-php> |
| `phpoffice/math` | 0.3.0 | MIT | <https://github.com/PHPOffice/Math> |
| `phpoffice/phpspreadsheet` | 5.7.0 | MIT | <https://github.com/PHPOffice/PhpSpreadsheet> |
| `phpoffice/phpword` | 1.4.0 | LGPL-3.0-only | <https://github.com/PHPOffice/PHPWord> |
| `psr/log` | 3.0.2 | MIT | <https://github.com/php-fig/log> |
| `psr/simple-cache` | 3.0.0 | MIT | <https://github.com/php-fig/simple-cache> |
| `sabberworm/php-css-parser` | v9.1.0 | MIT | <https://github.com/MyIntervals/PHP-CSS-Parser> |
| `smalot/pdfparser` | v2.12.3 | LGPL-3.0 | <https://github.com/smalot/pdfparser> |
| `symfony/polyfill-mbstring` | v1.37.0 | MIT | <https://github.com/symfony/polyfill-mbstring> |
| `tecnickcom/tcpdf` | 6.10.1 | LGPL-3.0-or-later | <https://github.com/tecnickcom/TCPDF> |
| `thecodingmachine/safe` | v3.3.0 | MIT | <https://github.com/thecodingmachine/safe> |
| `thiagoalessio/tesseract_ocr` | 2.13.0 | MIT | <https://github.com/thiagoalessio/tesseract-ocr-for-php> |

---

## JavaScript Dependencies — Base Plugin (`package.json`)

Bundled into the base plugin via esbuild (`esbuild.config.js`) or copied into
`assets/js/vendor/` at build time. Detailed update procedures live in
[`docs/THIRD_PARTY_ASSETS.md`](docs/THIRD_PARTY_ASSETS.md).

| Package | Version (declared) | License | Upstream |
|---------|--------------------|---------|----------|
| `@dnd-kit/core` | ^6.1.0 | MIT | <https://github.com/clauderic/dnd-kit> |
| `@dnd-kit/sortable` | ^8.0.0 | MIT | <https://github.com/clauderic/dnd-kit> |
| `@dnd-kit/utilities` | ^3.2.2 | MIT | <https://github.com/clauderic/dnd-kit> |
| `@microsoft/fetch-event-source` | ^2.0.1 | MIT | <https://github.com/Azure/fetch-event-source> |
| `@mlc-ai/web-llm` | ^0.2.80 | Apache-2.0 | <https://github.com/mlc-ai/web-llm> |
| `@neplex/vectorizer` | ^0.0.5 | MIT | <https://github.com/neplextech/vectorizer> |
| `chart.js` | ^4.4.7 | MIT | <https://github.com/chartjs/Chart.js> |
| `dompurify` | ^3.4.0 | MPL-2.0 OR Apache-2.0 | <https://github.com/cure53/DOMPurify> |
| `ky` | ^1.14.0 | MIT | <https://github.com/sindresorhus/ky> |
| `marked` | ^9.1.6 | MIT | <https://github.com/markedjs/marked> |
| `react` | ^18.2.0 | MIT | <https://github.com/facebook/react> |
| `react-dom` | ^18.2.0 | MIT | <https://github.com/facebook/react> |
| `reactflow` | ^11.10.4 | MIT | <https://github.com/xyflow/xyflow> |

Build-time only (not shipped to end users): `@babel/*`, `@wordpress/*`,
`esbuild`, `eslint`, `jest`, `babel-loader`, `clean-css`, `uglify-js`,
`webpack` plugins. Each is MIT- or Apache-2.0-licensed by its respective
upstream (Babel team, Automattic / WordPress contributors, the esbuild author
Evan Wallace, OpenJS Foundation).

---

## JavaScript Dependencies — Pro Addon (`addons/pro/package.json`)

Bundled into the Pro addon. Most ship as committed copies under
`addons/pro/assets/vendor/<name>/`.

| Package | Version (declared) | License | Upstream |
|---------|--------------------|---------|----------|
| `@remotion/bundler` | ^4.0.0 | Remotion (proprietary, licensed via Pro) | <https://github.com/remotion-dev/remotion> |
| `@remotion/cli` | ^4.0.0 | Remotion | <https://github.com/remotion-dev/remotion> |
| `@remotion/renderer` | ^4.0.0 | Remotion | <https://github.com/remotion-dev/remotion> |
| `@turf/turf` | ^7.3.2 | MIT | <https://github.com/Turfjs/turf> |
| `@types/pdfkit` | ^0.17.4 | MIT | <https://github.com/DefinitelyTyped/DefinitelyTyped> |
| `@woocommerce/woocommerce-rest-api` | ^1.0.1 | MIT | <https://github.com/woocommerce/woocommerce-rest-api-js-lib> |
| `axios` | ^1.15.0 | MIT | <https://github.com/axios/axios> |
| `chart.js` | ^4.4.7 | MIT | <https://github.com/chartjs/Chart.js> |
| `cheerio` | ^1.0.0 | MIT | <https://github.com/cheeriojs/cheerio> |
| `csv-parse` | ^5.6.0 | MIT | <https://github.com/adaltas/node-csv> |
| `csv-stringify` | ^6.5.2 | MIT | <https://github.com/adaltas/node-csv> |
| `currency.js` | ^2.0.4 | MIT | <https://github.com/scurker/currency.js> |
| `d3` | ^7.8.5 | ISC | <https://github.com/d3/d3> |
| `docx` | ^9.5.1 | MIT | <https://github.com/dolanmiu/docx> |
| `email-validator` | ^2.0.4 | MIT | <https://github.com/manishsaraan/email-validator> |
| `exceljs` | ^4.4.0 | MIT | <https://github.com/exceljs/exceljs> |
| `facebook-nodejs-business-sdk` | ^24.0.1 | Facebook Platform Policy | <https://github.com/facebook/facebook-nodejs-business-sdk> |
| `fast-csv` | ^5.0.0 | MIT | <https://github.com/C2FO/fast-csv> |
| `franc` | ^6.1.0 | MIT | <https://github.com/wooorm/franc> |
| `gif-encoder` | ^0.7.2 | MIT | <https://github.com/twolfson/gif-encoder> |
| `google-translate-api-x` | ^10.7.0 | MIT | <https://github.com/AidanWelch/google-translate-api> |
| `i18next` | ^23.7.0 | MIT | <https://github.com/i18next/i18next> |
| `ical-generator` | ^8.0.1 | MIT | <https://github.com/sebbo2002/ical-generator> |
| `ics` | ^3.8.1 | MIT | <https://github.com/adamgibbons/ics> |
| `iso-639-1` | ^3.1.0 | MIT | <https://github.com/meikidd/iso-639-1> |
| `katex` | ^0.16.11 | MIT | <https://github.com/KaTeX/KaTeX> |
| `libphonenumber-js` | ^1.11.21 | MIT | <https://github.com/catamphetamine/libphonenumber-js> |
| `linkedin-api-client` | ^0.3.0 | Apache-2.0 | <https://github.com/linkedin-developers/linkedin-api-js-client> |
| `mailparser` | ^3.7.1 | MIT | <https://github.com/nodemailer/mailparser> |
| `mathjs` | ^15.2.0 | Apache-2.0 | <https://github.com/josdejong/mathjs> |
| `mjml` | ^5.0.0-alpha.10 | MIT | <https://github.com/mjmlio/mjml> |
| `node-ensure` | ^0.0.0 | MIT | <https://github.com/martindale/node-ensure> |
| `nodemailer` | ^8.0.5 | MIT-0 | <https://github.com/nodemailer/nodemailer> |
| `p-queue` | ^8.0.1 | MIT | <https://github.com/sindresorhus/p-queue> |
| `pdf-lib` | ^1.17.1 | MIT | <https://github.com/Hopding/pdf-lib> |
| `pdf-parse` | ^1.1.4 | MIT | <https://gitlab.com/autokent/pdf-parse> |
| `pdfjs-dist` | ^4.9.155 | Apache-2.0 | <https://github.com/mozilla/pdf.js> |
| `pdfkit` | ^0.17.2 | MIT | <https://github.com/foliojs/pdfkit> |
| `prettier` | ^3.4.2 | MIT | <https://github.com/prettier/prettier> |
| `puppeteer-core` | (Pro packages page) | Apache-2.0 | <https://github.com/puppeteer/puppeteer> |
| `qrcode` | ^1.5.4 | MIT | <https://github.com/soldair/node-qrcode> |
| `regression` | ^2.0.1 | MIT | <https://github.com/Tom-Alexander/regression-js> |
| `remotion` | ^4.0.0 | Remotion | <https://github.com/remotion-dev/remotion> |
| `sharp` | ^0.33.5 | Apache-2.0 | <https://github.com/lovell/sharp> |
| `stripe` | ^14.0.0 | MIT | <https://github.com/stripe/stripe-node> |
| `subtitle` | ^3.0.0 | MIT | <https://github.com/gsantiago/subtitle.js> |
| `tesseract.js` | ^5.1.1 | Apache-2.0 | <https://github.com/naptha/tesseract.js> |
| `turndown` | ^7.2.0 | MIT | <https://github.com/mixmark-io/turndown> |
| `twitter-api-v2` | ^1.15.2 | Apache-2.0 | <https://github.com/PLhery/node-twitter-api-v2> |
| `validator` | ^13.12.0 | MIT | <https://github.com/validatorjs/validator.js> |
| `video-stitch` | ^1.7.1 | MIT | <https://github.com/Anveio/video-stitch> |

> **Note on Remotion:** Remotion uses a custom non-OSS license. Pro deployments
> that render videos with Remotion at scale may require a separate Remotion
> license; see <https://www.remotion.dev/license>.

> **Note on Facebook SDK:** Use is governed by Facebook's Platform Policy in
> addition to the SDK's open-source license. Review their terms before
> production use.

---

## JavaScript Dependencies — Add-ons

### `addons/algorave/` — live-coding music addon

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `@strudel/web` | 1.2.5 | AGPL-3.0 | <https://github.com/tidalcycles/strudel> |
| `@tonejs/midi` | ^2.0.28 | MIT | <https://github.com/Tonejs/Midi> |
| `tonal` | ^6.3.0 | MIT | <https://github.com/tonaljs/tonal> |
| `tone` | ^15.0.4 | MIT | <https://github.com/Tonejs/Tone.js> |
| `webmidi` | ^3.1.11 | Apache-2.0 | <https://github.com/djipco/webmidi> |

`@strudel/web` is bundled at
`addons/algorave/assets/js/vendor/strudel/strudel-web-1.2.5.js`. Strudel is a
JavaScript port of TidalCycles by Felix Roos and contributors.

### `addons/canvas/` — native binary addon for Tesseract PDF OCR

Ships pre-compiled native `canvas.node` binaries for Linux x64 / ARM64.
The `canvas` Node module is © Automattic and contributors, MIT-licensed.

* **node-canvas:** <https://github.com/Automattic/node-canvas>

### `addons/cornerstone3d/` — medical imaging viewer

Pre-built ESM bundles redistributed with attribution intact in their headers.

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `@cornerstonejs/core` | 1.86.1 | MIT | <https://github.com/cornerstonejs/cornerstone3D> |
| `@cornerstonejs/tools` | 1.86.1 | MIT | <https://github.com/cornerstonejs/cornerstone3D> |
| `@cornerstonejs/dicom-image-loader` | 1.86.0 | MIT | <https://github.com/cornerstonejs/cornerstone3D> |
| `dicom-parser` | 1.8.21 | MIT | <https://github.com/cornerstonejs/dicomParser> |
| `xmlbuilder2` | 3.0.2 | MIT | <https://github.com/oozcitak/xmlbuilder2> |

### `addons/graphify/` — knowledge-graph viewer

Vendored under `addons/graphify/assets/vendor/`, each with its own
`LICENSE` file alongside the bundle.

| Package | License | Upstream |
|---------|---------|----------|
| `cytoscape` | MIT — © 2016–2023 The Cytoscape Consortium | <https://github.com/cytoscape/cytoscape.js> |
| `cytoscape-fcose` | MIT | <https://github.com/iVis-at-Bilkent/cytoscape.js-fcose> |
| `cose-base` | MIT | <https://github.com/iVis-at-Bilkent/cose-base> |
| `layout-base` | MIT | <https://github.com/iVis-at-Bilkent/layout-base> |

### `addons/docs-hub/` — React-based documentation browser

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | ^19.2.6 | MIT | <https://github.com/facebook/react> |
| `react-dom` | ^19.2.6 | MIT | <https://github.com/facebook/react> |
| `react-router-dom` | ^7.15.0 | MIT | <https://github.com/remix-run/react-router> |
| `react-markdown` | ^10.1.0 | MIT | <https://github.com/remarkjs/react-markdown> |
| `remark-gfm` | ^4.0.1 | MIT | <https://github.com/remarkjs/remark-gfm> |
| `remark-directive` | ^4.0.0 | MIT | <https://github.com/remarkjs/remark-directive> |
| `remark-frontmatter` | ^5.0.0 | MIT | <https://github.com/remarkjs/remark-frontmatter> |
| `rehype-slug` | ^6.0.0 | MIT | <https://github.com/rehypejs/rehype-slug> |
| `rehype-autolink-headings` | ^7.1.0 | MIT | <https://github.com/rehypejs/rehype-autolink-headings> |
| `flexsearch` | ^0.8.212 | Apache-2.0 | <https://github.com/nextapps-de/flexsearch> |

All packages are MIT-licensed (except FlexSearch which is Apache-2.0). The React SPA
is bundled into `addons/docs-hub/assets/dist/docs-hub.js` via esbuild.

### `addons/toolkit-shell/` — manifest-driven React SPA shell for Pro toolkits

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | 19.1.0 | MIT | <https://github.com/facebook/react> |
| `react-dom` | 19.1.0 | MIT | <https://github.com/facebook/react> |

Build-time-only dev dependencies (not redistributed in `assets/dist/`):
`esbuild` (MIT), `typescript` (Apache-2.0), `@types/react` and `@types/react-dom`
(MIT). Full per-package license text lives in
[`addons/toolkit-shell/THIRD_PARTY_NOTICES.md`](addons/toolkit-shell/THIRD_PARTY_NOTICES.md).

The toolkit-shell addon is the canonical Phase 1 implementation of the
[Toolkit SPA Blueprint](docs/addons/toolkit-spa-blueprint.md) — one bundle, many
surfaces, driven by per-toolkit JSON manifests under
`addons/pro/config/spa-manifests/`.

### `addons/canvas-toolkit/` — React canvas / node-graph SPA addon

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | 19.2.6 | MIT | <https://github.com/facebook/react> |
| `react-dom` | 19.2.6 | MIT | <https://github.com/facebook/react> |
| `@xyflow/react` | 12.4.0 | MIT | <https://github.com/xyflow/xyflow> |
| `tldraw` | 5.0.0 | MIT | <https://github.com/tldraw/tldraw> |
| `bpmn-js` | 18.16.1 | MIT | <https://github.com/bpmn-io/bpmn-js> |
| `mermaid` | 11.14.0 | MIT | <https://github.com/mermaid-js/mermaid> |

Build-time-only dev dependencies (not redistributed in `assets/dist/`):
`esbuild` (MIT), `typescript` (Apache-2.0), `@types/react` and `@types/react-dom`
(MIT). Full per-package license text lives in
[`addons/canvas-toolkit/THIRD_PARTY_NOTICES.md`](addons/canvas-toolkit/THIRD_PARTY_NOTICES.md).

The canvas-toolkit addon is the Phase 2 implementation of the
[Toolkit SPA Blueprint](docs/addons/toolkit-spa-blueprint.md) Tier B — separate
addon for canvas / whiteboard / node-graph / BPMN surfaces, lazy-loaded by mode.
Ships all four modes: `flow` (@xyflow/react), `whiteboard` (tldraw v5),
`bpmn` (bpmn-js), and `mermaid` (Mermaid live preview).

### `addons/document-editor/` — Tiptap rich-text document editor + GrapesJS site-creator SPA addon

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | 19.1.0 | MIT | <https://github.com/facebook/react> |
| `react-dom` | 19.1.0 | MIT | <https://github.com/facebook/react> |
| `@tiptap/react` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/pm` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/starter-kit` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/extension-link` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/extension-placeholder` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/extension-table` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `@tiptap/extension-table-{cell,header,row}` | 3.22.5 | MIT | <https://github.com/ueberdosis/tiptap> |
| `grapesjs` | 0.22.16 | BSD-3-Clause | <https://github.com/GrapesJS/grapesjs> |
| `@grapesjs/react` | 2.0.0 | MIT | <https://github.com/GrapesJS/react> |

Build-time-only dev dependencies (not redistributed in `assets/dist/`):
`esbuild` (MIT), `typescript` (Apache-2.0), `@types/react` and `@types/react-dom`
(MIT). Full per-package license text lives in
[`addons/document-editor/THIRD_PARTY_NOTICES.md`](addons/document-editor/THIRD_PARTY_NOTICES.md).

The document-editor addon is the Phase 3 / Phase 12 implementation of the
[Toolkit SPA Blueprint](docs/addons/toolkit-spa-blueprint.md) Tier C — separate
addon for rich-text / document surfaces. v0.1.0 ships `mode="editor"` (full
Tiptap document editor with toolbar + REST `/nvoos-document-editor/v1/documents`
CRUD backed by the `nvoos_document` CPT). v0.2.0 ships `mode="site-creator"` —
a GrapesJS visual page builder with built-in blocks (header, text, two-column)
and localStorage project persistence.

### `addons/media-studio/` — Tier D media production SPA addon

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | 19.2.6 | MIT | <https://github.com/facebook/react> |
| `react-dom` | 19.2.6 | MIT | <https://github.com/facebook/react> |
| `konva` | 10.3.0 | MIT | <https://github.com/konvajs/konva> |
| `react-konva` | 19.2.3 | MIT | <https://github.com/konvajs/react-konva> |
| `react-image-crop` | 11.0.10 | ISC | <https://github.com/DominicTobias/react-image-crop> |
| `react-player` | 3.4.0 | MIT | <https://github.com/cookpete/react-player> |
| `wavesurfer.js` | 7.12.6 | BSD-3-Clause | <https://github.com/wavesurfer-js/wavesurfer.js> |

Build-time-only dev dependencies (not redistributed in `assets/dist/`):
`esbuild` (MIT), `typescript` (Apache-2.0), `@types/react` and `@types/react-dom`
(MIT). Full per-package license text lives in
[`addons/media-studio/THIRD_PARTY_NOTICES.md`](addons/media-studio/THIRD_PARTY_NOTICES.md).

The media-studio addon is the Phase 4 implementation of the
[Toolkit SPA Blueprint](docs/addons/toolkit-spa-blueprint.md) Tier D — specialist
surface serving the `image-production` and `media` toolkits. Ships three modes:
`image-editor` (react-konva canvas + react-image-crop overlay), `media-player`
(react-player universal player), and `audio-waveform` (wavesurfer.js). Bundle is
~826 KB gzip — expected for Tier D specialist, kept isolated by the separate-addon
guardrail. Future `drawing` mode (tldraw, large bundle) deferred to follow-up PR.

### `addons/chat-spa/` — Tier E React chat SPA addon

| Package | Version | License | Upstream |
|---------|---------|---------|----------|
| `react` | 19.1.0 | MIT | <https://github.com/facebook/react> |
| `react-dom` | 19.1.0 | MIT | <https://github.com/facebook/react> |
| `@ai-sdk/react` | 1.2.12 | Apache-2.0 | <https://github.com/vercel/ai> |

Build-time-only dev dependencies (not redistributed in `assets/dist/`):
`esbuild` (MIT), `typescript` (Apache-2.0), `@types/react` and `@types/react-dom`
(MIT), `@axe-core/react` (MPL-2.0). Full per-package license text lives in
[`addons/chat-spa/THIRD_PARTY_NOTICES.md`](addons/chat-spa/THIRD_PARTY_NOTICES.md).

The chat-spa addon is a modern React replacement for the legacy
`assets/js/chat.js` jQuery UI. It uses the Vercel AI SDK UI layer
(`@ai-sdk/react`'s `useChat` hook) **on the React side only** — the WordPress
PHP layer (`WP_MCP_AI_REST_Chat_Controller`) remains the orchestrator and AI
provider gateway. A client-side adapter
([`src/sse-adapter.ts`](addons/chat-spa/src/sse-adapter.ts)) translates NV oOS's
native SSE frames into the AI SDK Data Stream Protocol so `useChat` can consume
them with `streamProtocol: 'data'`. No Node server is introduced; every
existing capability (HITL, harness layers, memory bridge, guest tokens,
JetEngine transcripts, providers, tool registry) keeps working.

### `addons/embedded/`

No external bundled JS libraries; CSS/JS authored in-house. **License:**
proprietary — © NV Digital Solutions, all rights reserved. WebLLM
(Apache-2.0) is loaded from a CDN at runtime; llama.cpp (MIT) is invoked as
an external system binary.

### `addons/fantasy-football/`

No bundled third-party JavaScript. PHP-only addon under a **proprietary**
license — © NV Digital Solutions, all rights reserved. ESPN and Yahoo
Fantasy APIs are accessed at runtime over HTTPS; their use is governed by
the providers' respective Terms of Service.

### `addons/cloud-worker/` — NV oOS Cloud SaaS backend (Cloudflare Worker)

This addon is **not a WordPress plugin**. It is the SaaS-side counterpart to
the Pro plugin module that ships in `addons/pro/`. Deployed independently to
`nvoos.cloud` as a Cloudflare Worker.

| Package | License | Purpose |
|---|---|---|
| [`hono`](https://hono.dev/) ^4.12.4 | MIT | Edge-friendly HTTP router. |
| [`stripe`](https://github.com/stripe/stripe-node) ^17.4.0 | MIT | Type definitions only — at request-time we use the bare HTTPS API to keep the bundle small. |
| [`@cloudflare/workers-types`](https://github.com/cloudflare/workerd) ^4 | Apache-2.0 | Type definitions for the Workers runtime. |
| [`wrangler`](https://github.com/cloudflare/workers-sdk) ^3.114.17 | MIT OR Apache-2.0 | Build / deploy CLI. |
| [`vitest`](https://vitest.dev/) ^2.1.9 + [`@cloudflare/vitest-pool-workers`](https://www.npmjs.com/package/@cloudflare/vitest-pool-workers) | MIT | Test runner against Miniflare. |

External services consumed at runtime:

- [Cloudflare AI Gateway](https://developers.cloudflare.com/ai-gateway/) — inference proxy (revenue-share metering, caching, rate limiting).
- [OpenRouter](https://openrouter.ai/) — multi-provider model router.
- [Stripe](https://stripe.com/) — payments + tax (Stripe Tax handles VAT / GST / sales tax worldwide).

### `addons/saas-controller/` — NV oOS Cloud SaaS Controller (operator-side WP plugin)

**License: Proprietary — © 2026 NV Digital Solutions, All Rights Reserved.**
Unlike the rest of this repository (GPLv3), this addon is proprietary and is
**not** distributed via WordPress.org. See
[`addons/saas-controller/LICENSE`](addons/saas-controller/LICENSE) for the
governing terms; the third-party packages listed below remain under their own
upstream licenses.

Operator-side WordPress plugin that provisions, plan/applies, drift-detects,
and audits the `addons/cloud-worker/` runtime — without leaving WP-Admin.

The addon ships **two compiled artifacts**: `assets/build/index.js`
(`@wordpress/scripts` bundle, the WP-Admin UI) and
`worker/dist/index.js` (esbuild bundle, the Cloudflare Worker). Sources and
`node_modules/` are excluded from the distribution ZIP.

**Bundled at runtime — Admin UI** (`assets/build/index.js`):

| Package | License | Purpose |
|---|---|---|
| [`@tanstack/react-query`](https://tanstack.com/query) ^5.62.0 | MIT | Polling reconcile-job status, drift results, audit log. |
| [`zod`](https://zod.dev/) ^3.24.1 | MIT | Client-side schema validation of credentials & reconcile-plan JSON. |
| [`diff`](https://github.com/kpdecker/jsdiff) ^7.0.0 | BSD-3-Clause | Plan-preview before/after rendering. |
| [`date-fns`](https://date-fns.org/) ^4.1.0 | MIT | Audit-log timestamps and "last checked X ago" labels. |
| [`clsx`](https://github.com/lukeed/clsx) ^2.1.1 | MIT | Conditional className helper. |

WordPress core externals (`@wordpress/element`, `@wordpress/components`,
`@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/data`,
`@wordpress/icons`, `@wordpress/url`) are auto-externalized by
`@wordpress/scripts` and are not bundled.

**Build-time / dev-only — never shipped**:
[`wrangler`](https://github.com/cloudflare/workers-sdk) ^4.59.1 (MIT OR Apache-2.0; pinned at this floor because earlier versions are affected by a published GHSA OS-command-injection advisory in `wrangler pages deploy`),
[`esbuild`](https://github.com/evanw/esbuild) ^0.24.2 (MIT),
[`@cloudflare/workers-types`](https://github.com/cloudflare/workerd) ^4.x (Apache-2.0),
[`miniflare`](https://github.com/cloudflare/workers-sdk) ^4.x (MIT),
[`@wordpress/scripts`](https://github.com/WordPress/gutenberg/tree/trunk/packages/scripts) ^30 (GPL-2.0-or-later),
[`typescript`](https://github.com/microsoft/TypeScript) ^5.7 (Apache-2.0),
[`npm-run-all`](https://github.com/mysticatea/npm-run-all) ^4 (MIT).

The full per-package license + copyright table is in
[`addons/saas-controller/THIRD_PARTY_NOTICES.md`](addons/saas-controller/THIRD_PARTY_NOTICES.md).

### `addons/media-worker/` — Docker-based media processing sidecar (v2.2.0)

Node.js/Express service that offloads heavy media work from WordPress. All
runtime packages are declared in `addons/media-worker/package.json`. The
v2.2.0 security release added **helmet** and **express-rate-limit**.

| Package | Version | License | Purpose |
|---|---|---|---|
| `express` | ^4.21.0 | MIT | HTTP framework for the 11 route handlers. |
| `helmet` ⭐ v2.2.0 | ^8.0.0 | MIT | Security headers (CSP, HSTS, X-Content-Type-Options, etc.). |
| `express-rate-limit` ⭐ v2.2.0 | ^7.5.0 | MIT | Global + per-route-group rate limiting. |
| `cors` | ^2.8.5 | MIT | Restricted CORS (`ALLOWED_ORIGINS`). |
| `puppeteer` | ^25.7.0 | Apache-2.0 | Sandboxed headless-browser automation. |
| `sharp` | ^0.34 | Apache-2.0 | High-performance image processing. |
| `canvas` | ^2.11.2 | MIT | Server-side canvas rendering. |
| `tesseract.js` | ^6 | Apache-2.0 | OCR route backend. |
| `pdf-lib` / `pdfkit` / `pdf-parse` / `pdfjs-dist` | — | MIT / MIT / MIT / Apache-2.0 | PDF generation and text extraction. |
| `fluent-ffmpeg` | ^2.1.3 | MIT | Video transcoding, frame extraction, GIF conversion. |
| `mjml` | ^4 | MIT | Email template rendering. |
| `nodemailer` | ^7 | MIT | Email sending. |
| `multer` | ^2 | MIT | Multipart upload handling. |
| `exceljs` / `docx` | ^4.4.0 / ^9.5.1 | MIT | Spreadsheet and Word document generation. |
| `axios` | ^1.7.0 | MIT | Outbound HTTP client. |
| `cheerio` | ^1.0.0 | MIT | HTML parsing/scraping. |
| `openai` / `@google/generative-ai` | — / ^0.21.0 | Apache-2.0 | AI generation backends. |
| `mathjs` | ^14 | Apache-2.0 | Math/statistics utilities. |
| `katex` | ^0.16.0 | MIT | Math rendering. |
| `qrcode` | ^1.5.0 | MIT | QR code generation. |
| `chart.js` + `chartjs-node-canvas` | ^4.4.0 / ^5.0.0 | MIT | Server-rendered charts. |
| `marked` / `turndown` | ^9.1.6 / ^7 | MIT | Markdown/HTML conversion. |
| `validator` | ^13 | MIT | Input validation. |
| `ioredis` | ^5.4.0 | MIT | Redis job queuing. |
| `@turf/turf` | ^7.0.0 | MIT | Geospatial analysis. |
| `ics` | ^3.7.0 | ISC | Calendar ICS export. |
| `franc` / `iso-639-1` | ^6.1.0 / ^3.1.0 | MIT | Language detection + codes. |
| `google-translate-api-x` | ^10.7.0 | MIT | Auto-translation. |
| `mailparser` | ^3.9.9 | MIT | Inbound email parsing. |
| `libphonenumber-js` | ^1.11.21 | MIT | Phone number handling. |
| `subtitle` | ^4 | MIT | Subtitle parsing. |
| `gif-encoder` | ^0.7.2 | MIT | GIF encoding. |
| `regression` | ^2 | MIT | Trend/regression analysis. |
| `currency.js` | ^2.0.4 | MIT | Currency math. |
| `csv-parse` / `csv-stringify` / `fast-csv` | ^5.6.0 / ^6.5.2 / ^5.0.0 | MIT | CSV parsing/serialization. |
| `dotenv` | ^16.4.0 | BSD-2-Clause | Environment configuration. |
| `prettier` | ^3 | MIT | Code formatting (build-time). |
| `@neplex/vectorizer` | ^0.0.5 | MIT | Vector embedding helpers. |

Deployment: Docker image (`addons/media-worker/Dockerfile`). Mirrored one-way to the
standalone repo `nvdigitalsolutions/mcp-ai-wpoos-media-worker` via
`sync-media-worker.yml`.

---

## NV oOS First-Party Standalone Packages (`packages/`)

These packages are published separately under `@nvdigitalsolutions/` and bundled with the Pro addon's chat service layer. All are © NV Digital Solutions, MIT-licensed.

### Tier 5 — Chat Service Utilities (added May 2026)

| Package | Description | License |
|---------|-------------|---------|
| `@nvdigitalsolutions/nvoos-client-tools` | Browser-native AI tool registry (summarize, sentiment, translate, embed, image, audio) | MIT |
| `@nvdigitalsolutions/nvoos-chat-memory` | Promise-based REST client for the AI chat memory bridge (wake-up, recall, store, audit, preferences) | MIT |
| `@nvdigitalsolutions/nvoos-attachments` | File attachment helpers: type detection, validation, normalisation | MIT |
| `@nvdigitalsolutions/nvoos-cron-status` | SSE-first cron/job status monitor with REST polling fallback | MIT |
| `@nvdigitalsolutions/nvoos-transcription` | MediaRecorder-based audio recording + tool-call transcription pipeline | MIT |

### Tier 4 — Browser-AI Runtime Packages (added April 2026)

| Package | Description | License |
|---------|-------------|---------|
| `@nvdigitalsolutions/nvoos-llm-worker` | SharedWorker wrapper for in-browser LLM inference | MIT |
| `@nvdigitalsolutions/nvoos-model-loader` | Browser-side model caching and loader for WebGPU/WebAssembly backends | MIT |
| `@nvdigitalsolutions/nvoos-transformers-client` | Thin type-safe wrapper around `@huggingface/transformers` | MIT |

### Earlier tiers (Tiers 1–3) — Core Chat Utilities

| Package | Description | License |
|---------|-------------|---------|
| `@nvdigitalsolutions/nvoos-storage` | Storage utilities (localStorage, IndexedDB wrappers) | MIT |
| `@nvdigitalsolutions/nvoos-markdown` | Markdown rendering utilities | MIT |
| `@nvdigitalsolutions/nvoos-events` | Event system helpers | MIT |
| `@nvdigitalsolutions/nvoos-clipboard` | Clipboard management | MIT |
| `@nvdigitalsolutions/nvoos-http-client` | Typed HTTP client with retry support | MIT |
| `@nvdigitalsolutions/nvoos-offline-sync` | Offline sync primitives | MIT |
| `@nvdigitalsolutions/nvoos-slash-commands` | Slash command parsing + registration | MIT |
| `@nvdigitalsolutions/nvoos-audio` | Audio recording and playback utilities | MIT |
| `@nvdigitalsolutions/nvoos-dom-batcher` | DOM batching utilities | MIT |

---

## Vendored JavaScript — Base Plugin

| Location | Component | Version | License | Upstream |
|----------|-----------|---------|---------|----------|
| `assets/js/vendor/chart.min.js` | Chart.js | 4.4.1 | MIT | <https://github.com/chartjs/Chart.js> |
| `assets/js/vendor/konva/konva-9.3.16.min.js` | Konva | 9.3.16 | MIT | <https://github.com/konvajs/konva> |
| `assets/js/vendor/neplex-vectorizer/` | @neplex/vectorizer | 0.0.5 | MIT | <https://github.com/neplextech/vectorizer> |

---

## Bundled Agent Skills

NV oOS ships curated [agentskills.io](https://agentskills.io/specification)
skill packs. Per-skill attribution (upstream commit, original author,
license) is maintained in:

* **Base plugin skills:**
  [`includes/bundled-skills/THIRD_PARTY_NOTICES.md`](includes/bundled-skills/THIRD_PARTY_NOTICES.md)
* **Pro addon skills:**
  [`addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`](addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md)

Primary upstream sources:

* [`anthropics/skills`](https://github.com/anthropics/skills) — © Anthropic, MIT-licensed.
* [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) — © Soczó Kristóf (Lonsdale201), MIT-licensed.

Each individual `SKILL.md` carries `source:` and `license:` frontmatter that
points back to its upstream copy.

---

## Fonts

Embedded by the TCPDF library inside the Pro addon for PDF generation:

| Font | Location | License | Author |
|------|----------|---------|--------|
| DejaVu (TTF) | `addons/pro/vendor/tecnickcom/tcpdf/fonts/dejavu-fonts-ttf-2.33/`, `…/dejavu-fonts-ttf-2.34/` | DejaVu Fonts License (Bitstream Vera derivative, free for redistribution) | DejaVu Fonts project — <https://dejavu-fonts.github.io/> |
| FreeFont | `addons/pro/vendor/tecnickcom/tcpdf/fonts/freefont-20100919/`, `…/freefont-20120503/` | GPLv3+ with font exception | GNU FreeFont project — <https://www.gnu.org/software/freefont/> |
| KaTeX fonts | bundled with `katex` npm package (Pro) | OFL-1.1 / MIT | KaTeX project — <https://github.com/KaTeX/KaTeX> |

Each font directory contains its original `LICENSE`, `CREDITS`, or `README`
file with full attribution; nothing has been altered.

---

## Methodology & Inspiration (non-code)

These projects influenced the design of NV oOS's memory, workflow, and graph
subsystems. No code was copied — but the conceptual debt is real and worth
naming:

| Project | Influence | Upstream |
|---------|-----------|----------|
| **MemPalace** | Hierarchical scope (wing/room), verbatim-storage discipline; cited in source-file headers across the agent-memory subsystem | <https://github.com/MemPalace/mempalace> |
| **Letta / MemGPT** | Memory tier model, verbatim immutability flag, `expires_at` TTL anchor | <https://github.com/letta-ai/letta> |
| **Zep** | Bi-temporal memory validity, source provenance | <https://github.com/getzep/zep> |
| **mem0** | Importance scoring, verbatim discipline, source tracking | <https://github.com/mem0ai/mem0> |
| **Cognee** | Knowledge-graph-first memory retrieval | <https://github.com/topoteretes/cognee> |
| **BMAD Method** | Six-agent role split (Analyst / PM / Architect / SM / Dev / QA) inside `.bmad/agents/` | <https://github.com/bmadcode/BMAD-METHOD> |
| **GSD** | "Get Stuff Done" 30% rule for context-window discipline; 10-phase workflow gates | (community-curated; informal) |

See [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](docs/AGENT-MEMORY-COMPLETE-GUIDE.md)
and [`AGENTS.md`](AGENTS.md) for fuller context.

---

## Optional Third-Party WordPress Plugins

NV oOS integrates with — but does not bundle or redistribute — these
WordPress plugins. They are installed separately and remain owned by their
respective authors. Where applicable, NV oOS uses an affiliate referral link.

| Plugin | Vendor | Link |
|--------|--------|------|
| JetEngine | Crocoblock | <https://crocoblock.com/plugins/jetengine/?ref=16658> |
| WooCommerce | Automattic | <https://woocommerce.com/> |
| Elementor | Elementor Ltd. | <https://elementor.com/> |
| Rank Math | Rank Math team | <https://rankmath.com/> |
| WPCode | AwesomeMotive | <https://wpcode.com/> |

---

## External AI / API Providers

NV oOS connects to third-party AI and SaaS APIs at runtime when configured
by the site administrator. These services are **not bundled** and remain
governed by their own terms. The full list (with Terms / Privacy links) lives
in the **External Services** section of [`readme.txt`](readme.txt).

Headline providers acknowledged here for completeness:

* **OpenAI** — <https://openai.com/>
* **Google Gemini** — <https://ai.google.dev/>
* **Anthropic Claude** — <https://www.anthropic.com/>
* **Ollama** (local) — <https://ollama.com/>
* **LM Studio** (local) — <https://lmstudio.ai/>
* **OpenRouter** (unified gateway) — <https://openrouter.ai/>
* **DeepSeek** — <https://www.deepseek.com/>
* **Kimi (Moonshot AI)** — <https://platform.moonshot.ai/> (OpenAI-compatible API at `https://api.moonshot.ai/v1`; Kimi K2.7 Code, K2.6, K2.5, K2 with 256K context, tool calling, and thinking mode).
* **DigitalOcean Serverless Inference** — <https://www.digitalocean.com/products/ai> (OpenAI-compatible API at `https://inference.do-ai.run/v1`; Llama 3.3, DeepSeek-R1 distill, gpt-oss, plus native `/embeddings`).
* **Crawl4AI** — <https://github.com/unclecode/crawl4ai>

---

## How to Update This File

Whenever any of the following change, update **this file** and the matching
secondary surfaces:

| You changed… | Update |
|--------------|--------|
| `composer.json` (base or Pro) | This file's PHP tables; run `composer install` after editing the manifest and refresh the listed versions to match `composer.lock` |
| `package.json` (base or Pro or any addon) | This file's JavaScript tables; refresh versions; run `npm audit` |
| Any file under `**/assets/**/vendor/` | This file's "Vendored JavaScript" section + the per-addon Credits section in that addon's `README.md` |
| A new bundled skill curated upstream | The matching `bundled-skills/THIRD_PARTY_NOTICES.md` (do **not** duplicate here) |
| A new font added under `addons/pro/vendor/tecnickcom/tcpdf/fonts/` | This file's Fonts section |
| A new methodology / paper citation | This file's "Methodology & Inspiration" section |

The `== Credits ==` block in [`readme.txt`](readme.txt) carries a curated short
list and a link back to this file — keep them in sync.

In-product attribution lives on the **NV oOS → Pro Packages** admin screen
for Pro npm packages: the page renders upstream URL + license + copyright
from the same data this file mirrors. See
[`addons/pro/includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php`](addons/pro/includes/admin/class-wp-mcp-ai-pro-packages-settings-page.php).

---

## Reporting an Attribution Error

If something on this page is missing, mis-licensed, or pointing at the wrong
upstream, please open an issue at
<https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues> with the label
`credits`. Attribution corrections are always treated as priority fixes.
