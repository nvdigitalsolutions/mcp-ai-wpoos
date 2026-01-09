#!/usr/bin/env node
/**
 * Playwright Screenshot Capture Script for NV oOS Chat Interface
 * 
 * This script automates the capture of all 16 required chat interface screenshots
 * using Playwright browser automation.
 * 
 * Prerequisites:
 * - Node.js and npm installed
 * - Playwright installed: npm install playwright
 * - WordPress environment running with NV oOS activated
 * - AI provider configured (OpenAI, Gemini, or Ollama)
 * - Test assistant and chat pages created (use bin/capture-chat-screenshots.sh)
 * 
 * Usage:
 *   node bin/playwright-capture-screenshots.js
 * 
 * Environment Variables:
 *   WORDPRESS_URL - WordPress URL (default: http://localhost:8000)
 *   ADMIN_USER - WordPress admin username (default: admin)
 *   ADMIN_PASS - WordPress admin password (default: StrongPassword123!)
 *   PAGE_ID - Chat page ID (required)
 *   GUEST_PAGE_ID - Guest chat page ID (required)
 */

const fs = require('fs');
const path = require('path');

// Configuration
const config = {
    wordpressUrl: process.env.WORDPRESS_URL || 'http://localhost:8000',
    adminUser: process.env.ADMIN_USER || 'admin',
    adminPass: process.env.ADMIN_PASS || 'StrongPassword123!',
    pageId: process.env.PAGE_ID || '',
    guestPageId: process.env.GUEST_PAGE_ID || '',
    screenshotsDir: path.join(__dirname, '..', 'docs', 'screenshots', 'chat'),
    viewportWidth: 1920,
    viewportHeight: 1080,
};

// Ensure screenshots directory exists
if (!fs.existsSync(config.screenshotsDir)) {
    fs.mkdirSync(config.screenshotsDir, { recursive: true });
}

/**
 * Main screenshot capture function
 */
