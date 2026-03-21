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
- [ ] Phase 1 (Discovery) — Project Brief: `docs/proposals/[FEATURE]-PROJECT-BRIEF.md`
- [ ] Phase 2 (Planning) — PRD: `docs/proposals/[FEATURE]-PRD.md`
- [ ] Phase 3 (Architecture) — Arch Spec: `docs/proposals/[FEATURE]-ARCHITECTURE.md`
- [ ] Phase 4 (Story Breakdown) — Stories in task plan
- [ ] Phase 5 (Implementation) — Atomic commits per story
- [ ] Not applicable (patch / bug fix)

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
