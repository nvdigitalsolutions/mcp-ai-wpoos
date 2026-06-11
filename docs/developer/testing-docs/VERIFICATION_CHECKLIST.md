# Manual Verification Checklist

This checklist helps verify that the embedded provider settings fix is working correctly in a live WordPress environment.

## Prerequisites
- [ ] WordPress site with NV oOS plugin installed
- [ ] Pro addon active (check `WP_MCP_AI_PRO_VERSION` is defined)
- [ ] Access to WordPress admin dashboard
- [ ] Browser with developer console access (optional but helpful)

## Step 1: Access Provider Settings
1. [ ] Log in to WordPress admin
2. [ ] Navigate to **NV oOS** in the left sidebar
3. [ ] Click **General Settings** (or it might auto-open)
4. [ ] Click the **AI Providers** tab at the top

**Expected Result:** You should see the AI Providers configuration page.

## Step 2: Verify Embedded LLM Subtab Appears
1. [ ] Look at the subtab navigation below the "AI Providers" heading
2. [ ] Verify you see these subtabs (order may vary):
   - [ ] Priority Order
   - [ ] OpenAI
   - [ ] Anthropic
   - [ ] Google Gemini
   - [ ] Ollama (Local)
   - [ ] LM Studio (Local)
   - [ ] Hugging Face
   - [ ] HF Datasets
   - [ ] Cloudflare
   - [ ] Google Maps
   - [ ] **Embedded LLM** ← This is the key one!

**✅ PASS:** "Embedded LLM" subtab is visible  
**❌ FAIL:** "Embedded LLM" subtab is missing

## Step 3: Access Embedded LLM Settings
1. [ ] Click the **Embedded LLM** subtab
2. [ ] Wait for the page to load the settings

**Expected Result:** Settings page loads without errors.

## Step 4: Verify Settings Fields Appear
Check that you see all three settings fields:

1. [ ] **Enable Embedded LLM Provider** (checkbox)
   - Should have a checkbox you can toggle
   - Label: "Enable client-side embedded language models"
   - Description mentions WebGPU/WebAssembly

2. [ ] **Default Embedded Model** (dropdown)
   - Should show a dropdown selector
   - Should list models like:
     - Hermes 2 Pro Llama 3 8B (~4.5GB) - Recommended*
     - Qwen2.5 7B Instruct (~4.5GB)*
     - Phi-3.5 Mini Instruct (~2.5GB)*
     - Llama 3.2 3B Instruct (~2GB)
     - Qwen2.5 1.5B Instruct (~1GB)*
     - Llama 3.2 1B Instruct (~800MB)
     - Qwen2.5 0.5B Instruct (~400MB)

3. [ ] **Available Models** (informational section)
   - Should show info about client-side models
   - Mentions browser cache and WebGPU/WebAssembly

**✅ PASS:** All three fields are visible and functional  
**❌ FAIL:** Any fields are missing or broken

## Step 5: Test Settings Save
1. [ ] Check the "Enable Embedded LLM Provider" checkbox (if not already checked)
2. [ ] Select a model from the "Default Embedded Model" dropdown (recommend: Hermes 2 Pro)
3. [ ] Scroll to bottom of page
4. [ ] Click **Save Changes** button
5. [ ] Wait for page to reload with success message

**Expected Results:**
- [ ] Success message appears: "Settings saved successfully" (or similar)
- [ ] Page reloads showing the embedded subtab
- [ ] Your settings are preserved (checkbox still checked, model still selected)

**✅ PASS:** Settings save and persist correctly  
**❌ FAIL:** Settings don't save or are lost after reload

## Step 6: Verify No Console Errors (Optional)
1. [ ] Open browser developer console (F12 or right-click → Inspect)
2. [ ] Go to the **Console** tab
3. [ ] Check for any JavaScript errors (red text)

**Expected Result:** No critical errors related to embedded provider or settings.

**Note:** Some warnings or notices are normal. Only report red errors that mention "embedded" or "provider".

## Step 7: Test Embedded Chat Client (Optional but Recommended)
If you have an embedded chat widget set up:

1. [ ] Navigate to a page with the embedded chat widget
2. [ ] Open the chat interface
3. [ ] Type a test message
4. [ ] Check browser console for model loading messages

**Expected Results:**
- [ ] Model loads (may take time on first use)
- [ ] Chat responds successfully
- [ ] No WebGPU adapter errors
- [ ] Console shows successful model initialization

**Common Issues:**
- First model load can take several minutes (downloading to browser cache)
- Some browsers don't support WebGPU (Safari, older browsers)
- GPU may not be supported on some devices

## Verification Results

### Overall Status
- [ ] ✅ **ALL TESTS PASSED** - Fix is working correctly
- [ ] ⚠️ **PARTIAL PASS** - Some issues found (document below)
- [ ] ❌ **FAILED** - Critical issues found (document below)

### Issues Found (if any)
```
Describe any issues you encountered:

1. 

2. 

3. 
```

### Screenshots (Recommended)
Please provide screenshots showing:
1. Embedded LLM subtab in navigation
2. Embedded LLM settings page with all fields visible
3. Success message after saving settings

### Environment Details
- **WordPress Version:** 
- **Plugin Version:** 
- **Pro Version:** 
- **PHP Version:** 
- **Browser:** 
- **Operating System:** 

## Troubleshooting

### If "Embedded LLM" Subtab Doesn't Appear
1. Verify Pro addon is active: Check if `WP_MCP_AI_PRO_VERSION` constant is defined
2. Clear WordPress cache (if using cache plugin)
3. Check PHP error log for fatal errors
4. Verify container function exists: `function_exists('wp_mcp_ai_container')`

### If Settings Fields Don't Appear
1. Check browser console for JavaScript errors
2. Check PHP error log for reflection-related errors
3. Verify `WP_MCP_AI_Section_Pro_Providers` class exists
4. Try disabling other plugins temporarily

### If Settings Don't Save
1. Verify you have proper admin capabilities
2. Check if settings nonce is being generated
3. Check WordPress permissions on options table
4. Look for AJAX or form submission errors in browser network tab

## Reporting Issues

If you encounter issues, please provide:
1. Completed checklist with fail points marked
2. Screenshots of the issue
3. PHP error log entries (if any)
4. Browser console errors (if any)
5. Environment details from above

Thank you for helping verify this fix!
