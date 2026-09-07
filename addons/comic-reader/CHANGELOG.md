# Changelog — NV oOS Comic Reader

## 0.5.0 — 2026-09-07

### Added

- Settings page (Settings → Comic Reader): reader defaults, upload size cap,
  cover extraction toggle, progress sync toggle, and capability overrides.
- Site-wide reader defaults are merged into the shortcode config and seed the
  reader's initial preferences.
- Manifest now reports whether server-side progress sync is enabled.

### Changed

- Permission gates now resolve capabilities as: settings override → filterable
  default → built-in (read/upload/delete/edit contexts).
- The shortcode root container uses `role="region"` instead of
  `role="application"` for better screen-reader semantics.
- Uninstall cleans up per-user reading progress and series/collection terms.

## 0.4.0

### Added

- ComicInfo.xml parsing in the archive worker with automatic RTL detection
  for manga and server-side metadata persistence.
- `nvoos_comic_series` and `nvoos_comic_collection` taxonomies with REST CRUD.
- Per-user server-side reading progress (synced with localStorage fallback).
- Library search, sort, series filter chips, and pagination.
- Metadata editor and collection picker dialogs.

## 0.3.0

### Added

- Komga-parity reader settings: reading mode (paged / vertical / webtoon),
  scale (fit-screen / fit-width / fit-height / original), background colors,
  page transitions, and a touch-gestures toggle — all persisted per comic.
- Double-page rules: first/last page single; landscape pages single.
- Thumbnails explorer and keyboard-help dialog; F/W/H/P/R/G/? shortcuts.
- Swipe, pinch-zoom, and tap-zone gestures; continue-reading shelf.

## 0.2.1

### Added

- Server-side CBZ cover extraction with Media Library caching.
- HTTP Range support for comic file serving.

### Changed

- Uploads validate archive magic bytes, enforce a filterable size cap, and
  support a filterable upload-handler seam.
- Delete permission is scoped to the attachment owner.
- Reading progress persists as structured records (page/total/completed).

### Fixed

- Fullscreen-change listener leak in `useReaderState`.
- RTL double-page spread ordering.
- Covers now render in the library grid.

## 0.2.0

- Initial addon release: CBR/CBZ/CB7/CBT reader with client-side extraction,
  library view, AI comic creator, shortcode, Gutenberg block, and REST API.
