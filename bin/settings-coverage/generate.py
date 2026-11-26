#!/usr/bin/env python3
"""Generate resources/settings/coverage.json — which runtime reads each key.

Step 2 of the settings-coverage sweep. Step 1 is `dump-settings.php`, which
produces the schema rows; this script turns them into the bucket map the
`/admin/settings-coverage` page renders, and the AdminSettingsCoverageTest pins.

    python bin/settings-coverage/generate.py --schema .playwright-mcp/settings-rows.json

Every bucket is a claim about source code, so the script reads the code rather
than trusting a list:

  enforced             a reader exists (accessor call, DEFAULTS map, API-key map,
                       provider-URL map) — under the key or its storage alias
  reported             read only by the security posture report (graded, not enforced)
  inapplicable         declared in InapplicableSettings
  inert                declared in UnimplementedSettings
  unenforced           operator-or-tenant security control no enforcement point reads;
                       split by whether the admin surface can write it
  wordpress            read by WordPress PHP (a different store: wp_options)
  stale                named by no source in the repository at all
  unclassified         named somewhere, but no reader found — suspect, not dead

WHY THE SPLIT MATTERS

A bucket that says "dead" when it means "I could not find a reader" is the same
class of error the sweep exists to remove. `unclassified` is therefore a
separate, honest verdict: a reader that resolves a key through a variable or a
map entry the scan does not model would leave no literal trace.
"""
import argparse
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))

APP = "docker/laravel/app-skeleton"
LARAVEL_DIRS = [f"{APP}/app", "lib/core/src", "lib/laravel-adapter/src"]
WP_DIRS = ["includes", "addons/pro/includes"]
ENFORCE_DIRS = [
    f"{APP}/app",
    f"{APP}/config",
    f"{APP}/routes",
    f"{APP}/database",
    f"{APP}/resources/views",
    "lib/laravel-adapter/src",
]

# Declarations name keys; they are not readers.
DECLARATION_FILES = {
    "SettingsSchema.php",
    "SettingsCatalogue.php",
    "SettingsCoverage.php",
    "InapplicableSettings.php",
    "UnimplementedSettings.php",
}
# The posture scorer describes a key for a WordPress-scoped signal.
SCORING_ONLY = ("SecuritySignalCatalogue.php",)

# Read paths in the adapter, where a key is never written in a call.
CALL_RE = re.compile(r"->\s*(?:value|get)\s*\(\s*'([a-z0-9_]+)'")
DEFAULTS_RE = re.compile(r"private const DEFAULTS\s*=\s*array\((.*?)\);", re.S)
APIKEY_RE = re.compile(r"=>\s*'([a-z0-9_]+_api_key)'")
URLMAP_RE = re.compile(r"\$(urlMap|legacyMap)\s*=\s*array\((.*?)\);", re.S)
URL_KEY_RE = re.compile(r"'([a-z0-9_]+_(?:base_url|endpoint_url))'")
MAP_KEY_RE = re.compile(r"'([a-z0-9_]+)'\s*=>")

# A key read out of the settings array rather than through an accessor:
# `$stored['enable_pii_filter']` / `array_key_exists( 'enable_pii_filter', $stored )`.
# The guard in NvoosServiceProvider reads this way so it can tell "unset" from
# "set to false"; without this idiom in the scan those keys look unread and get
# filed as WordPress settings, which is exactly backwards.
SETTINGS_INDEX_RE = re.compile(r"\$(?:stored|settings|values)\[\s*'([a-z0-9_]+)'\s*\]")
KEY_EXISTS_RE = re.compile(r"array_key_exists\(\s*'([a-z0-9_]+)'")

# A key passed to a small resolver helper rather than read inline:
# `self::flag( $stored, $config, 'enable_pii_filter', 'pii_filter' )`.
FLAG_CALL_RE = re.compile(r"self::flag\(\s*\$[\w]+,\s*\$[\w]+,\s*'([a-z0-9_]+)'")

# Keys the plugin builds rather than writes: `$slug . '_safety_buffer'`. A scan
# for literal key names can never see these, so the suffixes are collected from
# the interpolation itself and matched against a key's tail. Without this, twelve
# WordPress commerce settings read as "defined by no source" — a false claim.
DYNAMIC_SUFFIX_RE = re.compile(r"\$\w+\s*\.\s*'([^']+)'")

SECURITY_CANDIDATES = """require_authentication_all allow_guest_access minimum_capability
ip_whitelist require_https rate_limit_by enable_security_audit_log log_successful_auth
log_file_access audit_log_retention_days enable_security_headers enable_hsts hsts_max_age
csp_frame_ancestors cors_allow_origin cors_allowed_origins mcp_app_allowed_hosts
enable_root_security_key root_security_key enable_2fa_requirement
enable_loopback_ssl_bypass enable_loopback_private_network_requests
enable_prompt_injection_detector prompt_injection_sensitivity prompt_injection_mode
enable_pii_filter max_sse_connections_per_user max_request_body_size_kb max_json_depth
enable_auth_rate_limiting auth_rate_limit_threshold auth_rate_limit_window
enable_oauth_token_rate_limit api_error_verbosity strip_asset_versions
mesh_inbound_api_key""".split()

