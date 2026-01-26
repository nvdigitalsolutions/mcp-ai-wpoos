/**
 * @jest-environment jsdom
 */

/**
 * Tests for Transformers Tools Integration
 */

// Mock the global window objects
global.window = global;
global.wpMcpAiTransformers = {
	enabled: true,
	autoInit: false, // Don't auto-init in tests
	debug: false,
	semanticSearchEnabled: true,
	features: {
		summarization: true,
		sentiment: true,
		ner: true,
		embedding: true,
		translation: true,
		questionAnswering: true,
		zeroShot: true,
	},
};

// Mock Transformers client
const mockClient = {
	summarize: jest.fn().mockResolvedValue({
		summary: 'Test summary',
		originalLength: 100,
		summaryLength: 20,
	}),
	analyzeSentiment: jest.fn().mockResolvedValue({
		label: 'positive',
		score: 0.95,
		confidence: '95.00%',
	}),
	extractEntities: jest.fn().mockResolvedValue({
		PER: [{ text: 'John Doe', score: 0.98 }],
		ORG: [{ text: 'Acme Corp', score: 0.97 }],
	}),
	embed: jest.fn().mockResolvedValue([0.1, 0.2, 0.3]),
	translate: jest.fn().mockResolvedValue('Bonjour'),
	answerQuestion: jest.fn().mockResolvedValue({
		answer: 'Paris',
		score: 0.98,
		confidence: '98.00%',
	}),
	classify: jest.fn().mockResolvedValue([
		{ label: 'positive', score: 0.9, confidence: '90.00%' },
		{ label: 'negative', score: 0.1, confidence: '10.00%' },
	]),
};

// Mock vector store
const mockVectorStore = {
	initialize: jest.fn().mockResolvedValue(),
	search: jest.fn().mockResolvedValue([
		{ text: 'Doc 1', score: 0.95, similarity: '95.00%' },
		{ text: 'Doc 2', score: 0.85, similarity: '85.00%' },
	]),
};

global.WP_MCP_AI_Transformers = mockClient;
global.WP_MCP_AI_ClientVectorStore = jest.fn(() => mockVectorStore);

