# IGCSE Teams Reseed Procedure

## Problem Summary

After initial plugin setup or team resync, IGCSE teams may show "No members" in WordPress admin because:

1. **Teams require profession posts to exist first** - Team member slugs (like `igcse_mathematics_tutor`) need to be converted to profession post IDs
2. **If professions aren't seeded**, the team save process can't find the profession posts and saves teams with empty member arrays
3. **Team validation requires minimum 2 members** - Teams with only 1 member will fail validation and not load

## Solution: Proper Reseed Order

### Step 1: Reseed Professions FIRST

Navigate to: **WP Admin → Settings → WP oOS → Advanced → Data Management**

1. Scroll to **"Reload Profession Data"** section
2. Click **"Update Professions"** button (or "Replace All Professions" for clean slate)
3. Wait for success message confirming professions were created/updated
4. Verify: Go to **WP Admin → Professions** and check that IGCSE professions exist:
   - igcse_mathematics_tutor
   - igcse_biology_tutor
   - igcse_chemistry_tutor
   - igcse_physics_tutor
   - igcse_sciences_tutor
   - igcse_english_tutor
   - igcse_history_tutor
   - igcse_geography_tutor
   - igcse_computer_science_tutor
   - igcse_business_studies_tutor
   - igcse_year_9_tutor
   - igcse_year_10_tutor
   - igcse_year_11_tutor

### Step 2: Reseed Teams SECOND

After professions are confirmed:

1. In the same **Data Management** page, scroll to **"Reload Team Data"** section
2. Click **"Update Teams"** button (or "Replace All Teams" for clean slate)
3. Wait for success message
4. **Check for warnings** - If you see "Warnings: X teams have no members", professions weren't found
5. Verify: Go to **WP Admin → Teams** and check that IGCSE teams have members:
   - IGCSE Mathematics Team (2 members)
   - IGCSE Science Tutoring Team (4 members)
   - IGCSE Humanities Tutoring Team (3 members)
   - IGCSE Languages & Technology Team (3 members)
   - IGCSE Year-Level Tutoring Team (3 members)
   - IGCSE Academic Support Team (5 members)

## Enhanced Features (Latest Version)

The reseed teams function now includes:

### Profession Check
- **Pre-flight validation**: Checks if at least 10 professions exist before attempting to reseed teams
- **Error message**: If < 10 professions found, shows clear error asking user to reseed professions first

### Better Diagnostics
- **Warning detection**: After saving each team, checks if members array is empty
- **Warning reporting**: Shows count of teams with no members in success message
- **Error logging**: Logs which profession slugs couldn't be found for debugging

### Example Messages

**Success with no issues:**
```
Teams reloaded successfully. Created: 8, Updated: 0
```

**Success with warnings:**
```
Teams reloaded successfully. Created: 8, Updated: 0. Warnings: 6 teams have no members. Try reseeding professions first.
```

**Error - professions not seeded:**
```
Not enough professions found in database (3). Please reseed professions first using "Update Professions" or "Replace All Professions" button above before reseeding teams.
```

## Troubleshooting

### Teams still show "No members" after reseed

**Cause**: Profession posts don't exist or have different slugs than expected

**Solution**:
1. Go to **WP Admin → Professions**
2. Search for "igcse" 
3. Verify all 13 IGCSE professions exist
4. Check the profession slug matches exactly (e.g., `igcse_mathematics_tutor` not `igcse-mathematics-tutor`)
5. If professions are missing, click **"Replace All Professions"** button
6. Then click **"Update Teams"** again

### Some teams have members, others don't

**Cause**: Some profession posts exist, others are missing

**Solution**:
1. Check error log for specific missing professions:
   ```
   WP_MCP_AI: Team X references profession "slug" which does not exist in database.
   ```
2. Reseed professions with **"Replace All Professions"**
3. Then reseed teams again

### Teams exist but validation fails

**Cause**: IGCSE Mathematics Team had only 1 member, validation requires 2+

**Solution**: Already fixed in this PR - `igcse_year_10_tutor` was added as second member

## Technical Details

### How Team Members Are Stored

1. **JSON file** (`education-extended-teams.json`) contains profession **slugs**:
   ```json
   "members": ["igcse_mathematics_tutor", "igcse_year_10_tutor"]
   ```

2. **Team Repository** converts slugs to post IDs using `get_posts()`:
   ```php
   $profession = get_posts([
       'post_type' => 'mcp_ai_profession',
       'name' => 'igcse_mathematics_tutor',
       'post_status' => 'publish',
       'posts_per_page' => 1,
       'fields' => 'ids',
   ]);
   ```

3. **Post meta** stores only the post IDs:
   ```php
   update_post_meta($team_id, '_wp_mcp_ai_team_members', [123, 456]);
   ```

4. **Admin display** retrieves profession posts by ID to show member names

### Why Order Matters

```
Professions (CPT posts) → Teams (CPT posts with profession IDs in meta)
                ↑                           ↓
         Must exist first        References profession IDs
```

If professions don't exist when teams are saved, the `get_posts()` query returns empty array, and team is saved with `_wp_mcp_ai_team_members = []`.

## Files Modified in This Fix

1. **`includes/knowledge-base/teams/education-extended-teams.json`**
   - Added `igcse_year_10_tutor` to IGCSE Mathematics Team (now has 2 members)

2. **`includes/repositories/class-wp-mcp-ai-team-repository.php`**
   - Added explicit `post_status => 'publish'` to profession query
   - Added error logging when profession slugs can't be resolved
   - Added tracking of missing members for diagnostics

3. **`includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`**
   - Added pre-flight check for profession count before reseeding teams
   - Added warning detection after saving each team
   - Added warning count to success message
   - Added error logging for debugging

4. **`IGCSE_IMPLEMENTATION_SUMMARY.md`**
   - Updated Mathematics Team to show 2 members
   - Added total member count statistic

5. **`docs/IGCSE_TEAMS_STRUCTURE.md`**
   - Updated Mathematics Team documentation

6. **`tests/test-enhanced-team-loading.php`**
   - Updated test expectations for Mathematics Team (2 members instead of 1)

## Testing Checklist

After deploying this fix:

- [ ] Go to **Settings → WP oOS → Advanced → Data Management**
- [ ] Click **"Update Professions"** (or "Replace All Professions")
- [ ] Verify success message shows professions created/updated
- [ ] Go to **Professions** admin page
- [ ] Verify all 13 IGCSE professions exist (search for "igcse")
- [ ] Go back to **Advanced → Data Management**
- [ ] Click **"Update Teams"** (or "Replace All Teams")
- [ ] Verify success message shows no warnings
- [ ] Go to **Teams** admin page
- [ ] Click on each IGCSE team and verify members are shown:
  - ✓ IGCSE Mathematics Team → 2 members
  - ✓ IGCSE Science Tutoring Team → 4 members
  - ✓ IGCSE Humanities Tutoring Team → 3 members
  - ✓ IGCSE Languages & Technology Team → 3 members
  - ✓ IGCSE Year-Level Tutoring Team → 3 members
  - ✓ IGCSE Academic Support Team → 5 members

## Additional Resources

- **Manifest**: `includes/knowledge-base/profession-playbooks/manifest.json` - All profession definitions
- **Teams JSON**: `includes/knowledge-base/teams/education-extended-teams.json` - IGCSE team definitions
- **Implementation Summary**: `IGCSE_IMPLEMENTATION_SUMMARY.md` - Complete IGCSE teams overview
- **Team Structure**: `docs/IGCSE_TEAMS_STRUCTURE.md` - Detailed team composition
