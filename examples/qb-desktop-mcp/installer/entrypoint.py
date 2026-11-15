"""
PyInstaller entrypoint. Kept separate from qbbridge_mcp/server.py's own
`if __name__ == "__main__"` block because PyInstaller needs a real script
file to point at, not a console-script/entry-point reference -- this is
the thinnest possible wrapper around the same `main()` the pip package
exposes, so there is exactly one code path to keep correct.
"""

from qbbridge_mcp.server import main

if __name__ == "__main__":
    main()
