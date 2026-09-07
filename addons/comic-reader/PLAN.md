# NV oOS Comic Reader — Industry Standards Upgrade Plan

> Reference model: [Komga](https://komga.org) (the de-facto standard self-hosted
> comic/manga server), cross-checked against Mihon/Tachiyomi conventions, Kavita,
> YACReader, ComicRack, and the ComicInfo.xml / OPDS ecosystem.
>
> Addon version covered: **0.2.0**. Research date: 2026-09-07.

---

## 1. Executive summary

The addon is a solid v0.2 foundation: a React 18 SPA, client-side archive
extraction via libarchive.js in a Web Worker, a REST API over the WordPress
Media Library, a 5-step AI creator, and a clean build/publish pipeline.

Measured against Komga and industry reader conventions it has **three structural
gaps** and a handful of concrete defects:

1. **The reader lacks the settings model every serious reader has** — Komga's
   webreader persists reading mode, scale type, background color, double-page
   behavior, transitions, and gesture preferences. This addon resets all reader
   prefs on every open and has no settings surface at all (see the dead
   `nvoos_comic_reader_settings` option).
2. **No metadata/organization model** — Komga organizes by library → series →
   book, imports ComicInfo.xml embedded metadata, and offers collections and
   read lists. This addon exposes a flat, search-only attachment list and never
   even displays covers (a live bug, not just a missing feature).
3. **Progress is device-local** — Komga syncs per-user read progress server-side
   and powers a "continue reading" shelf; this addon uses only `localStorage`.

Recommended path: **Phase 0 (correctness + security) → Phase 1 (reader parity) →
Phase 2 (metadata/organization/progress) → Phase 3 (settings page + ecosystem)**,
shipping 0.2.1, 0.3.0, 0.4.0, 0.5.0 respectively.

---

## 2. Research summary — what the reference implementations do

### 2.1 Komga's feature model (GitHub README + DIVINA webreader docs)

**Organize**
- Hierarchical model: **libraries → series → books** (books = CBZ/CBR/PDF/EPUB).
- **Collections** and **read lists** across libraries.
- **Metadata editing** for series and books; **embedded-metadata import**
  (ComicInfo.xml / ComicRack schema).
- Multi-user with **per-library access control**, age restrictions, label
  restrictions.
- **Duplicate file detection**; duplicate page detection and removal.
- Import books into series folders; import ComicRack `cbl` read lists.

**Read (DIVINA webreader)**
- **Four reading modes**: left-to-right, right-to-left, **vertical (scroll)**,
  and **webtoon** (continuous vertical strip of all pages).
- Reader **automatically uses the book's metadata reading direction**.
- **Settings dialog** (gear icon): reading mode, page-transition animation,
  gestures on/off, background color (**white / gray / black**), scale type
  (**fit to screen / fit to width / fit to height / original**), double pages.
- **Double-page rules**: first and last page always render single; landscape
  pages (width > height) render single.
- **Thumbnails explorer** — grid overlay for jumping to any page.
- **Help dialog** listing context-aware keyboard shortcuts.
- All settings **persist per user** (server-side).

**Integrate**
- REST API; **OPDS v1.2 and v2.0** feeds; Kobo Sync; KOReader Sync.
- Server-side **page streaming and thumbnails** (never ships whole archives to
  the client).
- Per-user **read progress** API + "continue reading".

### 2.2 Cross-tool ecosystem standards

| Standard | What it is | Relevance |
|---|---|---|
| **ComicInfo.xml** (ComicRack schema ≤2.1) | De-facto embedded metadata in CBZ/CBR/CB7: Title, Series, Number, Volume, Writer, Publisher, Genre, PageCount, `Manga` / `RightToLeft` flags | The single highest-value metadata feature: enables series grouping **and** automatic RTL detection |
| **OPDS 1.2 / OPDS-PSE 2.0** | Standard acquisition/catalog feeds read by Mihon, Panels, YACReader, KyBook | Optional interop surface for external readers |
| **Mihon/Tachiyomi reader defaults** | Fit-to-width default, RTL auto-detect, per-series reader prefs, tap zones, double-page exceptions | Confirms Komga's defaults are industry defaults |
| **IANA MIME** `application/vnd.comicbook+zip` | Registered CBZ MIME | Our `application/zip` mapping is interoperable; consider accepting the vnd type on upload |
| Webtoon/vertical | Continuous vertical scroll as a first-class mode (Komga, OpenComic, Comixor, webtoon platforms) | Missing entirely today |

