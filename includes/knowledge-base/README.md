# Knowledge Base (profession RAG data)

Non-PHP data files that seed the profession knowledge base consumed by
site-side AI assistants through retrieval (RAG): base knowledge documents
(`profession-documents/`), structured playbooks
(`profession-playbooks/`), profession metadata (`professions/`), and team
definitions (`teams/`).

## Fact freshness contract (sync rule)

The `wpoos_developer.txt` files and the `technology.json` entry carry product
facts that go stale — product name, plugin version, provider list, and tool
count. The rules:

1. Facts are version-tagged: each file carries a "Spec current as of vX.Y.Z"
   line. On every release, refresh the facts and the stamp together.
2. The model catalog is the single source of truth for model IDs; KB text
   points at the catalog instead of pinning model lists.
3. Historical changelog entries inside these files are never rewritten;
   refresh entries are appended above them.
4. CI enforces the contract via `tests/test-knowledge-base-facts-sync.php` —
   a version bump without a KB refresh fails the suite.
5. Edit the canonical files here, then copy them byte-identical to
   `plugins/nvoos-content-graph-ai-platform/data/knowledge-base/`.
6. The docs catch-up track (`.agents/skills/mcp-ai-wpoos-updates`) lists the
   KB refresh as a release step.

## Neighbors

- `docs/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md` — the full system guide.
- `includes/class-wp-mcp-ai-profession-*.php` — the profession CPT/loader.
- Load alongside: `.context/tool-registry.md`, `.context/conventions.md`.
