# Milestone Strategy

**Repository:** nvdigitalsolutions/mcp-ai-wpoos  
**Version:** 1.0  
**Last Updated:** December 24, 2025

---

## Overview

This document defines the milestone management strategy for the WP oOS plugin. Milestones represent specific releases and help track progress toward version goals.

## Milestone Types

### 1. Patch Releases (v1.0.x)

**Purpose:** Bug fixes, security patches, minor documentation updates

**Criteria:**
- ✅ Bug fixes that don't change behavior
- ✅ Security vulnerability patches
- ✅ Translation updates
- ✅ Documentation corrections
- ✅ Performance optimizations (safe)
- ❌ New features
- ❌ Breaking changes
- ❌ Major refactoring

**Frequency:** As needed (typically 1-2 per month)

**Examples:**
- Fix chat UI loading bug
- Patch security vulnerability
- Update translations
- Fix PHPCS violations

---

### 2. Minor Releases (v1.x.0)

**Purpose:** New features, enhancements, backward-compatible changes

**Criteria:**
- ✅ New features and tools
- ✅ Enhancements to existing features
- ✅ New AI provider integrations
- ✅ API additions (backward-compatible)
- ✅ Major documentation additions
- ✅ Safe refactoring
- ❌ Breaking changes
- ❌ Database schema changes

**Frequency:** Every 6-8 weeks

**Examples:**
- Add new project management tools
- Integrate new AI provider
- Add notification system
- Implement task dependencies

---

### 3. Major Releases (vX.0.0)

**Purpose:** Breaking changes, major architectural changes, database migrations

**Criteria:**
- ✅ Breaking API changes
- ✅ Database schema migrations
- ✅ Major architectural refactoring
- ✅ PHP/WordPress version requirement changes
- ✅ Removal of deprecated features
- ✅ Major feature additions

**Frequency:** Every 6-12 months

**Examples:**
- Change tool interface (breaking)
- Migrate from CPT to CCT
- Require PHP 8.0+
- Major settings restructure

---

### 4. Special Milestones

#### Backlog
**Purpose:** Approved features without scheduled release

**Criteria:**
- Feature accepted but not prioritized
- Good ideas for future consideration
- Dependent on external factors
- Needs more discussion

**No due date**

#### Future
**Purpose:** Ideas under consideration, not committed

**Criteria:**
- Interesting ideas needing evaluation
- Feature requests requiring research
- Breaking changes for next major
- Long-term architectural changes

**No due date**

---

## Milestone Lifecycle

### Creation

**When to Create:**
- Start of planning for next release
- When previous milestone reaches 80% completion

**How to Create:**
1. Go to Issues → Milestones → New Milestone
2. Name: `v1.x.x` (semantic versioning)
3. Due Date: Target release date
4. Description: Brief release theme/focus
5. Save milestone

**Example:**
```
Title: v1.1.0
Due Date: February 28, 2026
Description: Enhanced project management features including task dependencies, 
notifications, and improved calendar views. Focus on team collaboration.
```

---

### Issue Assignment

**Assignment Rules:**

| Issue Type | Priority | Milestone |
|------------|----------|-----------|
| Critical bug | Any | Next patch |
| High priority bug | High | Next patch/minor |
| Security issue | Any | Next patch (urgent) |
| New feature | High | Next minor |
| Enhancement | Medium | Next minor |
| Documentation | Any | Appropriate release |
| Refactoring | Any | Next minor |
| Breaking change | Any | Next major |
| Nice-to-have | Low | Backlog |

**Assignment Process:**
1. During triage, evaluate issue priority
2. Consider release impact and effort
3. Assign to appropriate milestone
4. Can be reassigned during sprint planning

---

### Milestone Tracking

**Weekly Review:**
- Check milestone progress (% complete)
- Review blocked issues
- Reassign low-priority issues if needed
- Update milestone due date if slipping

**Metrics to Track:**
- Open vs Closed issues
- Estimated completion date
- Burndown chart (if using projects)
- Issues added vs removed