# The posture reporter reads these to grade them. Grading is not enforcing.
REPORTED_ONLY = {
    "minimum_capability", "require_https", "rate_limit_by", "hsts_max_age",
    "csp_frame_ancestors", "require_authentication_all", "ip_whitelist",
}
# Genuinely enforced despite living in the security cluster.
REALLY_ENFORCED = {
    "prompt_injection_mode", "prompt_injection_sensitivity", "mesh_inbound_api_key",
}

REASONS = {
    "enforced": "Read by runtime code that changes behaviour.",
    "reported": (
        "Graded by the security posture report, not enforced: the admin saves the value "
        "and /admin/security scores it, but no middleware applies it."
    ),
    "inapplicable": (
        "Declared in InapplicableSettings: configures a WordPress plugin, endpoint or "
        "hosting model with no counterpart here, so it is not rendered."
    ),
    "inert": (
        "Declared in UnimplementedSettings: the capability is real upstream and not built "
        "here, so the control renders disabled and the writer refuses it."
    ),
    "unenforced": (
        "A security control that is rendered and writable but referenced by no enforcement "
        "point: no middleware, policy or service reads it. Changing it records an intention "
        "and changes no behaviour."
    ),
    "unenforced-operator": (
        "The same problem as unenforced, one scope up: an operator-scope security control "
        "that no enforcement point reads. It is writable from /ops, not from this panel, so "
        "the risk lands on whoever administers the deployment."
    ),
    "wordpress": (
        "A WordPress setting. The plugin reads it from wp_options; this admin writes "
        "nvoos_tenant_settings, which the plugin never reads, so changing it here has no "
        "effect on either runtime."
    ),
    "stale": (
        "In the generated catalogue but defined by no source: the generator emitted it from "
        "a field since removed upstream. Regenerating the catalogue drops it."
    ),
    "unclassified": (
        "No reader found by the sweep. Not proven dead: the sweep matches literal key names, "
        "so a reader resolving the key through a map or a variable would not be seen. "
        "Treat as suspect, not dead."
    ),
    "wordpress-dynamic": (
        "A WordPress setting the plugin builds by interpolation (`$slug . '_safety_buffer'`), "
        "so the literal key never appears in the plugin source. Same store boundary as the "
        "other WordPress settings: it configures the plugin, which reads wp_options."
    ),
}


def php_files(dirs):
    for d in dirs:
        base = os.path.join(ROOT, d)
        if not os.path.isdir(base):
            continue
        for dirpath, _dn, fns in os.walk(base):
            for fn in fns:
                if fn.endswith(".php"):
                    yield os.path.join(dirpath, fn)


def read(path):
    try:
        return open(path, encoding="utf-8", errors="ignore").read()
    except OSError:
        return ""


def laravel_readers():
    calls, defaults, apikeys, urls, indexed = {}, set(), set(), set(), set()

    for path in php_files(LARAVEL_DIRS):
        text = read(path)
        rel = os.path.relpath(path, ROOT)

        for m in CALL_RE.finditer(text):
            calls.setdefault(m.group(1), set()).add(rel)

        for blk in DEFAULTS_RE.finditer(text):
            defaults.update(m.group(1) for m in MAP_KEY_RE.finditer(blk.group(1)))

        apikeys.update(m.group(1) for m in APIKEY_RE.finditer(text))
        for blk in URLMAP_RE.finditer(text):
            urls.update(m.group(1) for m in URL_KEY_RE.finditer(blk.group(2)))

        indexed.update(m.group(1) for m in SETTINGS_INDEX_RE.finditer(text))
        indexed.update(m.group(1) for m in KEY_EXISTS_RE.finditer(text))
        indexed.update(m.group(1) for m in FLAG_CALL_RE.finditer(text))

    # A `get()` on a random object is not a settings read.
    hint = re.compile(r"SettingsStore|SettingsService|settings|Settings", re.I)
    real = {k: v for k, v in calls.items()
            if any(hint.search(read(os.path.join(ROOT, p))) for p in v)}

    return set(real) | defaults | apikeys | urls | indexed


def keys_in(dirs, wanted, skip_names=()):
    """One pass over a tree; returns the subset of `wanted` found as a literal."""
    remaining = set(wanted)
    found = set()
    for path in php_files(dirs):
        if os.path.basename(path) in skip_names:
            continue
        if not remaining:
            break
        text = read(path)
        for key in list(remaining):
            if key in text:
                found.add(key)
                remaining.discard(key)
    return found


def dynamic_suffixes():
    """Suffixes the WordPress plugin appends to a slug to build a setting key."""
    suffixes = set()
    for path in php_files(WP_DIRS):
        for m in DYNAMIC_SUFFIX_RE.finditer(read(path)):
            suffix = m.group(1)
            if suffix.startswith("_") and len(suffix) > 1:
                suffixes.add(suffix)
    return suffixes


