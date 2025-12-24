# Professional Test Model - Testing Guide

## Quick Test Guide

### Prerequisites
- WordPress installation with WP oOS plugin active
- At least one profession created
- Optional: One or more assistants created

## Test Scenarios

### Scenario 1: Profession Standalone (No Associated Assistant, No Default)

**Setup:**
1. Go to Settings → WP oOS
2. Ensure "Default Assistant" is NOT set (or set to "None")
3. Create a profession:
   - Title: "Tax Advisor Test"
   - Role Description: "You are a professional tax advisor"
   - Default Tools: Select 2-3 tools (e.g., search_posts, create_post)
   - Leave "Associated Assistant" empty

**Test:**
1. Go to Professions → Test Profession
2. Click "Test" on the "Tax Advisor Test" profession
3. Modal opens with chat interface
4. Send a test message: "Hello, what can you help me with?"

**Expected Result:**
- ✅ Chat works without errors
- ✅ Response reflects tax advisor role
- ✅ No "missing assistant" error
- ✅ Tools from profession are available

**Debug Info (Browser Console):**
```javascript
// assistantId should be: "profession_123" (where 123 is profession ID)
// Backend will resolve to assistant_id = 0
// System prompt should be profession-only
```

---

### Scenario 2: Profession with Associated Assistant (Append Mode)

**Setup:**
1. Create an assistant first:
   - Title: "General Helper"
   - System Prompt: "You are a helpful AI assistant with general knowledge."
   - Tools: Select 1-2 general tools
2. Edit your profession:
   - Set "Associated Assistant" to "General Helper"
   - Keep profession role description and tools

**Test:**
1. Go to Professions → Test Profession
2. Click "Test" on the profession
3. Send message: "What are your capabilities?"

**Expected Result:**
- ✅ Response mentions BOTH general assistant knowledge AND professional role
- ✅ Tools from BOTH assistant and profession are available
- ✅ Response shows layered expertise (general + professional)

**Verify in System Prompt:**
- Should contain original assistant prompt
- Should contain "\n\nProfessional Role & Expertise:" section
- Should contain profession role description

---

### Scenario 3: Test Default Assistant Still Works

**Setup:**
1. Go to Settings → WP oOS
2. Set a "Default Assistant"
3. Go to regular chat interface (not profession testing)

**Test:**
1. Use chat shortcode or widget
2. Don't specify an assistant_id
3. Send a message

**Expected Result:**
- ✅ Uses default assistant (unchanged behavior)
- ✅ No profession data involved

---

## Frontend Testing (JavaScript Console)

### Check Chat Configuration

```javascript
// Open browser console on Test Profession page after clicking Test
console.log(window.wpMcpAiChatInstances);

// Should show something like:
{
  "wp-mcp-ai-test-profession-chat-123-1234567890": {
    assistantId: "profession_123",
    professionId: 123,
    // ... other config
  }
}
```

### Monitor Network Requests

1. Open Network tab in DevTools
2. Filter by "chat" or "mcp-ai"
3. Click Test on a profession
4. Send a message
5. Look for POST to `/wp-json/mcp-ai/v1/chat-client`

**Check Request Payload:**
```json
{
  "assistant_id": "profession_123",
  "messages": [...],
  // ...
}
```

**Check Response:**
- Should not have "missing assistant" error
- Should return AI response
- System prompt in debug should show profession knowledge

---

## Backend Testing (PHP)

### Test resolve_assistant_id

```php
// In WordPress admin or via WP-CLI
$rest = new WP_MCP_AI_REST();
$reflection = new ReflectionClass($rest);
$method = $reflection->getMethod('resolve_assistant_id');
$method->setAccessible(true);

// Test 1: Profession without associated assistant
$result = $method->invoke($rest, 'profession_123');
echo "Result: " . $result; // Should be 0

// Test 2: Profession with associated assistant (ID 456)
// (After setting _wp_mcp_ai_profession_associated_assistant meta)
$result = $method->invoke($rest, 'profession_456');
echo "Result: " . $result; // Should be 456
```

