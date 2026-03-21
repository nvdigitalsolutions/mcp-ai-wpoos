# Active Feature Contexts

This directory contains active feature context files for ongoing development work.

## Purpose

Following the GSD principle of context engineering, each active feature gets its own
context file that is loaded at the start of every AI development session for that feature.

## Lifecycle

1. **Created (Phase 0):** Scrum Master initializes `[feature-name].md` from the Project Brief summary
2. **Updated:** Developer/QA update the file after each session with decisions and discoveries
3. **Archived (Phase 9):** Retrospective moves file to `.context/archive/[feature-name]-vX.Y.Z.md`

## File Naming Convention

```
[feature-slug].md
```

Examples:
- `manage-redirects.md`
- `shopify-catalog-v2.md`
- `dicom-imaging-toolkit.md`

## Template

Use `.context/templates/active-feature-template.md` as the starting point.
Keep each file under **500 lines** (GSD conciseness rule).

## Contents

Each active context file should include:
- Feature overview (2-3 sentences)
- Architecture Reference (link to Architecture Spec doc)
- Current Phase and next step
- Known issues / gotchas discovered during development
- Architectural decisions made (not in the spec)
- Context window loading strategy for this feature
