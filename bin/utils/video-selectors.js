/**
 * Centralized selector registry for demo video scripts.
 *
 * Every selector used by video scripts lives here, organized by page/feature.
 * Scripts import named exports instead of defining inline selector arrays.
 *
 * When the UI changes, update the selectors HERE — not in each script.
 *
 * Selector priority order within each array:
 *   1. data-testid attribute (most stable)
 *   2. Role-based locators (getByRole equivalents as CSS)
 *   3. Semantic CSS classes (least preferred, kept as fallback)
 */

const SELECTORS = {
	// ── WordPress Admin (global) ──
	admin: {
		loginForm: { user: '#user_login', pass: '#user_pass', submit: '#wp-submit' },
		adminBar: '#wpadminbar',
		addNewButton: ['a.page-title-action', '[data-testid="add-new-button"]'],
		publishButton: ['#publish', '[data-testid="publish-button"]', 'button.editor-post-publish-button__button', 'button.editor-post-publish-button'],
		saveDraftButton: ['#save-post', 'button.editor-post-save-draft'],
	},

	// ── Assistant Editor ──
	assistant: {
		titleInput: ['#title', '[data-testid="assistant-title-input"]'],
		contentArea: ['#content', '.wp-block-post-content', '[data-testid="assistant-description"]', 'textarea[name="post_content"]'],
		systemPrompt: ['[data-testid="system-prompt"]', '#wp-mcp-ai-system-prompt', 'textarea[name="wp_mcp_ai_system_prompt"]', '#mcp_ai_system_prompt', 'textarea[name*="system_prompt"]'],
		modelSelect: ['[data-testid="model-select"]', '#wp-mcp-ai-model', 'select[name="wp_mcp_ai_model"]', 'select[name*="model"]'],
		toolsTab: ['[data-testid="tools-tab"]', '.nav-tab-wrapper a[href*="tools"]', 'button:has-text("Tools")', '.mcp-ai-tools-tab', 'a.nav-tab:has-text("Tools")', '.components-tab-panel__tabs button:has-text("Tools")'],
		toolSearchInput: ['[data-testid="tools-search-input"]', 'input[type="search"]', 'input[placeholder*="search" i]', 'input[placeholder*="Search"]', '.mcp-ai-tool-search input'],
		toolCheckboxes: ['[data-testid="tool-toggle"]', 'input[type="checkbox"]'],
	},

	// ── Chat UI ──
	chat: {
		input: ['[data-testid="chat-input"]', 'textarea[placeholder*="message" i]', 'textarea[placeholder*="Message"]', '#mcp-ai-chat-input', '.mcp-ai-chat-input textarea', '.chat-input textarea', '.chat-input-container textarea', '[role="textbox"]'],
		sendButton: ['[data-testid="send-button"]', 'button[type="submit"]', 'button[aria-label*="send" i]', 'button[aria-label*="Send"]', '.mcp-ai-send-button', '.chat-submit button', '.send-message-button'],
		response: ['[data-testid="assistant-message"]', '.mcp-ai-message-assistant', '.chat-message.assistant', '.assistant-message', '[data-role="assistant"]', '.message.assistant', '.mcp-ai-response'],
		toolIndicator: ['[data-testid="tool-execution"]', '.mcp-ai-tool-call', '.tool-execution-indicator', '[data-role="tool-call"]', '.tool-call', '.mcp-ai-tool-status'],
		guestBadge: ['[data-testid="guest-badge"]', '.guest-badge', '.guest-mode-indicator', '[data-role="guest"]', '.mcp-ai-guest-notice'],
		loginPrompt: ['[data-testid="login-prompt"]', '.mcp-ai-login-prompt', '.guest-login-prompt', 'a[href*="wp-login"]'],
	},

	// ── AI Provider Settings ──
	provider: {
		apiKeyInput: ['[data-testid="openai-api-key"]', '#wp_mcp_ai_openai_api_key', 'input[name="wp_mcp_ai_openai_api_key"]'],
		modelSelect: ['[data-testid="default-model"]', 'select[name*="default_model"]'],
		providerSelect: ['[data-testid="default-provider"]', 'select[name*="default_provider"]'],
		testConnectionButton: ['[data-testid="test-connection"]', 'button:has-text("Test")', '.test-connection-button'],
		saveButton: ['[data-testid="save-settings"]', '#submit', 'input[type="submit"]'],
		aiProvidersTab: ['[data-testid="tab-ai-providers"]', '.nav-tab-wrapper a[href*="ai_providers"]', 'a.nav-tab:has-text("AI Providers")'],
	},

	// ── Tools Manager ──
	toolsManager: {
		categoryTabs: ['[data-testid="category-tab"]', '.nav-tab-wrapper a', '.nav-tab', '.mcp-ai-tool-category', '.tool-category-filter button', '.components-tab-panel__tabs button', '.tool-categories a'],
		toolRows: ['[data-testid="tool-row"]', '.mcp-ai-tool-row', 'tr[data-tool-slug]', '.tool-item', '.tool-list-item'],
		toggles: ['[data-testid="tool-toggle"]', 'input[type="checkbox"]', '.mcp-ai-toggle input', '.toggle-switch input', '.tool-enable-checkbox'],
		searchInput: ['[data-testid="tool-search"]', 'input[type="search"]', 'input[placeholder*="search" i]', 'input[placeholder*="Search"]', '#tool-search', '.mcp-ai-tool-search input', '.tool-filter-search input'],
		searchClear: ['[data-testid="search-clear"]', '.search-clear', '.mcp-ai-search-clear', 'button[aria-label*="clear" i]'],
	},

	// ── Tool Presets ──
	presets: {
		list: ['[data-testid="preset-list"]', '.mcp-ai-preset', '.tool-preset-card'],
		nameInput: ['[data-testid="preset-name"]', 'input[name*="preset_name"]', '#preset-name'],
		saveButton: ['[data-testid="save-preset"]', 'button:has-text("Save")', 'input[type="submit"]', '.button-primary'],
	},

	// ── Profession Editor ──
	profession: {
		titleInput: ['[data-testid="profession-title"]', '#title'],
		descriptionInput: ['[data-testid="profession-description"]', '#content'],
		systemPromptTemplate: ['[data-testid="profession-system-prompt"]', 'textarea[name*="system_prompt"]'],
		presetSelect: ['[data-testid="profession-preset"]', 'select[name*="tool_preset"]', 'select[name*="preset"]'],
	},

	// ── Pro Plugin Pages ──
	pro: {
		dashboard: { analyticsTab: '[data-testid="tab-analytics"]', usageTab: '[data-testid="tab-usage"]', chartTokenUsage: '[data-testid="chart-token-usage"]' },
		orchestration: { createWorkflowButton: '[data-testid="create-workflow"]', addAgentButton: '[data-testid="add-agent"]' },
		securityAudit: { startButton: '[data-testid="start-audit-button"]', resultsPanel: '[data-testid="audit-results"]' },
		siteCreator: { templateSelect: '[data-testid="template-select"]', deployButton: '[data-testid="deploy-site"]' },
		federation: { addRemoteButton: '[data-testid="add-remote"]' },
		scheduleManager: { createScheduleButton: '[data-testid="create-schedule"]' },
		workflowBuilder: { canvas: '[data-testid="workflow-canvas"]' },
		blueprints: { exportButton: '[data-testid="export-blueprint"]', importButton: '[data-testid="import-blueprint"]' },
	},
};

/**
 * Resolve a selector array to its first (most preferred) selector.
 *
 * @param {string[]} selectorArray
 * @returns {string}
 */
function preferredSelector(selectorArray) {
	return Array.isArray(selectorArray) ? selectorArray[0] : selectorArray;
}

/**
 * Find and click an element using fallback selectors.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @returns {Promise<boolean>} True if clicked.
 */
async function tryClick(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try { await el.click(); return true; } catch { /* next */ }
		}
	}
	return false;
}

/**
 * Find and fill a field using fallback selectors.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @param {string} text
 * @returns {Promise<boolean>}
 */
async function tryFill(page, selectors, text) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) {
			try { await el.fill(text); return true; } catch { /* next */ }
		}
	}
	return false;
}

/**
 * Find the first matching element from fallback selectors.
 *
 * @param {import('playwright').Page} page
 * @param {string[]} selectors
 * @returns {Promise<import('playwright').ElementHandle|null>}
 */
async function findElement(page, selectors) {
	for (const sel of selectors) {
		const el = await page.$(sel);
		if (el) return el;
	}
	return null;
}

module.exports = { SELECTORS, preferredSelector, tryClick, tryFill, findElement };
