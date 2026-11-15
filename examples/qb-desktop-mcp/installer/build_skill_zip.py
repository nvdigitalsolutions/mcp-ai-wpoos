"""
Builds the upload-ready skill ZIP for Claude Desktop.

Per Claude's Skills documentation ("upload a ZIP file containing your
skill folder"), the zip's top level must be the skill's own folder (here,
"qbbridge-bookkeeping/"), with SKILL.md inside it -- not SKILL.md at the
zip root. Run this before the Inno Setup build so the zip is a [Files]
entry the installer can ship.
"""

import zipfile
from pathlib import Path

SKILLS_DIR = Path(__file__).parent.parent / "skills"
SKILL_NAME = "qbbridge-bookkeeping"
OUTPUT = Path(__file__).parent / f"{SKILL_NAME}.zip"


def main():
    skill_folder = SKILLS_DIR / SKILL_NAME
    if not skill_folder.is_dir():
        raise SystemExit(f"Expected skill folder not found: {skill_folder}")

    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as z:
        for path in skill_folder.rglob("*"):
            if path.is_file():
                arcname = Path(SKILL_NAME) / path.relative_to(skill_folder)
                z.write(path, arcname)

    with zipfile.ZipFile(OUTPUT) as z:
        names = z.namelist()

    print(f"Wrote {OUTPUT} containing: {names}")


if __name__ == "__main__":
    main()
