# Settings coverage sweep

Generates `docker/laravel/app-skeleton/resources/settings/coverage.json` — the
map `/admin/settings-coverage` renders and `AdminSettingsCoverageTest` pins.

The admin form renders 601 controls. Fifty-six of them change behaviour in this
runtime. The rest are WordPress settings (a different store), declared-inert
controls, stale generator output, or controls no reader can be found for. The
map states which, per key, with a reason.

## Why it is generated

Every bucket is a claim about source code, and a hand-maintained claim about
code is wrong the first time the code moves. The generator reads the code; the
artefact is its output. Do not edit `coverage.json` by hand — the test compares
it to `SettingsSchema::fields()` and fails on any difference.

## Running it

**Step 1 — dump the schema.** The catalogue is assembled from
`SettingsSchema::coreFields()` (PHP) and `SettingsCatalogue::fields()` (JSON),
so it needs a booted app:

```sh
rm -rf .playwright-mcp/appsnap && cp -a docker/laravel/app-skeleton .playwright-mcp/appsnap

MSYS_NO_PATHCONV=1 docker run --rm --entrypoint php \
  -v "$PWD/.playwright-mcp/appsnap:/app" \
  -v "$PWD:/workspace/oos:ro" \
  -v oos-laravel_laravel_vendor:/app/vendor \
  -v oos-laravel_laravel_storage:/app/storage \
  -e APP_KEY=base64:... \
  -w /app oos-laravel-app \
  php /workspace/oos/bin/settings-coverage/dump-settings.php /tmp/settings-rows.json
```

Copy `/tmp/settings-rows.json` out of the container (or mount a writable path).

**Step 2 — classify and write the artefact.**

```sh
python bin/settings-coverage/generate.py --schema .playwright-mcp/settings-rows.json
```

**Step 3 — prove it.**

```sh
# in the app container
vendor/bin/phpunit --no-coverage --filter='AdminSettingsCoverageTest'
```

## The buckets

| Bucket | Meaning |
|---|---|
| `enforced` | A reader exists — an accessor call, a `DEFAULTS` entry, an API-key map entry, a provider-URL map entry, or a reader under the key's storage alias |
| `reported` | Read only by the security posture report: graded, not enforced |
| `inapplicable` | Declared in `InapplicableSettings` — configures absent software |
| `inert` | Declared in `UnimplementedSettings` — real upstream, not built here |
| `unenforced` | A security control no enforcement point reads, and `/admin` can write it |
| `unenforced-operator` | The same, at operator scope — writable from `/ops` |
| `wordpress` | Read by WordPress PHP, which uses `wp_options`; this admin writes `nvoos_tenant_settings`, so the control is inert here |
| `wordpress-dynamic` | The same, for keys the plugin builds by interpolation (`$slug . '_safety_buffer'`) |
| `stale` | Named by no source in the repository |
| `unclassified` | Named somewhere, but no reader found — suspect, not dead |

The last two are deliberately distinct. `stale` means no source mentions the key
at all. `unclassified` means the key appears, but no reader was found — and a
reader that resolves a key through a variable or a map entry the scan does not
model leaves no literal trace, so absence of evidence is not proof of death.

## What the scan models

Four read idioms, because a reader does not always look like a call:

| Idiom | Example |
|---|---|
| Accessor call | `$settings->value( 'memory_dormancy_days' )` |
| Map value | `'ollama' => 'ollama_endpoint_url'` inside `getApiBaseUrl()` |
| Array index | `$stored['enable_pii_filter']` |
| Resolver argument | `self::flag( $stored, $config, 'enable_pii_filter', 'pii_filter' )` |

A fifth case is handled by derivation rather than matching: the plugin builds
twelve commerce keys as `$slug . '_safety_buffer'`, so the literal never appears.
The scan collects the suffixes the plugin actually interpolates and classifies
those keys `wordpress-dynamic`. Without it they read as "defined by no source",
which is a false statement about code that plainly exists.

## What the scan cannot see

It matches literal key names and the four idioms above. It would still miss a
reader that assembles a key at runtime in a way not modelled
(`"chat_".$suffix`), or one that reads a whole payload and indexes it with a
variable. That limit is why `unclassified` exists as a verdict rather than being
folded into `stale`, and why the page describes it as "no reader found" instead
of "dead".

Two of these limits have already produced wrong rows once each: the array-index
idiom was unmodelled (two wired security keys still read as WordPress settings),
and the residue scan counted the coverage map itself as evidence (so no key could
ever be `stale`). Both are fixed; the pattern is worth remembering when a bucket
looks suspiciously tidy.