**Warning Signs:**
- < 70% complete with 2 weeks remaining
- Many blocked issues
- Scope creep (too many additions)
- Critical issues still open

---

### Milestone Completion

**Pre-Release Checklist:**

Before closing milestone:
- [ ] All assigned issues are closed
- [ ] No open critical/high bugs
- [ ] CHANGELOG.md updated
- [ ] Version numbers bumped
- [ ] All tests passing
- [ ] Documentation updated
- [ ] Release notes drafted

**Actions:**
1. Move incomplete issues to next milestone or Backlog
2. Document reason for moved issues
3. Close milestone
4. Create GitHub release
5. Announce release

---

## Active Milestones

### Current Milestones (December 2025)

#### v1.0.1 (Patch)
**Due Date:** January 15, 2026  
**Focus:** Stability and bug fixes

**Planned Issues:**
- [ ] Fix chat UI loading issues
- [ ] Resolve PM tool validation bugs
- [ ] Update documentation corrections
- [ ] Security patches (if any)

**Target:** 10-15 issues

---

#### v1.1.0 (Minor)
**Due Date:** February 28, 2026  
**Focus:** Enhanced project management and testing

**Planned Features:**
- [ ] Task dependencies and subtasks (#TBD)
- [ ] Notification system for PM (#TBD)
- [ ] Comprehensive PM test coverage (#TBD)
- [ ] REST API documentation improvements (#TBD)
- [ ] GitHub Projects integration docs (#TBD)

**Target:** 20-30 issues

---

#### v2.0.0 (Major)
**Due Date:** Q3 2026 (September 30, 2026)  
**Focus:** Enterprise features and architecture improvements

**Planned Features:**
- [ ] Advanced workflow automation
- [ ] Team collaboration features
- [ ] Custom role-based permissions
- [ ] Advanced analytics dashboard
- [ ] Time tracking and reporting
- [ ] Gantt chart visualization
- [ ] Breaking: Refactor tool interface

**Target:** 50-70 issues

---

#### Backlog
**No Due Date**

**Contents:**
- Approved feature requests not yet scheduled
- Nice-to-have enhancements
- Ideas requiring more research
- Features dependent on external factors

**Review:** Monthly during planning

---

## Milestone Naming Convention

### Version Numbers

Follow [Semantic Versioning 2.0.0](https://semver.org/):

```
MAJOR.MINOR.PATCH

Examples:
- v1.0.0 - Initial release
- v1.0.1 - First patch
- v1.1.0 - First minor with new features
- v2.0.0 - First major with breaking changes
```

### Pre-Release Versions

For beta/RC releases:

```
v1.1.0-beta.1    - First beta of 1.1.0
v1.1.0-rc.1      - First release candidate of 1.1.0
v2.0.0-alpha.1   - First alpha of 2.0.0
```

**Milestone Creation:**
- Create separate milestone for each pre-release
- Example: `v1.1.0-beta.1` milestone before `v1.1.0`

---

## Release Cadence

### Regular Schedule

| Release Type | Frequency | Day of Week |
|--------------|-----------|-------------|
| Patch | As needed | Tuesday preferred |
| Minor | Every 6-8 weeks | Last Thursday of month |
| Major | Every 6-12 months | First Thursday of quarter |

### Exception Windows

**No Releases During:**
- December 20 - January 5 (Holiday freeze)
- Week of major WordPress core releases
- Week before major holidays

**Reason:** Reduced team availability, user adoption challenges

---

## Milestone Reports

### Weekly Milestone Report

Generate and share with team:

**Report Contents:**
```markdown
# Milestone Progress: v1.1.0

**Due Date:** February 28, 2026  
**Status:** On Track / At Risk / Delayed

## Summary
- Total Issues: 25
- Closed: 15 (60%)
- Open: 10 (40%)
- Blocked: 2

## Progress This Week
- Closed: 5 issues
- Added: 2 issues
- Net Progress: +3 issues

## Blockers
1. Issue #123 - Waiting on external library
2. Issue #145 - Needs design approval

## At Risk
- Issue #156 - Behind schedule

## Action Items
- [ ] Resolve blocker for #123
- [ ] Review design for #145
- [ ] Assign additional developer to #156

## Forecast
- Estimated completion: Feb 21, 2026 (1 week early)
```

### Generation Script

```bash
#!/bin/bash
# Generate milestone report

MILESTONE="v1.1.0"

echo "# Milestone Progress: $MILESTONE"
echo ""

# Get total, open, closed counts
gh api graphql -f query='
  query($owner: String!, $repo: String!, $number: Int!) {
    repository(owner: $owner, name: $repo) {
      milestone(number: $number) {
        title
        dueOn
        issues(first: 100) {
          totalCount
        }
        closedIssues: issues(first: 100, states: CLOSED) {
          totalCount
        }
      }
    }
  }
' -f owner='nvdigitalsolutions' -f repo='mcp-ai-wpoos' -F number=1

# List open issues
echo "## Open Issues"
gh issue list --milestone "$MILESTONE" --state open
```

---

## Best Practices

### DO ✅

- Create milestones 2-3 sprints in advance
- Set realistic due dates
- Review milestone progress weekly
- Move scope creep to backlog
- Close milestones promptly after release
- Document reasons for moved issues
- Celebrate milestone completions

### DON'T ❌

- Don't overload milestones
- Don't change milestone type (patch → minor)
- Don't extend due dates repeatedly
- Don't assign to milestone without triage
- Don't close milestone with open issues
- Don't create too many milestones (max 3-4 active)

---

## Milestone FAQ

**Q: When should I assign an issue to a milestone?**  
A: During triage, once priority and effort are known. If uncertain, use Backlog.

**Q: Can I move issues between milestones?**  
A: Yes, during sprint planning or if priorities change. Document the reason.

**Q: What if a milestone is behind schedule?**  
A: Review issues, move non-critical items to next milestone, or extend due date once.

**Q: Should bugs always go in next patch?**  
A: Critical/high bugs yes, medium/low bugs can wait for minor if not urgent.

**Q: How many issues per milestone?**  
A: Depends on team size. Typical: 10-15 for patch, 20-30 for minor, 50-70 for major.

**Q: When do we create the next milestone?**  
A: When current milestone is 80% complete or 2-3 weeks before release.

---

## Integration with Projects

Milestones are used alongside GitHub Projects:

**Roadmap Project:**
- Shows long-term vision across multiple milestones
- Groups features by theme, not by milestone

**Sprint Board:**
- Shows current sprint work (2 weeks)
- May span multiple milestones
- Focuses on what's active right now

**Bug Triage:**
- Triaged bugs get assigned to milestones
- Critical bugs → Next patch milestone
- Other bugs → Appropriate milestone

---

## Automation

### GitHub Actions Integration

```yaml
# .github/workflows/milestone-automation.yml
name: Milestone Automation

on:
  issues:
    types: [closed, milestoned]
  pull_request:
    types: [closed]

jobs:
  update-milestone:
    runs-on: ubuntu-latest
    steps:
      - name: Check if milestone is complete
        run: |
          # Check if all issues in milestone are closed
          # If yes, notify team that milestone is ready for release
```

### Milestone Notifications

Set up notifications for:
- Milestone 80% complete → Alert: Start next milestone
- Milestone due in 1 week → Alert: Review progress
- Milestone overdue → Alert: Review and extend or close
- Milestone closed → Celebrate: Release notes published

---

## Change Log

| Date | Version | Changes |
|------|---------|---------|
| 2025-12-24 | 1.0 | Initial milestone strategy document |

---

## See Also

- [PROJECT_MANAGEMENT_GAP_ANALYSIS.md](PROJECT_MANAGEMENT_GAP_ANALYSIS.md) - Complete PM gap analysis
- [LABEL_STRATEGY.md](LABEL_STRATEGY.md) - Label management
- [ROADMAP.md](ROADMAP.md) - Product roadmap
- [RELEASE_PROCESS.md](RELEASE_PROCESS.md) - Release management
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Contributing guidelines