### 2.3 What maps to a WordPress addon — and what doesn't

Komga is a standalone server with its own user system, filesystem, and
transcoding. A WP addon should adopt Komga's **reader UX and metadata model**
but keep WP primitives:

| Komga concept | WP equivalent in this addon |
|---|---|
| Library | Media Library (attachment) — already used |
| Series / collection / read list | WP taxonomy (`nvoos_comic_series`) or `mcp_ai_comic` meta |
| Book metadata | Attachment meta keyed by attachment ID |
| Per-user access / age limits | WP capabilities + the existing `nvoos_comic_reader_can_render` filter |
| User progress | User meta or a small custom table |
| Page streaming / thumbnails | Keep client-side extraction (it's the addon's differentiator); add server-side cover extraction for CBZ via PHP ZipArchive as an optional accelerator |
| OPDS feed | Optional REST route (Phase 2/3) |
| Kobo/KOReader sync, duplicate page removal, cbl import | **Out of scope** — see §7 |

---

## 3. Current state snapshot (v0.2.0)

**Working today**
- Formats CBZ/CBR/CB7/CBT via `upload_mimes` + `wp_check_filetype_and_ext` fixes
  (`includes/class-nvoos-comic-reader-mime.php`).
- REST surface: `health`, `manifest`, `comics` (list/get/file/cover/delete),
  `upload`, and full `/creator/*` set (`includes/rest/class-nvoos-comic-reader-rest.php`).
- Library grid, reader (single/double page, zoom 25–400 %, fit width/height,
  LTR/RTL toggle, fullscreen), upload view, 5-step creator (`src/`).
- Shortcode + Gutenberg block; build ZIP pipeline with SHA-256
  (`.github/workflows/build-comic-reader-addon.yml`).

**Defects found during research (fix in Phase 0)**
1. **Covers never render.** `format_comic_item()` hard-codes `cover_url: ''`;
   `fetchComicCover()` exists but no component calls it. The library shows
   placeholder tiles only. `_nvoos_comic_cover_id` meta is read but never written.
2. **Fullscreen listener leak.** `useReaderState` registers
   `document.addEventListener('fullscreenchange', …)` in the hook body (every
   render) instead of a `useEffect` with cleanup.
3. **Keyboard shortcuts documented but not implemented.** The `useKeyboardNav`
   header promises `F` (fullscreen), `W` (fit width), `H` (fit height); the
   switch only handles arrows, A/D, +/−.
4. **README overclaims touch support.** Zero touch handlers exist in `src/`
   (no swipe, no pinch). Either implement or correct the README.
5. **Dead code**: `createWorkerFromSource()` in `ComixReader.tsx`; the
   `nvoos_comic_reader_settings` option deleted by `uninstall.php` is written by
   nothing (Phase 3 wires it up).
6. **i18n gaps**: hard-coded `Retry`, `No pages to display.`, `+ Create`,
   `+ Upload`, and `` `Page ${n}` `` alt text bypass `wp.i18n`/`t()`.
7. **RTL double-page ordering** is ignored — the spread always renders
   `currentPage` on the left regardless of direction.
8. **Library ignores API pagination** — `page`/`per_page`/`search` are supported
   by REST but the UI neither searches nor paginates.
9. **Security hardening needed** (see Phase 0): extension-only upload
   validation, whole-file `file_get_contents()` serving (memory blowout), broad
   `delete_posts` delete gate, no byte-range support, no size cap.

---

## 4. Gap analysis (Komga/industry → current addon)

