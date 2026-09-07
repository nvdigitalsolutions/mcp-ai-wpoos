# includes/admin — Comic Reader Settings

## Purpose

Hosts the Comic Reader addon's settings page and the site-wide option storage that drives reader defaults, upload limits, and capability overrides.

## Tier

Addon (standalone, requires the NV oOS base plugin) · PHP 7.4+ · No optional dependencies.

## Public Surface

- `NV_oOS_Comic_Reader_Settings` — static settings store and admin page:
  - `get( $key, $default )` / `get_all()` — merged settings values
  - `get_capability( $context )` — effective capability (setting → filter → built-in)
  - `get_reader_defaults()` — reader defaults for SPA localization
  - `get_max_upload_bytes()` / `progress_sync_enabled()` / `cover_extraction_enabled()` — runtime gates
  - `sanitize_settings( $input )` — whitelist sanitizer for the Settings API

## Inputs / Outputs / Neighbors

- Inputs: the `nvoos_comic_reader_settings` option (defaults in `DEFAULTS`); the `nvoos_comic_reader_*_capability` and `nvoos_comic_reader_max_upload_bytes` filters.
- Outputs: values consumed by `includes/rest/` (permission callbacks, upload limits, cover extraction) and `includes/shortcode/` (localized SPA defaults).
- Neighbors: `includes/rest/class-nvoos-comic-reader-rest.php`, `includes/shortcode/class-nvoos-comic-reader-shortcode.php`, `uninstall.php` (option cleanup).

## Conventions

- Settings are stored as one array option; keys are lowercase snake_case.
- Capability fields are blank by default (meaning "use the filter default") — never store a hardcoded default capability.

## Tests

`addons/comic-reader/tests/test-settings.php` — defaults merge, sanitizer whitelist, capability resolution, and shortcode default fallbacks. Run with the repo PHPUnit harness against `addons/comic-reader/tests`.

## Also Load

- `.context/security-checklist.md` — sanitizing/escaping rules for admin output
- `.context/conventions.md` — naming and structure rules
- `docs/developer/folder-readme-convention.md` — this file's template