### Test load_profession_configuration

```php
$rest = new WP_MCP_AI_REST();
$reflection = new ReflectionClass($rest);
$method = $reflection->getMethod('load_profession_configuration');
$method->setAccessible(true);

$profession_id = 123; // Your profession ID

// Test with empty assistant config
$empty_config = array();
$result = $method->invoke($rest, $profession_id, $empty_config);
var_dump($result['system_prompt']); // Should be profession-only

// Test with assistant config
$assistant_config = array(
    'system_prompt' => 'You are a helpful assistant.',
    'tools' => array('tool1'),
);
$result = $method->invoke($rest, $profession_id, $assistant_config);
var_dump($result['system_prompt']); // Should contain both
var_dump($result['tools']); // Should merge tools
```

---

## Automated Testing

### Run PHPUnit Tests

```bash
cd /path/to/wp-content/plugins/mcp-ai-wpoos

# Run profession integration tests
vendor/bin/phpunit tests/test-profession-integration.php

# Run all tests
vendor/bin/phpunit
```

### Expected Test Results

All tests should pass:
- ✅ test_extract_profession_id
- ✅ test_resolve_assistant_id_with_profession (updated - expects 0)
- ✅ test_load_profession_configuration
- ✅ test_load_profession_configuration_preserves_assistant_config
- ✅ test_profession_configuration_priority (updated - tests append)
- ✅ test_profession_with_associated_assistant (new)
- ✅ test_profession_without_assistant_standalone (new)

---

## Common Issues & Solutions

### Issue: "No assistant was provided and no default assistant is configured"

**Cause:** Old code path or profession_id extraction failed

**Debug:**
1. Check profession exists and is published
2. Verify assistant_id parameter is `"profession_123"` format
3. Check `extract_profession_id()` is working

**Solution:**
- Verify profession post type is `mcp_ai_profession`
- Clear any caches
- Check profession ID is valid

### Issue: Profession knowledge not appearing in response

**Cause:** Profession meta data missing or playbook loader failed

**Debug:**
1. Check profession has role_description meta
2. Verify playbook files exist
3. Check system prompt construction

**Solution:**
- Add role description to profession
- Verify playbook loader class is loaded
- Check profession category is set

### Issue: Tools not available

**Cause:** Tool merge logic or capability checks

**Debug:**
1. Check profession has default_tools meta
2. Verify tools are registered in plugin
3. Check user capabilities for tools

**Solution:**
- Set default tools in profession meta
- Verify tool slugs are correct
- Check tool capability requirements

---

## Verification Checklist

- [ ] Profession without associated assistant works standalone
- [ ] Profession with associated assistant appends knowledge
- [ ] Tools are merged correctly
- [ ] Memory files are merged correctly
- [ ] Provider/model/temperature from profession override
- [ ] No default assistant required
- [ ] Default assistant still works for non-profession chats
- [ ] All PHPUnit tests pass
- [ ] No JavaScript errors in browser console
- [ ] Network requests succeed (200 status)

---

## Rollback Plan

If issues are discovered:

1. **Git revert:**
   ```bash
   git revert <commit-hash>
   ```

2. **Or restore old behavior:**
   - In `resolve_assistant_id()`, change `return 0;` back to returning default assistant
   - In `load_profession_configuration()`, change append logic back to replace logic

3. **Database:**
   - No database changes were made, so no migration needed

---

## Support

For issues or questions:
- Check logs: Settings → WP oOS → View Logs
- Enable debug mode: `define('WP_DEBUG', true);`
- Check browser console for JavaScript errors
- Review backend error logs in PHP error log

## Related Documentation

- `docs/PROFESSIONAL_TEST_MODEL_CHANGES.md` - Architecture details
- `tests/test-profession-integration.php` - Test examples
- `/tmp/verify-profession-model.php` - Standalone verification
