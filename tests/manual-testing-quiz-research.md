# Manual Testing Guide: Quiz Research Page Fixes

## Overview
This guide covers testing the fixes for:
1. Broken shortcode output ("]" appearing at bottom of page)
2. CPT action button not showing up
3. Quiz preview sidebar functionality

## Prerequisites
- WordPress site with NV oOS Pro addon installed
- At least one published Assistant with `research_quiz_topic` tool enabled
- Admin access to the WordPress site

## Test 1: Verify Shortcode Renders Correctly

### Steps:
1. Navigate to **Quizzes → Research & Add**
2. Check the page HTML source (View Source or Inspect Element)

### Expected Results:
- ✅ No `"]` text appearing at the bottom of the page
- ✅ The chat interface renders completely
- ✅ No JavaScript errors in browser console
- ✅ The shortcode `[mcp_ai_chat ...]` is not visible in the page content

### Debug:
If shortcode is still broken:
- Check browser console for JavaScript errors
- Verify the assistant ID in Quiz Settings is valid
- Check PHP error logs for shortcode parsing issues

## Test 2: Verify CPT Action Button Appears

### Steps:
1. Navigate to **Quizzes → Research & Add**
2. Type a message in the chat (e.g., "Hello")
3. Send the message
4. Wait for assistant response

### Expected Results:
- ✅ "Add to Database" button appears in the control buttons row at the bottom of the chat
- ✅ Button has database icon (dashicons-database-add)
- ✅ Button is styled as primary button (blue)
- ✅ Button is visible and not hidden

### Debug:
If button doesn't appear:
- Open browser console and check for JavaScript errors
- Look for `window.wpMcpAiChatInstances` object in console
- Verify it contains `cptActions` array with one item
- Check that `renderCptActionButtons` function is being called

## Test 3: Verify Quiz Preview Updates

### Workflow A: Research Quiz (Preview Should Show)

#### Steps:
1. Navigate to **Quizzes → Research & Add**
2. In the chat, type: "Research quiz about World War II with 5 questions"
3. Send the message
4. Wait for AI to process and call the `research_quiz_topic` tool

#### Expected Results:
- ✅ The sidebar "Quiz Preview" section becomes visible
- ✅ Shows "Building quiz..." loading indicator briefly
- ✅ Preview displays:
  - Quiz title
  - Metadata (difficulty, question count, time limit, pass score)
  - List of questions with options
  - Correct answers marked with ✓
- ✅ Pagination appears if more than 3 questions
- ✅ Preview updates smoothly if you ask to modify the quiz

#### Debug:
If preview doesn't show:
- Check browser console for `wp-mcp-ai-tool-result-stored` event
- Verify the tool name is exactly `research_quiz_topic`
- Check that result has `success: true` property
- Verify ResearchPage JavaScript is initialized (check for `.wp-mcp-ai-research-page` class)

### Workflow B: Direct Create Quiz (No Preview)

#### Steps:
1. Navigate to **Quizzes → Research & Add**
2. In the chat, type: "Create a quiz about Python programming with 3 questions"
3. Send the message
4. Wait for AI to process and call the `create_quiz` tool

#### Expected Results:
- ✅ Quiz is created immediately in database
- ✅ Assistant confirms quiz was created with quiz ID
- ✅ Preview sidebar does NOT show (this is correct - quiz already exists)
- ✅ You can navigate to **Quizzes** list to see the new quiz

## Test 4: Verify "Add to Database" Button Works

### Steps:
1. Complete "Test 3 - Workflow A" above to get quiz preview
2. Verify the preview shows quiz data
3. Click the "Add to Database" button in the control buttons row

### Expected Results:
- ✅ Confirmation dialog appears: "Create a quiz with the researched information?"
- ✅ After confirming:
  - Button shows loading state with spinner
  - Button text changes to "Creating quiz..."
- ✅ Success message appears
- ✅ Page redirects to edit the newly created quiz
- ✅ New quiz has all the questions from the preview

### Debug:
If button click fails:
- Check browser console for JavaScript errors
- Verify `handleCptActionClick` is being called
- Check Network tab for AJAX request to `admin-ajax.php`
- Check response for error messages
- Verify user has `edit_posts` capability

## Test 5: Verify Pagination in Preview

### Steps:
1. Navigate to **Quizzes → Research & Add**
2. In the chat, type: "Research quiz about Shakespeare with 10 questions"
3. Wait for preview to load with 10 questions

### Expected Results:
- ✅ Preview shows first 3 questions
- ✅ Pagination controls appear at bottom
- ✅ Shows "1 of 4" (10 questions / 3 per page = 4 pages)
- ✅ "Previous" button is disabled on page 1
- ✅ Click "Next" to see questions 4-6
- ✅ Click "Next" again to see questions 7-9
- ✅ Click "Next" again to see question 10
- ✅ "Next" button is disabled on last page

## Test 6: Verify Base64 Encoding Compatibility

### Steps:
1. Create a test shortcode manually:
   ```php
   // In a test page or widget, add:
   echo do_shortcode('[mcp_ai_chat assistant="123"]');
   ```
2. Verify it still works (backwards compatibility)

3. Then test with base64-encoded cpt_actions:
   ```php
   $actions = array(
       array(
           'label' => 'Test Button',
           'action' => 'test_action',
           'classes' => 'button',
           'icon' => 'dashicons-yes'
       )
   );
   echo do_shortcode('[mcp_ai_chat assistant="123" cpt_actions="' . 
       esc_attr(base64_encode(wp_json_encode($actions))) . '"]');
   ```

### Expected Results:
- ✅ Both shortcodes render without errors
- ✅ No `"]` appears in output
- ✅ CPT actions decode correctly

## Common Issues and Solutions

### Issue: "Add to Database" button never appears
**Solution**: Check that the shortcode is passing `cpt_actions` attribute correctly. Inspect the page source to verify the `data-wp-mcp-ai-chat` element has proper configuration.

### Issue: Preview doesn't update
**Solution**: Verify the assistant has `research_quiz_topic` tool enabled in its configuration.

### Issue: Clicking button does nothing
**Solution**: Check browser console for JavaScript errors. Verify jQuery is loaded before the research-page.js script.

### Issue: `"]` still appears on page
**Solution**: Clear all caches (WordPress object cache, page cache, browser cache) and reload. Verify the latest code is deployed.

## Success Criteria

All tests pass when:
- ✅ No broken shortcode output
- ✅ CPT action button renders and is clickable
- ✅ Quiz preview shows for research workflow
- ✅ Button click creates quiz successfully
- ✅ Pagination works in preview
- ✅ No JavaScript console errors
- ✅ No PHP errors in logs

## Additional Notes

- The research page uses jQuery, so ensure it's loaded
- Preview styling is controlled by `research-page.css`
- CPT action button rendering happens in `chat.js` line ~4133
- Tool result events are fired from `chat.js` line ~8719
- Preview update logic is in `research-page.js` line ~125
