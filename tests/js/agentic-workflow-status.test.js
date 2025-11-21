/**
 * Test for agentic workflow status transitions
 * 
 * Tests that status properly transitions through the various states in an
 * agentic workflow: thinking -> tool execution -> streaming -> thinking -> streaming
 *
 * @package WP_MCP_AI
 */

// Import the UI utilities service
import '../../assets/js/chat-ui-utilities-service.js';

describe('Agentic Workflow Status Transitions', () => {
	let container;
	let statusEl;

	beforeEach(() => {
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		
		const messagesEl = document.createElement('div');
		messagesEl.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(messagesEl);
		
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

	it('should handle multi-step agentic workflow with multiple thinking phases', () => {
		// Step 1: Initial thinking
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Analyzing your request...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);

		// Step 2: First response streaming
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'I need to search for information...',
			type: 'text-stream',
			showTime: false
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Step 3: Tool execution (agent calls a tool)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Executing tools: search_web',
			type: 'processing',
			showTime: true,
			startTime: Date.now()
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(true);

		// Step 4: Second thinking phase (analyzing tool results)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Processing search results...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--processing')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);

		// Step 5: Final response streaming
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Based on the search results, I found that...',
			type: 'text-stream',
			showTime: false
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);
	});

	it('should allow thinking status to update during agentic workflow even after initial streaming', () => {
		// Initial streaming
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'First response...',
			type: 'text-stream',
			showTime: false
		});
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(true);

		// Agent realizes it needs to think more (legitimate transition)
		window.wpMcpAiChatUIUtils.setStatus(container, {
			message: 'Let me reconsider this approach...',
			type: 'thinking',
			showTime: true,
			startTime: Date.now()
		});
		
		// This SHOULD be allowed in agentic workflows
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--text-stream')).toBe(false);
		expect(statusEl.classList.contains('wp-mcp-ai-chat__status--thinking')).toBe(true);
	});

	it('should handle rapid transitions in complex agentic workflows', () => {
		const workflow = [
			{ message: 'Initial request analysis', type: 'thinking' },
			{ message: 'Let me search for this...', type: 'text-stream' },
			{ message: 'Executing tools: search', type: 'processing' },
			{ message: 'Analyzing search results...', type: 'thinking' },
			{ message: 'I found several options...', type: 'text-stream' },
			{ message: 'Executing tools: compare', type: 'processing' },
			{ message: 'Comparing the options...', type: 'thinking' },
			{ message: 'Here is my recommendation...', type: 'text-stream' }
		];

		workflow.forEach((step, index) => {
			window.wpMcpAiChatUIUtils.setStatus(container, {
				message: step.message,
				type: step.type,
				showTime: step.type !== 'text-stream',
				startTime: Date.now()
			});

			// Verify current state
			expect(statusEl.classList.contains(`wp-mcp-ai-chat__status--${step.type}`)).toBe(true);
			
			// Verify previous states are cleared
			workflow.slice(0, index).forEach((prevStep) => {
				if (prevStep.type !== step.type) {
					expect(statusEl.classList.contains(`wp-mcp-ai-chat__status--${prevStep.type}`)).toBe(false);
				}
			});
		});
	});
});