describe('TransformersToolsIntegration', () => {
	let integration;

	beforeAll(() => {
		// Load the integration module once
		require('../../assets/js/transformers-tools-integration.js');
	});

	beforeEach(() => {
		// Clear mocks
		jest.clearAllMocks();

		// Get the integration instance
		integration = global.WP_MCP_AI_TransformersTools;
		
		// Initialize before each test
		if (!integration.isInitialized) {
		}
	});

	describe('Initialization', () => {
		test('should create integration instance', () => {
			expect(integration).toBeDefined();
			expect(integration.tools).toBeDefined();
		});

		test('should register tools', async () => {
			expect(integration.tools.size).toBeGreaterThan(0);
		});

		test('should have all 7 tools when fully enabled', async () => {
			const expectedTools = [
				'client_summarize_text',
				'client_analyze_sentiment',
				'client_extract_entities',
				'client_semantic_search',
				'client_translate_text',
				'client_question_answering',
				'client_classify_text',
			];
			
			expectedTools.forEach((slug) => {
				expect(integration.hasTool(slug)).toBe(true);
			});
		});
	});

	describe('Tool Registration', () => {
		beforeEach(() => {
		});

		test('should get tool by slug', () => {
			const tool = integration.getTool('client_summarize_text');
			expect(tool).toBeDefined();
			expect(tool.name).toBe('Summarize Text (Browser)');
		});

		test('should return null for non-existent tool', () => {
			const tool = integration.getTool('non_existent_tool');
			expect(tool).toBeNull();
		});

		test('should list all tools', () => {
			const allTools = integration.getAllTools();
			expect(Array.isArray(allTools)).toBe(true);
			expect(allTools.length).toBeGreaterThan(0);
			expect(allTools[0]).toHaveProperty('slug');
			expect(allTools[0]).toHaveProperty('name');
			expect(allTools[0]).toHaveProperty('description');
		});
	});

	describe('Tool Execution - Summarization', () => {
		beforeEach(() => {
		});

		test('should execute summarize tool', async () => {
			const result = await integration.executeTool('client_summarize_text', {
				text: 'Long text to summarize...',
			});

			expect(result.success).toBe(true);
			expect(result.summary).toBe('Test summary');
			expect(mockClient.summarize).toHaveBeenCalledWith(
				'Long text to summarize...',
				expect.objectContaining({
					maxLength: 130,
					minLength: 30,
				})
			);
		});

		test('should accept custom length parameters', async () => {
			await integration.executeTool('client_summarize_text', {
				text: 'Text',
				max_length: 100,
				min_length: 20,
			});

			expect(mockClient.summarize).toHaveBeenCalledWith(
				'Text',
				expect.objectContaining({
					maxLength: 100,
					minLength: 20,
				})
			);
		});
	});

	describe('Tool Execution - Sentiment', () => {
		beforeEach(() => {
		});

		test('should execute sentiment analysis tool', async () => {
			const result = await integration.executeTool('client_analyze_sentiment', {
				text: 'Great product!',
			});

			expect(result.success).toBe(true);
			expect(result.sentiment).toBe('positive');
			expect(result.confidence).toBe('95.00%');
			expect(mockClient.analyzeSentiment).toHaveBeenCalledWith('Great product!');
		});
	});

	describe('Tool Execution - NER', () => {
		beforeEach(() => {
		});

		test('should execute entity extraction tool', async () => {
			const result = await integration.executeTool('client_extract_entities', {
				text: 'John Doe works at Acme Corp',
			});

			expect(result.success).toBe(true);
			expect(result.entities).toBeDefined();
			expect(result.count).toBe(2);
			expect(mockClient.extractEntities).toHaveBeenCalled();
		});
	});

	describe('Tool Execution - Semantic Search', () => {
		beforeEach(() => {
		});

		test('should execute semantic search tool', async () => {
			const result = await integration.executeTool('client_semantic_search', {
				query: 'test query',
			});

			expect(result.success).toBe(true);
			expect(result.results).toBeDefined();
			expect(result.count).toBe(2);
			expect(mockVectorStore.search).toHaveBeenCalledWith(
				'test query',
				5,
				{ minScore: 0 }
			);
		});

		test('should accept custom parameters', async () => {
			await integration.executeTool('client_semantic_search', {
				query: 'test',
				limit: 10,
				min_score: 0.5,
			});

			expect(mockVectorStore.search).toHaveBeenCalledWith(
				'test',
				10,
				{ minScore: 0.5 }
			);
		});
	});

	describe('Tool Execution - Translation', () => {
		beforeEach(() => {
		});

		test('should execute translation tool', async () => {
			const result = await integration.executeTool('client_translate_text', {
				text: 'Hello',
				target_language: 'fr',
			});

			expect(result.success).toBe(true);
			expect(result.translated_text).toBe('Bonjour');
			expect(mockClient.translate).toHaveBeenCalledWith('Hello', 'fr');
		});
	});

	describe('Tool Execution - Question Answering', () => {
		beforeEach(() => {
		});

		test('should execute QA tool', async () => {
			const result = await integration.executeTool('client_question_answering', {
				question: 'What is the capital?',
				context: 'The capital of France is Paris.',
			});

			expect(result.success).toBe(true);
			expect(result.answer).toBe('Paris');
			expect(mockClient.answerQuestion).toHaveBeenCalled();
		});
	});

	describe('Tool Execution - Classification', () => {
		beforeEach(() => {
		});

		test('should execute classification tool', async () => {
			const result = await integration.executeTool('client_classify_text', {
				text: 'Great product!',
				labels: ['positive', 'negative', 'neutral'],
			});

			expect(result.success).toBe(true);
			expect(result.classifications).toBeDefined();
			expect(result.top_label).toBe('positive');
			expect(mockClient.classify).toHaveBeenCalled();
		});
	});

	describe('Error Handling', () => {
		beforeEach(() => {
		});

		test('should throw error for non-existent tool', async () => {
			await expect(
				integration.executeTool('non_existent', {})
			).rejects.toThrow('Tool not found');
		});

		test('should handle client errors', async () => {
			mockClient.summarize.mockRejectedValueOnce(new Error('Client error'));
			
			await expect(
				integration.executeTool('client_summarize_text', { text: 'test' })
			).rejects.toThrow();
		});
	});
});