def built_by_wordpress(key, suffixes):
    """Is this key a `$slug . suffix` construction in the plugin?"""
    return any(key.endswith(s) and len(key) > len(s) for s in suffixes)


def security_unenforced(rows):
    """Security candidates with no enforcement-zone reference."""
    enforced, unenforced = set(), set()
    zone_text = ""

    for path in php_files(ENFORCE_DIRS):
        name = os.path.basename(path)
        if name in DECLARATION_FILES or name in SCORING_ONLY:
            continue
        zone_text += read(path)

    for key in SECURITY_CANDIDATES:
        if not any(r["key"] == key for r in rows):
            continue
        (enforced if key in zone_text else unenforced).add(key)

    return enforced, unenforced


def declared(path):
    text = read(path)
    m = re.search(r"public const REASONS\s*=\s*array\((.*?)\n    \);", text, re.S)
    if not m:
        return {}
    out = {}
    for line in m.group(1).splitlines():
        km = re.match(r"\s*'([a-z0-9_]+)'\s*=>\s*'([^']*)'", line)
        if km:
            out[km.group(1)] = km.group(2)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--schema", required=True, help="output of dump-settings.php")
    ap.add_argument("--out", default=os.path.join(
        ROOT, APP, "resources", "settings", "coverage.json"))
    args = ap.parse_args()

    rows = json.load(open(args.schema, encoding="utf-8"))
    storage_of = {r["key"]: (r.get("storage") or r["key"]) for r in rows}
    writable_admin = {r["key"]: bool(r.get("writable_admin")) for r in rows}

    settings_dir = os.path.join(ROOT, APP, "app", "Support", "Settings")
    inapplicable = declared(os.path.join(settings_dir, "InapplicableSettings.php"))
    inert = declared(os.path.join(settings_dir, "UnimplementedSettings.php"))

    readers = laravel_readers()
    _, unenforced_security = security_unenforced(rows)

    # WordPress presence, for everything not already enforced here.
    candidates = [r["key"] for r in rows
                  if r["key"] not in readers and storage_of[r["key"]] not in readers]
    wp = keys_in(WP_DIRS, candidates)

    # Repo-wide presence, only for the residue — the expensive scan, kept small.
    # The settings artefact and the catalogue are skipped: they contain every key,
    # so including them would make every residual key look referenced.
    residue = [k for k in candidates if k not in wp]
    anywhere = keys_in(
        [d for d in os.listdir(ROOT)
         if os.path.isdir(os.path.join(ROOT, d))
         and d not in (".git", "vendor", "node_modules", ".playwright-mcp")
         and not d.startswith(".wt-")],
        residue,
        skip_names=("coverage.json", "catalogue.json"),
    )

    suffixes = dynamic_suffixes()

    out = []
    for r in rows:
        key = r["key"]
        storage = storage_of[key]

        if key in REALLY_ENFORCED:
            bucket = "enforced"
        elif key in REPORTED_ONLY:
            bucket = "reported"
        elif key in inapplicable:
            bucket = "inapplicable"
        elif key in inert:
            bucket = "inert"
        elif key in unenforced_security:
            bucket = "unenforced" if writable_admin.get(key) else "unenforced-operator"
        elif key in readers or storage in readers:
            bucket = "enforced"
        elif key in wp:
            bucket = "wordpress"
        elif built_by_wordpress(key, suffixes):
            bucket = "wordpress-dynamic"
        elif key not in anywhere:
            bucket = "stale"
        else:
            bucket = "unclassified"

        reason = inapplicable.get(key) or inert.get(key) or REASONS[bucket]
        if bucket == "enforced" and storage != key and key not in REALLY_ENFORCED:
            reason = (f"Read by the runtime under its storage key `{storage}`; the surface "
                      "name is the one the writer remaps.")

        out.append({"key": key, "bucket": bucket, "reason": reason, "storage": storage})

    doc = {
        "_comment": (
            "Generated by bin/settings-coverage (Proposal 149 §183). Each row states which "
            "runtime reads the key, from source evidence, so /admin/settings-coverage can "
            "show an operator which controls do something. Regenerate rather than "
            "hand-edit: the buckets are claims about code and drift the moment it moves."
        ),
        "schema_version": 1,
        "buckets": {b: REASONS[b] for b in REASONS},
        "fields": out,
    }

    with open(args.out, "w", encoding="utf-8", newline="\n") as fh:
        json.dump(doc, fh, indent=2, ensure_ascii=False)
        fh.write("\n")

    counts = {}
    for r in out:
        counts[r["bucket"]] = counts.get(r["bucket"], 0) + 1

    print(f"wrote {args.out}")
    print(f"rows: {len(out)}")
    for b in sorted(counts):
        print(f"  {b}: {counts[b]}")

    if not counts.get("enforced"):
        print("ERROR: nothing is enforced — the reader scan found nothing.", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