| Capability | Komga / industry | Current addon | Gap | Phase |
|---|---|---|---|---|
| Reading modes | LTR, RTL, vertical, webtoon | LTR, RTL (paged only) | Missing vertical + webtoon | 1 |
| Scale types | Fit screen / width / height / original | Width, height, free zoom | Missing fit-screen + original + shrink-only | 1 |
| Double-page rules | First/last single; landscape single | Naive 2-up, RTL order wrong | Add exceptions + RTL spread | 0/1 |
| Background color | White / gray / black | Fixed | Missing | 1 |
| Page transitions | Fade/animate option | None | Missing | 1 |
| Settings dialog + persistence | Per-user, persisted | None (resets each open) | Missing | 1 |
| Thumbnails explorer | Yes | No | Missing | 1 |
| Help dialog w/ shortcuts | Context-aware | None; shortcuts unimplemented | Missing | 1 |
| Gestures | Swipe/pinch/tap zones, toggleable | None (README overclaims) | Missing | 1 |
| Reading direction auto-detect | From book metadata | Manual per-embed toggle | Missing (needs ComicInfo) | 2 |
| Continue reading | Per-user server-side | `localStorage` only | Server sync | 2 |
| Series/collection grouping | Libraries, series, collections, read lists | Flat list | Missing | 2 |
| Embedded metadata | ComicInfo.xml import | Not parsed | Missing | 2 |
| Metadata editing | Series + book editors | None | Missing | 2 |
| Covers | Server thumbnails | Never displayed (bug) | Fix | 0 |
| Search/filter/sort/pagination | Faceted | API-only, no UI | Missing | 2 |
| OPDS feeds | v1.2 + v2.0 | None | Optional | 2/3 |
| Per-user access control | Per-library, age, labels | `read` cap + one filter | Cap/filter refinement | 3 |
| Settings/admin surface | Server settings | None (dead option) | Settings page | 3 |
| Upload validation | Real parsing, duplicate detection | Extension-only | Magic bytes + size cap | 0 |
| Large-file serving | Page streaming, ranges | Whole file into memory | Range/stream support | 0/1 |

---

## 5. Roadmap

### Phase 0 — Correctness, security, quick wins → **v0.2.1**

**Goal**: fix the defects in §3 before adding surface area.

1. **Covers**
   - Client-side: `ComicLibrary` extracts page 1 via the existing worker and
     renders a blob thumbnail; cache to `_nvoos_comic_cover_id` via a new
     `POST /comics/{id}/cover` (upload_files) using PHP `ZipArchive` for CBZ
     (CBZ ≈ 90 % of files; other formats stay client-side).
   - `format_comic_item()` populates `cover_url` from the meta when present.
   - Files: `includes/rest/class-nvoos-comic-reader-rest.php`,
     `src/components/ComicLibrary.tsx`, `src/api/comic-api.ts`.
   - Accept: library grid shows covers; cache hit skips extraction.
2. **Fix `useReaderState`** fullscreen listener → `useEffect` with cleanup.
   Files: `src/hooks/useReaderState.ts`. Accept: no duplicate listeners
   (assert via test).
3. **Remove dead code** (`createWorkerFromSource`). File: `src/components/ComixReader.tsx`.
4. **Implement F/W/H shortcuts** and fix the hook docblock. File:
   `src/hooks/useKeyboardNav.ts` (+ pass new callbacks from `ComixReader.tsx`).
   Accept: vitest coverage for all shortcuts.
5. **i18n pass** — route every hard-coded UI string through `t()`; add the new
   keys to the `wp_localize_script` i18n array in
   `includes/shortcode/class-nvoos-comic-reader-shortcode.php`.
6. **RTL double-page ordering** — flip spread order when `direction === 'rtl'`.
   File: `src/components/ComixReader.tsx` / `PageViewer.tsx`. Accept: RTL manga
   spreads read right→left.
7. **Upload security**
   - Validate archive **magic bytes** (ZIP `PK\x03\x04`, RAR `Rar!`, 7z
     `7z\xbc\xaf\x27\x1c`, TAR `ustar` at 257) in `upload_comic` before
     `media_handle_upload`.
   - Enforce a configurable max upload size filter
     (`nvoos_comic_reader_max_upload_bytes`, default e.g. 256 MB).
   - Files: `includes/rest/class-nvoos-comic-reader-rest.php`,
     `includes/class-nvoos-comic-reader-mime.php`. Accept: garbage renamed to
     `.cbz` is rejected; test coverage.
