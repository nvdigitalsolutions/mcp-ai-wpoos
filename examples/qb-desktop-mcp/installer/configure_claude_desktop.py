"""
Run once, after install: registers QBBridge as an MCP server in Claude
Desktop's config file, so the user never has to hand-edit JSON.

Safety choices, deliberately:
- Never overwrite an unparseable existing config -- back it up and tell the
  user, rather than silently clobbering settings for their other MCP
  servers because of one malformed file.
- Merge into the existing "mcpServers" object; never replace it wholesale.
  A user may already have other MCP servers configured.
- Idempotent: running this twice (e.g. a reinstall/repair) just re-writes
  the same "qbbridge" entry, it doesn't duplicate or corrupt anything else.
"""

import json
import os
import shutil
import sys
from pathlib import Path


def find_config_path():
    appdata = os.environ.get("APPDATA")
    if not appdata:
        raise RuntimeError("Could not find %APPDATA% -- are you on Windows?")
    return Path(appdata) / "Claude" / "claude_desktop_config.json"


def load_or_init(config_path):
    if not config_path.exists():
        return {"mcpServers": {}}

    try:
        with open(config_path, encoding="utf-8") as f:
            data = json.load(f)
    except (json.JSONDecodeError, OSError) as e:
        backup_path = config_path.with_suffix(".json.bak")
        shutil.copy2(config_path, backup_path)
        print("WARNING: existing config at", config_path, "could not be parsed (" + str(e) + ").")
        print("Backed it up to", backup_path, "and starting a fresh config.")
        print("Any other MCP servers you had configured will need to be re-added by hand from the backup.")
        return {"mcpServers": {}}

    if "mcpServers" not in data or not isinstance(data.get("mcpServers"), dict):
        data["mcpServers"] = {}
    return data


def main():
    exe_path = sys.argv[1] if len(sys.argv) > 1 else str(Path(__file__).parent / "qbbridge-mcp.exe")
    exe_path = str(Path(exe_path).resolve())

    config_path = find_config_path()
    config_path.parent.mkdir(parents=True, exist_ok=True)

    data = load_or_init(config_path)
    data["mcpServers"]["qbbridge"] = {"command": exe_path}

    with open(config_path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)

    print("QBBridge registered with Claude Desktop.")
    print("Config file:", config_path)
    print()
    print("Next steps:")
    print("  1. Restart Claude Desktop if it's currently running (it only")
    print("     reads this config file at startup).")
    print("  2. Make sure QuickBooks Desktop is open on the company file")
    print("     you want to work with.")
    print("  3. The first time Claude actually uses a QBBridge tool,")
    print("     QuickBooks will show a one-time permission popup asking")
    print("     whether to allow this connection -- click Yes/Allow. If you")
    print("     miss it or click no by mistake, QuickBooks Desktop has a")
    print("     preference (Edit > Preferences > Integrated Applications)")
    print("     where you can find and re-allow it.")


if __name__ == "__main__":
    main()
