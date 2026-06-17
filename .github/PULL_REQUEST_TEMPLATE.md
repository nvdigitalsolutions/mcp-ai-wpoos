## Description
<!-- Provide a brief description of the changes in this PR -->

## Type of Change
<!-- Check all that apply -->
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update
- [ ] Code refactoring
- [ ] Performance improvement
- [ ] Security fix

## Related Issues
<!-- Link to related issues using #issue_number -->
Closes #

## GSD × BMAD Phase Reference
<!-- For feature work, indicate which phases were completed -->
- [ ] Phase 0 (Context Init) — `.context/active/[feature].md` initialized
- [ ] Phase 1 (Discovery) — Project Brief: `docs/project/proposals/[FEATURE]-PROJECT-BRIEF.md`
- [ ] Phase 2 (Planning) — PRD: `docs/project/proposals/[FEATURE]-PRD.md`
- [ ] Phase 3 (Architecture) — Arch Spec: `docs/project/proposals/[FEATURE]-ARCHITECTURE.md`
- [ ] Phase 4 (Story Breakdown) — Stories in task plan (see artifact below)
- [ ] Phase 5 (Implementation) — Atomic commits per story
- [ ] Not applicable (patch / bug fix)

## Story Breakdown Artifact
<!-- Medium+ features: paste the create_task_plan output here so reviewers can see the full story list. -->
<!-- Run in your AI assistant: create_task_plan(plan_name="[feature]", goal="[goal]", tasks=[...]) -->
<!-- Then expand the section below and paste the returned markdown. -->
<details>
<summary>Task Plan — <code>create_task_plan</code> output (expand to view)</summary>

```markdown
<!-- Paste create_task_plan markdown output here. Example:

# [Feature Name] Task Plan

## Goal
[Feature goal]

## Tasks
- [ ] Story 1.1: ... (Priority: High)
- [ ] Story 1.2: ... (Priority: Medium)

## Status
Progress: 0%
Created: YYYY-MM-DD HH:MM:SS
-->
```

</details>

## Changes Made
<!-- List the specific changes made in this PR -->
-
-
-

## Testing
<!-- Describe how you tested these changes -->
- [ ] Tested locally
- [ ] Added/updated unit tests
- [ ] All tests pass (`composer run test`)
- [ ] Code follows WordPress coding standards (`composer run lint`)
- [ ] PHP compatibility check passes (`composer run lint:compat`)
- [ ] JavaScript linting passes (`npm run lint:js`) — *if JS files changed*

## Per-Story Gate Checklist
<!-- Based on GSD × BMAD Phase 6 validation — check all that apply -->
- [ ] All acceptance criteria met
- [ ] PHPDoc blocks on all new classes and methods
- [ ] Security review complete:
  - [ ] Input sanitized (`sanitize_text_field()`, `absint()`, etc.)
  - [ ] Output escaped (`esc_html()`, `esc_url()`, etc.)
  - [ ] Capability checked before every privileged operation
  - [ ] Nonce verified for state-changing requests
  - [ ] ABSPATH guard on all new PHP files
- [ ] Documentation updated if needed
- [ ] Backward compatibility verified
- [ ] Base vs Pro version gating correct

## Screenshots
<!-- If applicable, add screenshots to show the changes -->

## Checklist
- [ ] My code follows the project's coding standards
- [ ] I have commented my code, particularly in hard-to-understand areas
- [ ] I have updated the documentation accordingly
- [ ] My changes generate no new warnings or errors
- [ ] I have added tests that prove my fix is effective or that my feature works
- [ ] New and existing unit tests pass locally with my changes
- [ ] Any dependent changes have been merged and published

## Additional Notes
<!-- Add any additional context or notes for reviewers -->