8. **Serving security/performance**
   - Replace `file_get_contents` with `WP_Filesystem`/chunked streaming and add
     **HTTP Range** support (`206 Partial Content`) so large archives can be
     downloaded progressively and browsers can retry.
   - File: `includes/rest/class-nvoos-comic-reader-rest.php`. Accept: range
     request test returns 206 with correct `Content-Range`.
9. **Scoped delete permission** — check `current_user_can( 'delete_post', $id )`
   on the attachment (owner-aware) instead of global `delete_posts`; add
   `nvoos_comic_reader_delete_permission` / `upload_permission` filters.
   Accept: editor cannot delete another user's comic.
10. **Docs honesty** — remove unsupported touch/zoom claims from `README.md`
    until Phase 1 implements them; document the new filters.

### Phase 1 — Reader parity with Komga's DIVINA webreader → **v0.3.0**

**Goal**: close every reader-UX gap in §4 rows 1–8.

1. **Reader settings dialog + persistence**
   - New `ReaderSettings` component (gear icon): reading mode, scale type,
     background (white/gray/black), double page, transitions (none/fade/slide),
     gestures on/off.
   - Persist under `nvoos_cr_prefs` (localStorage, per-user-session) and apply
     on mount; per-comic override key `nvoos_cr_prefs_{id}` (Komga persists
     globally but applies per-book direction from metadata — mirror this).
   - Files: `src/components/ReaderSettings.tsx`, `src/hooks/useReaderState.ts`.
