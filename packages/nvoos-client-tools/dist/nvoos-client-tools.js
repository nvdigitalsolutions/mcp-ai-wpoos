
let pipelineFactory = null;

/**
 * Configure the client tools registry.
 *
 * @param {Object} options
 * @param {Function} [options.pipeline] - Transformers.js pipeline factory.
 *   Typically: `import { pipeline } from '@huggingface/transformers'`
 *   or a globally-loaded CDN copy.
 */
export function configure(options) {
	options = options || {};
	if (typeof options.pipeline === 'function') {
		pipelineFactory = options.pipeline;
	}
}

function getPipeline() {
	if (typeof pipelineFactory === 'function') {
		return pipelineFactory;
	}
	if (typeof globalThis !== 'undefined' && typeof globalThis.pipeline === 'function') {
		return globalThis.pipeline;
	}
	throw new Error(
		'nvoos-client-tools: pipeline factory is not configured. ' +
		'Call configure({ pipeline }) with the Transformers.js pipeline function before executing tools.'
	);
}

/**
 * Browser-Native AI Tools using Transformers.js
 *
 * Provides client-side AI capabilities without server processing,
 * enabling privacy-first AI operations in the browser.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */




	/**
	 * Client-executable tools using Transformers.js
	 *
	 * These tools run entirely in the browser using pre-trained models
	 * from Hugging Face, providing fast, private AI processing.
	 */
	const CLIENT_TOOLS = {
		/**
		 * Text Summarization
		 *
		 * Summarizes long text into concise summaries using DistilBART.
		 */
		client_summarize: {
			name: 'client_summarize',
			description: 'Summarize text using in-browser AI (fast, private)',
			parameters: {
				type: 'object',
				properties: {
					text: {
						type: 'string',
						description: 'The text to summarize',
					},
					max_length: {
						type: 'number',
						description: 'Maximum length of summary (default: 150)',
						default: 150,
					},
					min_length: {
						type: 'number',
						description: 'Minimum length of summary (default: 30)',
						default: 30,
					},
				},
				required: ['text'],
			},
			execute: async function(args) {
				const summarizer = await getPipeline()('summarization', 'Xenova/distilbart-cnn-12-6');
				const result = await summarizer(args.text, {
					max_length: args.max_length || 150,
					min_length: args.min_length || 30,
				});
				return result[0].summary_text;
			},
		},

		/**
		 * Sentiment Analysis
		 *
		 * Analyzes sentiment (positive/negative/neutral) of text.
		 */
		client_sentiment: {
			name: 'client_sentiment',
			description: 'Analyze sentiment (positive/negative/neutral) in browser',
			parameters: {
				type: 'object',
				properties: {
					text: {
						type: 'string',
						description: 'The text to analyze',
					},
				},
				required: ['text'],
			},
			execute: async function(args) {
				const classifier = await getPipeline()('sentiment-analysis');
				const result = await classifier(args.text);
				return result[0];
			},
		},

		/**
		 * Text Translation
		 *
		 * Translates text between languages using NLLB-200.
		 */
		client_translate: {
			name: 'client_translate',
			description: 'Translate text between languages in browser',
			parameters: {
				type: 'object',
				properties: {
					text: {
						type: 'string',
						description: 'The text to translate',
					},
					from: {
						type: 'string',
						description: 'Source language code (e.g., "eng_Latn")',
					},
					to: {
						type: 'string',
						description: 'Target language code (e.g., "spa_Latn")',
					},
				},
				required: ['text', 'from', 'to'],
			},
			execute: async function(args) {
				const translator = await getPipeline()('translation', 'Xenova/nllb-200-distilled-600M');
				const result = await translator(args.text, {
					src_lang: args.from,
					tgt_lang: args.to,
				});
				return result[0].translation_text;
			},
		},

		/**
		 * Text Embeddings
		 *
		 * Generates embeddings for semantic search and similarity.
		 */
		client_embed: {
			name: 'client_embed',
			description: 'Generate text embeddings for semantic search',
			parameters: {
				type: 'object',
				properties: {
					text: {
						type: 'string',
						description: 'The text to embed',
					},
				},
				required: ['text'],
			},
			execute: async function(args) {
				const embedder = await getPipeline()('feature-extraction', 'Xenova/all-MiniLM-L6-v2');
				const embedding = await embedder(args.text, {
					pooling: 'mean',
					normalize: true,
				});
				return Array.from(embedding.data);
			},
		},

		/**
		 * Image Captioning
		 *
		 * Describes what is in an image using vision-language models.
		 */
		client_describe_image: {
			name: 'client_describe_image',
			description: 'Describe what is in an image using browser AI',
			parameters: {
				type: 'object',
				properties: {
					image_url: {
						type: 'string',
						description: 'URL of the image to describe',
					},
				},
				required: ['image_url'],
			},
			execute: async function(args) {
				const captioner = await getPipeline()('image-to-text', 'Xenova/vit-gpt2-image-captioning');
				const result = await captioner(args.image_url);
				return result[0].generated_text;
			},
		},

		/**
		 * Object Detection
		 *
		 * Detects objects in images with bounding boxes.
		 */
		client_detect_objects: {
			name: 'client_detect_objects',
			description: 'Detect objects in images with bounding boxes',
			parameters: {
				type: 'object',
				properties: {
					image_url: {
						type: 'string',
						description: 'URL of the image to analyze',
					},
					threshold: {
						type: 'number',
						description: 'Detection confidence threshold (0-1, default: 0.5)',
						default: 0.5,
					},
				},
				required: ['image_url'],
			},
			execute: async function(args) {
				const detector = await getPipeline()('object-detection', 'Xenova/detr-resnet-50');
				const result = await detector(args.image_url, {
					threshold: args.threshold || 0.5,
				});
				return result;
			},
		},

		/**
		 * Audio Transcription
		 *
		 * Transcribes audio to text using Whisper.
		 */
		client_transcribe_audio: {
			name: 'client_transcribe_audio',
			description: 'Transcribe audio to text using browser AI',
			parameters: {
				type: 'object',
				properties: {
					audio_url: {
						type: 'string',
						description: 'URL of the audio file to transcribe',
					},
					language: {
						type: 'string',
						description: 'Language code (e.g., "en", default: auto-detect)',
					},
				},
				required: ['audio_url'],
			},
			execute: async function(args) {
				const transcriber = await getPipeline()('automatic-speech-recognition', 'Xenova/whisper-tiny.en');
				const result = await transcriber(args.audio_url);
				return result.text;
			},
		},
	};

/**
 * Get the full tool registry as an object keyed by tool name.
 * @returns {Object} Map of tool name → tool definition
 */
export function getTools() {
	return CLIENT_TOOLS;
}

/**
 * Get a single tool definition by name.
 * @param {string} name
 * @returns {Object|null}
 */
export function getTool(name) {
	if (!name) return null;
	return Object.prototype.hasOwnProperty.call(CLIENT_TOOLS, name) ? CLIENT_TOOLS[name] : null;
}

/**
 * Execute a tool by name with the provided arguments.
 * @param {string} name - Tool name (e.g. 'client_summarize')
 * @param {Object} args - Tool arguments
 * @returns {Promise<*>} Tool result
 */
export function executeTool(name, args) {
	const tool = getTool(name);
	if (!tool || typeof tool.execute !== 'function') {
		return Promise.reject(new Error('nvoos-client-tools: unknown tool "' + name + '"'));
	}
	return Promise.resolve(tool.execute(args || {}));
}

export { CLIENT_TOOLS };

export default {
	configure: configure,
	getTools: getTools,
	getTool: getTool,
	executeTool: executeTool,
	CLIENT_TOOLS: CLIENT_TOOLS
};
