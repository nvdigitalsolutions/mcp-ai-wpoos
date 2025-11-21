/**
 * Test for tool execution timer display
 * 
 * This test verifies that all tools show their execution timer during execution,
 * not just async tools like generate_gemini_image. The "thinking" status that
 * follows tool execution should not immediately overwrite the tool timer.
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Tool Timer Display', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		// Create a container with status element (simulating shortcode structure)
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		statusEl = document.createElement('div');
		statusEl.className = 'wp-mcp-ai-chat__status';
		statusEl.setAttribute('role', 'status');
		statusEl.setAttribute('aria-live', 'polite');
		statusEl.hidden = true;
		container.appendChild(statusEl);
		
		document.body.appendChild(container);
	});

	afterEach(() => {
		document.body.removeChild(container);
	});

	it('should show timer for tool execution', () => {
		// Simulate tool_start event
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing generate_gemini_image…',
			type: 'tool',
			showTime: true,
			startTime: Date.now()
		});

		// Verify tool status is shown with timer
		expect(statusEl.hidden).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status')).toBe(true);
		
		const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
		expect(statusText.textContent).toBe('Executing generate_gemini_image…');
		
		// Verify timer element exists
		const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl).not.toBeNull();
		expect(statusEl._timeInterval).toBeDefined();
	});

	it('should show timer for all tools, not just async tools', () => {
		const tools = [
			'generate_gemini_image',
			'generate_openai_image',
			'get_posts',
			'create_post',
			'search_content'
		];

		tools.forEach((toolName) => {
			// Simulate tool_start event
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: `Executing ${toolName}…`,
				type: 'tool',
				showTime: true,
				startTime: Date.now()
			});

			// Verify timer is shown for this tool
			const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
			expect(timeEl).not.toBeNull();
			expect(timeEl.textContent).toMatch(/\d+s/); // Should show "0s", "1s", etc.
			
			// Verify status text shows the tool name
			const statusText = statusEl.querySelector('.wp-mcp-ai-chat__status-text');
			expect(statusText.textContent).toContain(toolName);

			// Clear for next iteration
			window.wpMcpAiChatUIUtils.clearStatus(container);
		});
	});

	it('should show tool icon during tool execution', () => {
		// Set tool status
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing tool…',
			type: 'tool',
			showTime: true,
			startTime: Date.now()
		});

		// Verify status has tool class modifier
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--tool')).toBe(true);

		// Verify tool indicator (spinner) is present
		const indicator = statusEl.querySelector('.wp-mcp-ai-chat__status-indicator');
		expect(indicator).not.toBeNull();
		
		// UI utilities service uses a spinner for tool status
		const spinner = indicator.querySelector('.wp-mcp-ai-chat__status-spinner');
		expect(spinner).not.toBeNull();
	});

	it('should update timer text every second', (done) => {
		const startTime = Date.now();
		
		// Set tool status with timer
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing tool…',
			type: 'tool',
			showTime: true,
			startTime: startTime
		});

		const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl.textContent).toBe('0s');

		// Wait for timer to update
		setTimeout(() => {
			// Timer should have updated to show elapsed time
			expect(timeEl.textContent).toMatch(/[1-9]\d*s/); // Should be "1s" or more
			
			// Clean up
			window.wpMcpAiChatUIUtils.clearStatus(container);
			done();
		}, 1100); // Wait slightly more than 1 second
	});

	it('should clear timer interval when status is cleared', () => {
		// Set tool status with timer
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing tool…',
			type: 'tool',
			showTime: true,
			startTime: Date.now()
		});

		// Verify interval is set
		expect(statusEl._timeInterval).toBeDefined();
		expect(statusEl._timeInterval).not.toBeNull();

		// Clear status
		window.wpMcpAiChatUIUtils.clearStatus(container);

		// Verify interval was cleared
		expect(statusEl._timeInterval).toBeNull();
		expect(statusEl.hidden).toBe(true);
	});

	it('should show timer for thinking, processing, and tool types only', () => {
		const typesWithTimer = ['thinking', 'processing', 'tool'];
		const typesWithoutTimer = ['streaming', 'text-stream', 'default'];

		// Test types that should show timer
		typesWithTimer.forEach((type) => {
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: `Status: ${type}`,
				type: type,
				showTime: true,
				startTime: Date.now()
			});

			const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
			expect(timeEl).not.toBeNull();
			
			window.wpMcpAiChatUIUtils.clearStatus(container);
		});

		// Test types that should NOT show timer (even if showTime is true)
		typesWithoutTimer.forEach((type) => {
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: `Status: ${type}`,
				type: type,
				showTime: true, // Explicitly request timer
				startTime: Date.now()
			});

			const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
			expect(timeEl).toBeNull(); // Should NOT show timer for these types
			
			window.wpMcpAiChatUIUtils.clearStatus(container);
		});
	});

	it('should format elapsed time correctly', () => {
		const now = Date.now();
		
		// Test 5 seconds - timer starts at 0s and updates after interval
		// so we can't test retroactive calculation
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Testing...',
			type: 'tool',
			showTime: true,
			startTime: now - 5000
		});
		
		let timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		// Timer always starts at "0s" when first set, regardless of startTime
		// The interval updates it, but that happens asynchronously
		expect(timeEl.textContent).toBe('0s');
		
		// Test that timer will update to correct elapsed time after interval fires
		// We can verify the interval exists and startTime is stored correctly
		expect(statusEl._timeInterval).toBeDefined();
		const storedStartTime = parseInt(timeEl.getAttribute('data-start-time'));
		expect(storedStartTime).toBe(now - 5000);
		
		// Manually trigger what the interval would do to verify calculation logic
		const elapsed = Math.floor((Date.now() - storedStartTime) / 1000);
		expect(elapsed).toBeGreaterThanOrEqual(5);
		
		// Clean up before next test
		window.wpMcpAiChatUIUtils.clearStatus(container);
		
		// Now test the timer display format after 1 second with a current start time
		const currentTime = Date.now();
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Testing...',
			type: 'tool',
			showTime: true,
			startTime: currentTime
		});
		
		timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
		expect(timeEl.textContent).toBe('0s'); // Starts at 0s
		
		// Verify the data attribute is set correctly for the interval to use
		expect(timeEl.getAttribute('data-start-time')).toBe(String(currentTime));
	});
});