2. **Scale types** — add `fit-screen` (contain) and `original`; "shrink-only"
   variant for fit-width (Komga issue #584 pattern). File:
   `src/components/PageViewer.tsx`.
3. **Vertical + webtoon modes**
   - `mode: 'scroll'` renders pages stacked with lazy loading (Intersection
     Observer) and scroll-snap; `mode: 'webtoon'` is continuous with no gaps.
   - Progress = scroll position. Files: new `src/components/ScrollViewer.tsx`,
     `src/hooks/useReaderState.ts`.
4. **Double-page exceptions** — first/last page single; landscape page single
   (width > height check on natural image size). File: `ComixReader.tsx`.
   Accept: parity with Komga's documented rules.
5. **Thumbnails explorer** — grid overlay of all extracted pages, click to
   jump, current page highlighted. File: new `src/components/ThumbnailsExplorer.tsx`.
6. **Help dialog** — context-aware shortcuts list (from `useKeyboardNav`).
   File: new `src/components/HelpDialog.tsx`.
7. **Touch/gestures** — swipe (horizontal in paged modes, native scroll in
   vertical), pinch zoom, configurable tap zones (left 1/3 prev, center toggle
   UI, right 1/3 next) in paged mode; gestures toggleable. Files:
   `PageViewer.tsx`, new `src/hooks/useTouchGestures.ts`.
8. **Background color** — CSS custom properties on the reader root
   (`--nvoos-cr-bg`), default black for webtoon, gray for paged.
9. **Continue reading (local)** — library "Continue reading" section sorted by
   last-read timestamp with % badge; already-available localStorage keys get a
   companion `nvoos_cr_lastread_{id}` timestamp.
10. **Transitions** — CSS fade/slide between page changes behind
    `prefers-reduced-motion` guard.

**Exit criteria**: feature parity with the Komga webreader settings list
(§2.1 Read section), verified against a checklist.

### Phase 2 — Metadata, organization, progress sync → **v0.4.0**

**Goal**: Komga's Organize column, adapted to WP primitives.

1. **ComicInfo.xml parsing**
   - Extend `archive-worker.ts` to surface `ComicInfo.xml` (Title, Series,
     Number, Volume, Writer, Penciller, Publisher, Genre, PageCount, Manga,
     RightToLeft).
   - Expose via new REST: `POST /comics/{id}/metadata` (uploads the parsed
     payload; server stores in attachment meta) and `PUT /comics/{id}` (edit
     title/series/…). Server side: PHP XML reader with entity-guard (no
     external entities — `LIBXML_NONET`).
   - Files: `src/api/archive-worker.ts`, `includes/rest/class-nvoos-comic-reader-rest.php`.
   - Accept: a manga CBZ with `RightToLeft=Yes` auto-opens RTL.
2. **Series taxonomy** — register `nvoos_comic_series` (or reuse
   `mcp_ai_comic` structures after a decision, see §10 Q2); library groups by
   series with collapsible sections and a series detail view.
3. **Collections / read lists** — taxonomy terms with a "add to list" affordance;
   read lists = ordered attachment lists (term meta).
4. **Library UI** — search box, sort (title/date/size), filter (format, series),
   real pagination (wire existing API params), % read badges.
   Files: `src/components/ComicLibrary.tsx`, `src/api/comic-api.ts`.
5. **Server-side progress sync**
   - `POST /comics/{id}/progress` (current page / completed / last-read) →
     user meta; `GET /comics/progress` returns map; library "Continue reading"
     reads server first, falls back to localStorage.
   - Gate behind `is_user_logged_in`; per-user isolation.
   - Accept: progress survives browser switch on the same account.
6. **Metadata editor** — small edit dialog per comic (title, series, number,
   publisher, reading direction) → `PUT /comics/{id}`.
7. **OPDS feeds (optional, stretch)** — `GET /opds/1.2` catalog + acquisition
   feed so Mihon/Panels can browse the library read-only. Low cost, high
   interop; defer if Phase 2 is large.

### Phase 3 — Admin settings, permissions, ops → **v0.5.0**

**Goal**: the missing settings surface (the option already "exists" in
`uninstall.php`) and ecosystem polish.

1. **Settings page** (Settings → NV oOS → Comic Reader, or under the
   `mcp_ai_comic` menu — see §10 Q3)
   - **Reader defaults**: direction, reading mode, scale type, background,
     double-page default, transitions, gestures (global defaults the shortcode
     can override).
   - **Library**: upload size cap, allowed formats, who can upload/delete
     (capability dropdowns), cover-extraction toggle.
   - **Progress**: enable/disable server sync; retention.
   - Store in `nvoos_comic_reader_settings` (register default via
     `add_option`/`get_option` with a defaults array); wire the option properly
     so the existing `uninstall.php` cleanup becomes meaningful.
   - Files: new `includes/admin/class-nvoos-comic-reader-settings.php` +
     `includes/admin/README.md` (folder-README convention) + enqueue on the
     settings page only.
2. **Shortcode/block defaults from settings** — `render()` merges attribute
   overrides over stored defaults; new `defaults` attribute.
3. **Capability filters** — add `nvoos_comic_reader_read_capability`,
   `nvoos_comic_reader_upload_capability`, `nvoos_comic_reader_delete_capability`
   filters over the existing permission callbacks so Pro/membership plugins can
   integrate (document alongside `nvoos_comic_reader_can_render`).
4. **Accessibility pass** — replace `role="application"` with `role="region"`;
   focus management + focus trap in dialogs; `prefers-reduced-motion` in
   transitions; contrast-checked backgrounds; run the existing `lint:a11y`.
5. **Docs** — README rewrite (features, settings, hooks, API table), release
   notes, addon page copy in
   `addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php`, and
   `docs/` references if the platform docs index the addon.
6. **Tests** — PHPUnit for settings defaults, permission filters, progress
   endpoints, range serving; vitest for settings dialog, scroll/webtoon modes,
   thumbnails; keep the addon green in `composer run test`.

---

## 6. Cross-cutting work items

- **Version bumps + changelog** per phase (`nvoos-comic-reader.php` header +
  `package.json` must stay in sync — the build pipeline reads the PHP header).
- **Folder READMEs**: any new PHP-bearing subdirectory (e.g. `includes/admin/`)
  must ship a `README.md` per `docs/developer/folder-readme-convention.md`;
  `composer run docs:check-folder-readmes` must pass.
- **i18n**: every new string through `t()`/`__()` with the
  `nvoos-comic-reader` domain; regenerate POT if the addon ships one.
- **WPCS/PHP 7.4 floor**: new PHP must pass `composer run lint` and
  `lint:compat` from the repo root (or addon-local equivalent).
- **Bundle size watch**: keep the ZIP ~≤1 MB; lazy-load the WASM path and
  new components rather than growing the initial chunk.

---

## 7. Explicitly out of scope (with rationale)

| Komga feature | Why out of scope |
|---|---|
| Kobo Sync / KOReader Sync | Requires dedicated device-sync servers; no WP analogue; niche for a WP addon |
| Multi-user age/label restrictions | WP roles + membership plugins cover this; expose via the capability filters in Phase 3 |
| Duplicate file / page detection & removal | Server-side image analysis; expensive; revisit if users request dedupe |
| ComicRack `cbl` read-list import | Vendor format; OPDS/read-list terms cover the use case |
| PDF/EPUB reading | Komga supports these, but our differentiator is archive extraction; PDF would need a separate viewer (pdf.js). Revisit post-Phase 2 if demand exists |
| Server-side page transcoding (WebP/AVIF) | Client-side extraction already renders whatever codec is inside the archive |

---

## 8. Risks & decisions

- **Memory usage on huge archives** (client extraction). Mitigate: Range
  support (Phase 0) + thumbnails + on-demand extraction; document a realistic
  file-size ceiling (default 256 MB).
- **Zip-bomb / malicious archives**: cap entries and total uncompressed size in
  the worker; magic-byte validation server-side (Phase 0). Note the worker is
  the right place for the bomb guard since extraction is client-side.
- **Settings option naming**: `nvoos_comic_reader_settings` already appears in
  `uninstall.php`; adopt it rather than inventing a second key.
- **Series taxonomy vs `mcp_ai_comic` meta**: creator comics live in the
  `mcp_ai_comic` CPT; library comics live in attachments. Keep them separate
  until a unification decision (see §10 Q2) — do not block Phase 2 on it.
- **Back-compat**: no breaking changes to the shortcode attributes, block
  attributes, REST paths, or localStorage keys; add only additive endpoints
  and defaults.

---

## 9. Validation gates

Per phase, before merge:

- `npm test` + `npm run typecheck` + `npm run lint:a11y` in `addons/comic-reader`.
- Addon PHPUnit: `vendor/bin/phpunit addons/comic-reader/tests` (or the
  repo's established addon test invocation).
- `composer run lint` / `lint:compat` on changed PHP.
- Manual matrix: desktop + mobile, LTR + RTL, CBZ + CBR sample files, guest vs
  subscriber vs editor (permission gates), network-throttled large file
  (range/streaming), dark/light theme (background colors).
- Security review pass with the `wp-security-audit` / `wp-security-deep`
  patterns for any new endpoint touching files or uploads.
- Build pipeline dry-run (`npm run build`) and confirm the ZIP contents.

---

## 10. Open questions

1. **Q1 — Scope of server-side extraction.** Do we add a PHP-side page/cover
   extractor (ZipArchive) as an optional accelerator, or stay 100 %
   client-side? Plan assumes CBZ-only server cover extraction.
2. **Q2 — Series model.** Register a dedicated `nvoos_comic_series` taxonomy
   for library attachments, or unify library comics into the existing
   `mcp_ai_comic` CPT ecosystem (consolidate/research pages already target
   it)? Unification is cleaner long-term but a bigger migration; taxonomy is
   reversible and unblocks Phase 2 sooner.
3. **Q3 — Settings page placement.** Top-level under Settings → NV oOS, or a
   submenu of the `mcp_ai_comic` CPT admin where the other comic tooling
   lives? Pro-side pages (`consolidate`, `research`) are CPT submenus.
4. **Q4 — Guest reading.** Komga requires auth. Do we want a filter to open
   read-only library access to guests (the `read_permission` gate makes this a
   one-line filter today, but the REST surface should be reviewed before
   doing so)?
5. **Q5 — OPDS priority.** Worth shipping in 0.4.0 (stretch) or defer to its
   own 0.4.x after progress sync?

---

*Maintained by the NV oOS team. Update this plan as phases complete and record
decisions for §10 in the phase release notes.*
