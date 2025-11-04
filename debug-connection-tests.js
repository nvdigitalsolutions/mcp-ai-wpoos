/**
 * Debug script for connection test buttons
 * 
 * Add this to the browser console on the WP WP oOS settings page to diagnose
 * why the "Test Connection" buttons are not working.
 */

(function() {
    console.log('=== Connection Test Button Diagnostics ===');
    
    // Check if jQuery is loaded
    if (typeof jQuery === 'undefined') {
        console.error('❌ jQuery is not loaded!');
        return;
    }
    console.log('✅ jQuery is loaded (version ' + jQuery.fn.jquery + ')');
    
    // Check if wpMcpAiAdmin is defined
    if (typeof wpMcpAiAdmin === 'undefined') {
        console.error('❌ wpMcpAiAdmin is not defined! The admin-settings.js script may not be loaded.');
        console.log('Expected: wpMcpAiAdmin = { ajaxUrl: "...", nonce: "..." }');
        return;
    }
    console.log('✅ wpMcpAiAdmin is defined:', wpMcpAiAdmin);
    
    // Check if AJAX URL is valid
    if (!wpMcpAiAdmin.ajaxUrl) {
        console.error('❌ wpMcpAiAdmin.ajaxUrl is empty!');
    } else {
        console.log('✅ AJAX URL:', wpMcpAiAdmin.ajaxUrl);
    }
    
    // Check if nonce is valid
    if (!wpMcpAiAdmin.nonce) {
        console.error('❌ wpMcpAiAdmin.nonce is empty!');
    } else {
        console.log('✅ Nonce:', wpMcpAiAdmin.nonce);
    }
    
    // Check if buttons exist
    const buttons = {
        ollama: '#wp-mcp-ai-test-ollama-connection',
        lmStudio: '#wp-mcp-ai-test-lm-studio-connection',
        cloudflare: '#wp-mcp-ai-test-cloudflare-connection'
    };
    
    console.log('\n--- Checking Buttons ---');
    Object.keys(buttons).forEach(function(key) {
        const selector = buttons[key];
        const $button = jQuery(selector);
        
        if ($button.length === 0) {
            console.warn('⚠️ ' + key + ' button not found: ' + selector);
        } else {
            console.log('✅ ' + key + ' button found');
            
            // Check if button has click handlers
            const events = jQuery._data($button[0], 'events');
            if (!events || !events.click) {
                console.error('❌ ' + key + ' button has NO click handlers attached!');
                console.log('   This means the JavaScript initialization failed.');
            } else {
                console.log('✅ ' + key + ' button has ' + events.click.length + ' click handler(s)');
            }
        }
    });
    
    // Check for JavaScript errors in console
    console.log('\n--- Recommendations ---');
    console.log('1. Check the browser console for JavaScript errors');
    console.log('2. Verify you are on the Settings → WP oOS page');
    console.log('3. Check Network tab when clicking buttons to see if AJAX request is sent');
    console.log('4. Try refreshing the page with Ctrl+Shift+R (hard refresh)');
    console.log('5. If still not working, check if admin-settings.js is loaded:');
    console.log('   - Open Network tab');
    console.log('   - Filter by "admin-settings.js"');
    console.log('   - Verify the file is loaded with HTTP 200 status');
    
    console.log('\n=== Diagnostics Complete ===');
})();
