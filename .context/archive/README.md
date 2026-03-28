# Archived Feature Contexts

This directory stores completed feature context files following Phase 9 (Retrospective).

## Purpose

Context files are **archived, not deleted** so that future development sessions can
reference decisions made in prior development cycles.

## File Naming Convention

```
[feature-slug]-vX.Y.Z.md
```

Examples:
- `manage-redirects-v1.3.0.md`
- `shopify-catalog-v2-v1.4.0.md`

## When to Archive

During Phase 9 (Retrospective), the Scrum Master moves the active context file here:

```bash
# Move from active to archive:
mv .context/active/[feature-slug].md .context/archive/[feature-slug]-vX.Y.Z.md
```

## Using Archived Contexts

When starting work on a feature that was previously implemented (e.g., a v2), load
the archived context to understand decisions made in the first cycle before
creating a new active context file.
