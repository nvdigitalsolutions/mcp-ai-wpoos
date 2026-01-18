/**
 * Test suite for research page CPT action event handling.
 * Verifies that native CustomEvents are properly caught by the event listeners.
 */

describe('Research Page CPT Action Events', () => {
	let container;
	let eventReceived;
	let eventDetail;

	beforeEach(() => {
		// Create a test container that mimics the chat container
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		document.body.appendChild(container);

		// Reset test state
		eventReceived = false;
		eventDetail = null;

		// Add native event listener to simulate research-page.js
		document.addEventListener('wp-mcp-ai-cpt-action', (e) => {
			eventReceived = true;
			eventDetail = e.detail;
		});
	});

	afterEach(() => {
		// Clean up
		document.body.removeChild(container);
		document.removeEventListener('wp-mcp-ai-cpt-action', () => {});
	});

	test('should dispatch and receive CustomEvent with detail', () => {
		// Create and dispatch the event (simulates chat.js behavior)
		const testDetail = {
			action: 'create_quiz',
			conversation: { test: 'data' },
			state: { id: 'test-state' },
			button: document.createElement('button'),
		};

		const event = new CustomEvent('wp-mcp-ai-cpt-action', {
			bubbles: true,
			detail: testDetail,
		});

		container.dispatchEvent(event);

		// Verify the event was received
		expect(eventReceived).toBe(true);
		expect(eventDetail).toEqual(testDetail);
		expect(eventDetail.action).toBe('create_quiz');
	});

	test('should bubble from container to document', () => {
		// Create a nested structure to test bubbling
		const innerDiv = document.createElement('div');
		innerDiv.className = 'wp-mcp-ai-chat__messages';
		container.appendChild(innerDiv);

		const testDetail = {
			action: 'create_place',
			conversation: {},
			state: {},
			button: null,
		};

		const event = new CustomEvent('wp-mcp-ai-cpt-action', {
			bubbles: true,
			detail: testDetail,
		});

		// Dispatch from inner element
		innerDiv.dispatchEvent(event);

		// Should still be received at document level
		expect(eventReceived).toBe(true);
		expect(eventDetail.action).toBe('create_place');
	});

	test('should handle all CPT action types', () => {
		const actionTypes = [
			'create_quiz',
			'create_place',
			'create_eca',
			'create_policy',
			'create_post',
			'create_page',
		];

		actionTypes.forEach((actionType) => {
			eventReceived = false;
			eventDetail = null;

			const event = new CustomEvent('wp-mcp-ai-cpt-action', {
				bubbles: true,
				detail: {
					action: actionType,
					conversation: {},
					state: {},
					button: null,
				},
			});

			container.dispatchEvent(event);

			expect(eventReceived).toBe(true);
			expect(eventDetail.action).toBe(actionType);
		});
	});

	test('should not receive event if bubbles is false', () => {
		const event = new CustomEvent('wp-mcp-ai-cpt-action', {
			bubbles: false, // Should not bubble to document
			detail: {
				action: 'create_quiz',
			},
		});

		container.dispatchEvent(event);

		// Event should not reach document listener
		expect(eventReceived).toBe(false);
	});

	test('should handle event with complete conversation data', () => {
		const fullConversation = {
			lastAssistantMessage: 'Test quiz data',
			fullText: 'Full conversation text',
			toolResults: {
				research_quiz_topic: {
					success: true,
					title: 'Test Quiz',
					questions: [
						{
							question: 'Question 1?',
							options: { a: 'Answer A', b: 'Answer B' },
							correct_answer: 'a',
						},
					],
				},
			},
		};

		const event = new CustomEvent('wp-mcp-ai-cpt-action', {
			bubbles: true,
			detail: {
				action: 'create_quiz',
				conversation: fullConversation,
				state: {},
				button: document.createElement('button'),
			},
		});

		container.dispatchEvent(event);

		expect(eventReceived).toBe(true);
		expect(eventDetail.conversation).toEqual(fullConversation);
		expect(eventDetail.conversation.toolResults.research_quiz_topic.success).toBe(true);
	});
});

describe('Research Page Tool Result Events', () => {
	let container;
	let eventReceived;
	let eventDetail;

	beforeEach(() => {
		container = document.createElement('div');
		container.className = 'wp-mcp-ai-chat';
		document.body.appendChild(container);

		eventReceived = false;
		eventDetail = null;

		document.addEventListener('wp-mcp-ai-tool-result-stored', (e) => {
			eventReceived = true;
			eventDetail = e.detail;
		});
	});

	afterEach(() => {
		document.body.removeChild(container);
		document.removeEventListener('wp-mcp-ai-tool-result-stored', () => {});
	});

	test('should dispatch and receive tool result event', () => {
		const testResult = {
			success: true,
			title: 'Test Quiz',
			difficulty: 'intermediate',
			questions: [],
		};

		const event = new CustomEvent('wp-mcp-ai-tool-result-stored', {
			bubbles: true,
			detail: {
				toolName: 'research_quiz_topic',
				result: testResult,
			},
		});

		container.dispatchEvent(event);

		expect(eventReceived).toBe(true);
		expect(eventDetail.toolName).toBe('research_quiz_topic');
		expect(eventDetail.result).toEqual(testResult);
	});

	test('should handle research_quiz_topic tool result', () => {
		const quizData = {
			success: true,
			title: 'World War II Quiz',
			difficulty: 'intermediate',
			time_limit: 30,
			pass_score: 70,
			questions: [
				{
					question: 'When did WWII start?',
					options: {
						a: '1939',
						b: '1940',
						c: '1941',
						d: '1942',
					},
					correct_answer: 'a',
				},
			],
		};

		const event = new CustomEvent('wp-mcp-ai-tool-result-stored', {
			bubbles: true,
			detail: {
				toolName: 'research_quiz_topic',
				result: quizData,
			},
		});

		container.dispatchEvent(event);

		expect(eventReceived).toBe(true);
		expect(eventDetail.result.title).toBe('World War II Quiz');
		expect(eventDetail.result.questions).toHaveLength(1);
	});
});
