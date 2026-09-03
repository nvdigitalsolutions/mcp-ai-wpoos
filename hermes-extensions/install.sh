#!/usr/bin/env bash
# Install the NV oOS Fleet dashboard extension into a Hermes installation.
#
# Usage:
#   HERMES_HOME=/custom/path bash install.sh
#
# Copies:
#   nv-oos-fleet/            -> $HERMES_HOME/plugins/nv-oos-fleet/
#   nv-oos-fleet/theme/nv-oos.yaml
#                            -> $HERMES_HOME/dashboard-themes/nv-oos.yaml
#
# After installing:
#   - UI plugins are picked up after a dashboard reload (or
#     curl http://127.0.0.1:9119/api/dashboard/plugins/rescan).
#   - Backend routes (plugin_api.py) mount at startup — restart
#     `hermes dashboard` to activate /api/plugins/nv-oos-fleet/*.
set -euo pipefail

HERMES_HOME="${HERMES_HOME:-$HOME/.hermes}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$SCRIPT_DIR/nv-oos-fleet"
PLUGIN_DST="$HERMES_HOME/plugins/nv-oos-fleet"
THEME_SRC="$PLUGIN_SRC/theme/nv-oos.yaml"
THEME_DST="$HERMES_HOME/dashboard-themes/nv-oos.yaml"

if [ ! -f "$PLUGIN_SRC/dashboard/manifest.json" ]; then
  echo "error: manifest.json not found under $PLUGIN_SRC" >&2
  exit 1
fi

mkdir -p "$HERMES_HOME/plugins" "$HERMES_HOME/dashboard-themes"

# Preserve an existing registry (and its 0600 perms) across reinstalls.
REGISTRY_BAK=""
if [ -f "$PLUGIN_DST/dashboard/sites.yaml" ]; then
  REGISTRY_BAK="$(mktemp)"
  cp "$PLUGIN_DST/dashboard/sites.yaml" "$REGISTRY_BAK"
fi

rm -rf "$PLUGIN_DST"
cp -R "$PLUGIN_SRC" "$PLUGIN_DST"
cp "$THEME_SRC" "$THEME_DST"

# Never leave a stale example where the backend would read it.
rm -f "$PLUGIN_DST/dashboard/sites.yaml"

if [ -n "$REGISTRY_BAK" ]; then
  mv "$REGISTRY_BAK" "$PLUGIN_DST/dashboard/sites.yaml"
  chmod 600 "$PLUGIN_DST/dashboard/sites.yaml" 2>/dev/null || true
fi

echo "Installed:"
echo "  plugin: $PLUGIN_DST"
echo "  theme:  $THEME_DST"
echo
echo "Next steps:"
echo "  1. Restart: hermes dashboard"
echo "  2. Verify:  curl http://127.0.0.1:9119/api/plugins/nv-oos-fleet/meta"
echo "  3. Open the dashboard, pick the NV oOS theme, then add sites under the"
echo "     NV oOS Fleet tab."