async function captureScreenshots() {
    const playwright = require('playwright');
    const browser = await playwright.chromium.launch({
        headless: false, // Set to true for CI/CD
    });
    
    const context = await browser.newContext({
        viewport: {
            width: config.viewportWidth,
            height: config.viewportHeight,
        },
    });
    
    const page = await context.newPage();
    
    try {
        console.log('🚀 Starting screenshot capture...\n');
        
        // 1. Frontend shortcode - basic interface
        console.log('📸 Capturing: frontend-shortcode.png');
        await page.goto(`${config.wordpressUrl}/?page_id=${config.pageId}`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'frontend-shortcode.png'),
            fullPage: true,
        });
        console.log('✅ Saved: frontend-shortcode.png\n');
        
        // 2. Active conversation
        console.log('📸 Capturing: chat-conversation-example.png');
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'Hello! Can you help me understand what you can do?');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(3000); // Wait for response
        
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'What tools do you have access to?');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(3000); // Wait for response
        
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-conversation-example.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-conversation-example.png\n');
        
        // 3. File attachments (if upload button exists)
        console.log('📸 Capturing: chat-with-attachments.png (if upload available)');
        const uploadButton = await page.$('[data-testid="upload-button"], input[type="file"], button[aria-label*="upload"]');
        if (uploadButton) {
            await page.setInputFiles('input[type="file"]', path.join(__dirname, '..', 'README.md'));
            await page.waitForTimeout(1000);
            await page.screenshot({
                path: path.join(config.screenshotsDir, 'chat-with-attachments.png'),
                fullPage: true,
            });
            console.log('✅ Saved: chat-with-attachments.png\n');
        } else {
            console.log('⚠️  Upload button not found, skipping\n');
        }
        
        // 4. Tool execution
        console.log('📸 Capturing: chat-tool-execution.png');
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'Can you search for recent posts on this website?');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(2000); // Capture during tool execution
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-tool-execution.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-tool-execution.png\n');
        
        // 5. Streaming response (capture during typing)
        console.log('📸 Capturing: chat-streaming-response.png');
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'Tell me a short story about WordPress');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(500); // Capture early in response
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-streaming-response.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-streaming-response.png\n');
        
        // Wait for streaming to complete
        await page.waitForTimeout(3000);
        
        // 6. Prompt shortcuts (if available)
        console.log('📸 Capturing: chat-shortcuts-buttons.png (if shortcuts exist)');
        const shortcuts = await page.$$('[data-testid="shortcut-button"], .prompt-shortcut, button[data-shortcut]');
        if (shortcuts.length > 0) {
            await page.screenshot({
                path: path.join(config.screenshotsDir, 'chat-shortcuts-buttons.png'),
                fullPage: true,
            });
            console.log('✅ Saved: chat-shortcuts-buttons.png\n');
        } else {
            console.log('⚠️  Shortcuts not found, skipping\n');
        }
        
        // 7. Error handling (trigger by sending to invalid endpoint or disconnecting)
        console.log('📸 Capturing: chat-error-handling.png');
        // Simulate error by going offline
        await context.setOffline(true);
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'This should trigger an error');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(2000);
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-error-handling.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-error-handling.png\n');
        
        // Go back online
        await context.setOffline(false);
        
        // 8. Mobile portrait view
        console.log('📸 Capturing: chat-mobile-portrait.png');
        await page.setViewportSize({ width: 375, height: 667 });
        await page.reload();
        await page.waitForLoadState('networkidle');
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-mobile-portrait.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-mobile-portrait.png\n');
        
        // 9. Mobile landscape view
        console.log('📸 Capturing: chat-mobile-landscape.png');
        await page.setViewportSize({ width: 667, height: 375 });
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-mobile-landscape.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-mobile-landscape.png\n');
        
        // Reset to desktop view
        await page.setViewportSize({
            width: config.viewportWidth,
            height: config.viewportHeight,
        });
        
        // 10. Guest mode (in incognito context)
        console.log('📸 Capturing: frontend-guest-mode.png');
        const guestContext = await browser.newContext({
            viewport: {
                width: config.viewportWidth,
                height: config.viewportHeight,
            },
        });
        const guestPage = await guestContext.newPage();
        await guestPage.goto(`${config.wordpressUrl}/?page_id=${config.guestPageId}`);
        await guestPage.waitForLoadState('networkidle');
        await guestPage.screenshot({
            path: path.join(config.screenshotsDir, 'frontend-guest-mode.png'),
            fullPage: true,
        });
        console.log('✅ Saved: frontend-guest-mode.png\n');
        await guestContext.close();
        
        // 11. localStorage view (with DevTools)
        console.log('📸 Capturing: chat-history-localstorage.png');
        await page.goto(`${config.wordpressUrl}/?page_id=${config.pageId}`);
        await page.waitForLoadState('networkidle');
        // Open DevTools and take screenshot
        // Note: This requires CDP (Chrome DevTools Protocol)
        const client = await page.context().newCDPSession(page);
        await client.send('Debugger.enable');
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-history-localstorage.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-history-localstorage.png');
        console.log('   Note: Manually capture DevTools → Application → Local Storage\n');
        
        // 12. History restoration after reload
        console.log('📸 Capturing: chat-history-restoration.png');
        await page.fill('[data-testid="chat-input"], textarea[placeholder*="message"], #mcp-ai-chat-input', 'Test message before reload');
        await page.click('[data-testid="send-button"], button[type="submit"]');
        await page.waitForTimeout(2000);
        await page.reload();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
        await page.screenshot({
            path: path.join(config.screenshotsDir, 'chat-history-restoration.png'),
            fullPage: true,
        });
        console.log('✅ Saved: chat-history-restoration.png\n');
        
        // 13-16. Elementor widgets (requires Elementor plugin)
        console.log('📸 Elementor screenshots require Elementor plugin installation');
        console.log('   Please install Elementor and capture manually:');
        console.log('   - elementor-chat-widget.png');
        console.log('   - elementor-chat-widget-frontend.png');
        console.log('   - elementor-dashboard-widgets.png');
        console.log('   - elementor-chat-intro-widget.png\n');
        
        console.log('✅ Screenshot capture complete!');
        console.log(`📁 Screenshots saved to: ${config.screenshotsDir}\n`);
        
    } catch (error) {
        console.error('❌ Error during screenshot capture:', error);
        throw error;
    } finally {
        await browser.close();
    }
}

/**
 * Validate configuration before running
 */
function validateConfig() {
    const errors = [];
    
    if (!config.pageId) {
        errors.push('PAGE_ID environment variable is required');
    }
    
    if (!config.guestPageId) {
        errors.push('GUEST_PAGE_ID environment variable is required');
    }
    
    if (errors.length > 0) {
        console.error('❌ Configuration errors:');
        errors.forEach(error => console.error(`   - ${error}`));
        console.error('\nPlease run bin/capture-chat-screenshots.sh first to set up WordPress.');
        process.exit(1);
    }
}

// Main execution
if (require.main === module) {
    validateConfig();
    captureScreenshots().catch(error => {
        console.error('Fatal error:', error);
        process.exit(1);
    });
}

module.exports = { captureScreenshots };
