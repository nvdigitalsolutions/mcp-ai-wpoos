# Quiz System Troubleshooting Guide

This guide helps resolve common issues with the NV oOS Quiz System.

## Table of Contents

1. [Setup Issues](#setup-issues)
2. [Submission Problems](#submission-problems)
3. [Grading Issues](#grading-issues)
4. [Time Tracking Problems](#time-tracking-problems)
5. [Permission Errors](#permission-errors)
6. [JetEngine CCT Sync](#jetengine-cct-sync)
7. [Performance Issues](#performance-issues)

---

## Setup Issues

### Quiz System Not Showing

**Problem**: Quiz tools are not available in the plugin.

**Solutions**:
1. Check if you're in Base Version mode:
   ```php
   // In wp-config.php, ensure this is NOT defined:
   // define( 'WP_MCP_AI_BASE_VERSION', true );
   ```

2. Enable the quiz system:
   - Go to **WP Admin → Settings → NV oOS → Tools & Features**
   - Click the **Features** tab
   - Check **"Enable Quiz System"**
   - Click **Save Changes**

3. Verify feature is enabled:
   ```php
   $settings = get_option( 'wp_mcp_ai_settings', array() );
   if ( empty( $settings['enable_quiz_system'] ) ) {
       // Feature is not enabled
   }
   ```

### JetEngine CCT Not Syncing

**Problem**: Quizzes or submissions not appearing in JetEngine CCT.

**Solutions**:
1. Verify JetEngine is active and CCT module is enabled
2. Check if CCT was created:
   - Go to **JetEngine → Custom Content Types**
   - Look for "Quizzes" and "Quiz Submissions" CCTs
   - If missing, deactivate and reactivate the plugin

3. Manually trigger sync:
   ```php
   // Save the quiz again to trigger sync
   $quiz = get_post( $quiz_id );
   wp_update_post( array( 'ID' => $quiz_id ) );
   ```

---

## Submission Problems

### "Too many submission attempts" Error

**Problem**: Users getting rate limit error when submitting quizzes.

**Cause**: Rate limiting prevents spam (5 submissions per 5 minutes per user).

**Solutions**:
1. Wait 5 minutes before trying again
2. Clear the rate limit (admin only):
   ```php
   delete_transient( 'wp_mcp_ai_quiz_submit_' . $user_id );
   ```

3. Adjust rate limit (in code):
   ```php
   // In class-wp-mcp-ai-tool-submit-quiz-answer.php
   // Change: if ( $recent_submissions >= 5 )
   // To: if ( $recent_submissions >= 10 ) // Allow 10 instead
   ```

### "Duplicate submission" Error

**Problem**: User cannot submit quiz again.

**Cause**: Each user can only submit once per quiz.

**Solutions**:
1. This is intentional behavior
2. To allow resubmission, delete the existing submission:
   ```php
   // Find and delete existing submission
   $existing = get_posts( array(
       'post_type'   => 'mcp_ai_submission',
       'author'      => $user_id,
       'meta_key'    => '_mcp_ai_submission_quiz_id',
       'meta_value'  => $quiz_id,
       'numberposts' => 1,
   ) );
   if ( ! empty( $existing ) ) {
       wp_delete_post( $existing[0]->ID, true );
   }
   ```

### Invalid Answer Format Errors

**Problem**: Submission rejected with "Invalid true/false answer" or similar.

**Solutions**:

**True/False Questions:**
- Valid: `true`, `false`, `yes`, `no`, `1`, `0` (case-insensitive)
- Normalized to: `true` or `false`

**Multiple Choice:**
- Answer must exactly match one of the provided options (case-sensitive)
- Check spelling and capitalization

**Short Answer:**
- Cannot be empty
- Any non-empty text is valid

---

## Grading Issues

### Permission Denied When Grading

**Problem**: "You do not have permission to grade this quiz"

**Cause**: Only quiz author or users with `edit_others_posts` capability can grade.

**Solutions**:
1. Check user role:
   - **Authors**: Can only grade their own quizzes
   - **Editors/Admins**: Can grade any quiz

2. Verify quiz authorship:
   ```php
   $quiz = get_post( $quiz_id );
   $is_author = ( $quiz->post_author == $current_user_id );
   $can_edit_others = current_user_can( 'edit_others_posts' );
   ```

3. Grant permission (admin):
   ```php
   // Add capability to role
   $role = get_role( 'author' );
   $role->add_cap( 'edit_others_posts' );
   ```

### Points Exceed Maximum Error

**Problem**: "Points earned exceed maximum for question"

**Cause**: Grader assigned more points than question allows.

**Solution**:
1. Check question max points:
   ```php
   $questions = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );
   $max_points = $questions[0]['points']; // Check question points
   ```

2. Award points within the maximum:
   ```php
   array(
       'question_index' => 0,
       'points_earned'  => 3, // Must be <= question max
   )
   ```

---

## Time Tracking Problems

### "Time limit exceeded" Error

**Problem**: Submission rejected for exceeding time limit.

**Cause**: More than (time_limit + 1 minute grace period) elapsed.

**Solutions**:
1. Ensure `started_at` is set correctly:
   ```php
   $started_at = gmdate( 'Y-m-d\TH:i:s\Z' ); // ISO 8601 format
   ```

2. Check time limit:
   ```php
   $time_limit = get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true );
   // 0 = no limit
   ```

3. Verify timestamp format:
   - Must be ISO 8601: `2025-01-03T14:30:00Z`
   - Use `gmdate()` or `current_time('mysql')` converted to ISO 8601

### Missing Start Time Error

**Problem**: "Time limit exists but no start time provided"

**Cause**: Quiz has `time_limit > 0` but `started_at` parameter not provided.

**Solution**:
Always include `started_at` when submitting quizzes with time limits:
```json
{
  "quiz_id": 123,
  "started_at": "2025-01-03T14:30:00Z",
  "answers": [...]
}
```

### Completion Time Not Calculated

**Problem**: `completion_time` is null in results.

**Cause**: `started_at` was not provided during submission.

**Solution**:
Completion time is only calculated when `started_at` is provided. Include it in submissions:
```php
$started_at = current_time( 'c' ); // ISO 8601 format
```

---

## Permission Errors

### Subscriber Cannot Grade

**Problem**: Subscriber gets permission error when trying to grade.

**Expected Behavior**: This is correct. Only these roles can grade:
- **Quiz Author** (any role)
- **Users with `edit_others_posts`** (Editors, Admins)

**Solution**: Assign appropriate role or grant capability.

### Student Cannot View Others' Results

**Problem**: Student trying to view another student's results.

**Expected Behavior**: Students can only view their own results.

**Solution**:
```php
// Check if user is viewing their own results
$submission = get_post( $submission_id );
$is_own_submission = ( $submission->post_author == $current_user_id );
```

---

## JetEngine CCT Sync

### CCT Fields Not Updating

**Problem**: Changes to quiz/submission not reflected in CCT.

**Solutions**:
1. Verify sync is triggered:
   - Sync runs on `save_post` hook
   - May not trigger on direct meta updates

2. Manual sync:
   ```php
   // Trigger sync by updating post
   wp_update_post( array(
       'ID'            => $post_id,
       'post_modified' => current_time( 'mysql' ),
   ) );
   ```

3. Check sync lock:
   ```php
   // Clear stuck sync lock
   delete_transient( 'wp_mcp_ai_quiz_sync_lock_' . $post_id );
   delete_transient( 'wp_mcp_ai_submission_sync_lock_' . $post_id );
   ```

### Time Tracking Fields Missing in CCT

**Problem**: `started_at` and `completion_time` not in CCT.

**Cause**: Using older version of plugin.

**Solutions**:
1. Update to latest version (v1.1+)
2. Check if fields exist in CCT schema:
   - Go to **JetEngine → Custom Content Types**
   - Edit "Quiz Submissions" CCT
   - Look for `started_at` and `completion_time` fields
3. If missing, deactivate and reactivate plugin to re-register CCT

---

## Performance Issues

### Slow Quiz Creation

**Problem**: Creating quizzes with many questions is slow.

**Solutions**:
1. Break large quizzes into smaller ones (recommended: < 50 questions)
2. Disable CCT sync temporarily (for bulk operations):
   ```php
   remove_action( 'save_post_mcp_ai_quiz', array( 'WP_MCP_AI_Quiz_CPT', 'sync_quiz_to_cct' ) );
   // ... create quizzes ...
   add_action( 'save_post_mcp_ai_quiz', array( 'WP_MCP_AI_Quiz_CPT', 'sync_quiz_to_cct' ), 10, 2 );
   ```

### Submission List Loading Slowly

**Problem**: `get_quiz_submissions` taking too long.

**Solutions**:
1. Use pagination:
   ```php
   array(
       'quiz_id'  => 123,
       'per_page' => 10,  // Limit results
       'page'     => 1,
   )
   ```

2. Filter by status:
   ```php
   array(
       'quiz_id' => 123,
       'status'  => 'pending', // Only pending submissions
   )
   ```

3. Add database indexes (advanced):
   ```sql
   ALTER TABLE wp_postmeta 
   ADD INDEX idx_quiz_id (_mcp_ai_submission_quiz_id(20));
   ```

---

## Debugging Tips

### Enable Debug Logging

1. Enable in WordPress:
   ```php
   // wp-config.php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```

2. Enable plugin logging:
   - Go to **Settings → NV oOS**
   - Check **"Enable Logging"**

3. Check logs:
   - **PHP Error Log**: `/wp-content/debug.log`
   - **Plugin Logs**: WP Admin → Settings → NV oOS → View Logs

### Check Quiz Metadata

View all quiz metadata:
```php
$quiz_id = 123;
$meta = get_post_meta( $quiz_id );
print_r( $meta );
```

Key fields:
- `_mcp_ai_quiz_questions` - Question array
- `_mcp_ai_quiz_total_points` - Total points
- `_mcp_ai_quiz_time_limit` - Time limit (minutes)
- `_mcp_ai_quiz_passing_score` - Passing percentage

### Check Submission Metadata

```php
$submission_id = 456;
$meta = get_post_meta( $submission_id );
print_r( $meta );
```

Key fields:
- `_mcp_ai_submission_quiz_id` - Associated quiz
- `_mcp_ai_submission_answers` - User answers
- `_mcp_ai_submission_status` - pending/graded
- `_mcp_ai_submission_ip_address` - Submitter IP
- `_mcp_ai_submission_audit_log` - Grade change history

### View Audit Log

```php
$audit_log = get_post_meta( $submission_id, '_mcp_ai_submission_audit_log', true );
foreach ( $audit_log as $entry ) {
    echo sprintf(
        "%s - Grader: %s, Score: %.1f/%d (%.1f%%), IP: %s\n",
        $entry['timestamp'],
        $entry['grader_name'],
        $entry['earned_points'],
        $entry['total_points'],
        $entry['percentage'],
        $entry['ip_address']
    );
}
```

---

## Common Error Codes

| Error Code | Meaning | Solution |
|-----------|---------|----------|
| `wp_mcp_ai_forbidden` | Permission denied | Check user role/capabilities |
| `wp_mcp_ai_quiz_not_found` | Invalid quiz ID | Verify quiz exists |
| `wp_mcp_ai_duplicate_submission` | Already submitted | Delete existing submission |
| `wp_mcp_ai_rate_limit_exceeded` | Too many submissions | Wait 5 minutes |
| `wp_mcp_ai_time_limit_exceeded` | Exceeded time limit | Check start time and limit |
| `wp_mcp_ai_invalid_question_index` | Invalid question number | Check question exists |
| `wp_mcp_ai_points_exceed_max` | Too many points awarded | Check question max points |
| `wp_mcp_ai_invalid_true_false_answer` | Bad boolean value | Use true/false/yes/no/1/0 |
| `wp_mcp_ai_invalid_multiple_choice_answer` | Invalid option | Check option matches exactly |
| `wp_mcp_ai_empty_answer` | Blank short answer | Provide non-empty text |

---

## Getting Help

If you continue experiencing issues:

1. **Check Plugin Version**: Ensure you're running the latest version
2. **Review Documentation**: See `/docs/features/tools/QUIZ_TOOLS.md`
3. **Enable Debug Mode**: Capture detailed error information
4. **Check Server Requirements**:
   - PHP 7.4+
   - WordPress 6.0+
   - Sufficient memory (`memory_limit >= 256M`)

5. **Report Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

Include in your report:
- Plugin version
- WordPress version
- PHP version
- Error messages (from debug log)
- Steps to reproduce
- Relevant quiz/submission IDs

---

**Last Updated**: January 2025  
**Plugin Version**: 1.1+  
**Applicable To**: Quiz System (Full Version Only)
