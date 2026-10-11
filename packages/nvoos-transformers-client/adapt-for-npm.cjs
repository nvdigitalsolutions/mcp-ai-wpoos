// Adaptation script: Convert WordPress plugin code to standalone NPM package
// This script removes WordPress-specific code and makes the module framework-agnostic

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting transformers-tasks-client.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'transformers-tasks-client.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Update log prefix
console.log('   → Updating log prefixes');
code = code.replace(/\[NV oOS Transformers\]/g, '[nvoos-transformers-client]');
code = code.replace(/\[NV oOS\] Transformers\.js client ready[^']*/g, '[nvoos-transformers-client] Ready');

// Step 2: Make the transformers module URL configurable so consumers can use a
// bundled `import` instead of the CDN.
console.log('   → Making transformers module source configurable');
code = code.replace(
  /\/\/ Load from jsdelivr CDN[\s\S]*?const module = await import\( 'https:\/\/cdn\.jsdelivr\.net\/npm\/@huggingface\/transformers@3\.8\.1' \);/,
  `// Load from configured source. Defaults to the CDN-hosted v3 build but can
			// be overridden via configure({ transformersImporter }) so consumers can
			// bundle their own copy of @huggingface/transformers.
			let importer = this.config.transformersImporter;
			if ( ! importer ) {
				if ( ! this.config.transformersUrl ) {
					throw new Error( 'nvoos-transformers-client: transformersUrl is not configured. Set it via the constructor or configure({ transformersUrl }), or supply a transformersImporter.' );
				}
				const url = this.config.transformersUrl;
				importer = () => import( /* @vite-ignore */ url );
			}
			const module = await importer();`
);

// Step 3: Rename class
console.log('   → Renaming class for clean public API');
code = code.replace(/class WP_MCP_AI_TransformersTasksClient \{/, 'class TransformersTasksClient {');
code = code.replace(/WP_MCP_AI_TransformersTasksClient/g, 'TransformersTasksClient');

// Step 4: Add config + configure() inside constructor
console.log('   → Adding configure() method');
code = code.replace(
  /constructor\(\) \{\s*this\.pipelines = new Map\(\);/,
  `constructor( options = {} ) {
		this.config = {
			transformersUrl: options.transformersUrl || 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1',
			transformersImporter: options.transformersImporter || null,
			device: options.device || null,
			dtype: options.dtype || 'q8',
			models: Object.assign( {}, options.models || {} )
		};
		this.pipelines = new Map();`
);

// Replace the hard-coded models map so caller overrides via config.models take effect
code = code.replace(
  /this\.models = \{\s*summarization: 'Xenova\/distilbart-cnn-6-6',[\s\S]*?embedding: 'Xenova\/all-MiniLM-L6-v2'\s*\};/,
  `this.models = Object.assign( {
			summarization: 'Xenova/distilbart-cnn-6-6',
			sentiment: 'Xenova/distilbert-base-uncased-finetuned-sst-2-english',
			ner: 'Xenova/bert-base-NER',
			translation: 'Xenova/nllb-200-distilled-600M',
			qa: 'Xenova/distilbert-base-uncased-distilled-squad',
			embedding: 'Xenova/all-MiniLM-L6-v2'
		}, this.config.models );`
);

// Honor configured device + dtype in getPipeline
code = code.replace(
  /\/\/ Detect the best available device\s*const device = await this\.detectDevice\(\);/,
  `// Honor explicitly configured device, otherwise auto-detect.
		const device = this.config.device || await this.detectDevice();`
);
code = code.replace(
  /dtype: 'q8',/,
  `dtype: this.config.dtype,`
);

// Add configure() method after constructor's log line
const configureMethod = `

	/**
	 * Update configuration after construction.
	 *
	 * @param {Object} options
	 * @param {string} [options.transformersUrl]
	 * @param {Function} [options.transformersImporter]
	 * @param {string} [options.device] 'webgpu' | 'wasm' | null (auto)
	 * @param {string} [options.dtype]
	 * @param {Object} [options.models]
	 */
	configure( options = {} ) {
		if ( options.transformersUrl ) {
			this.config.transformersUrl = options.transformersUrl;
		}
		if ( options.transformersImporter ) {
			this.config.transformersImporter = options.transformersImporter;
		}
		if ( typeof options.device !== 'undefined' ) {
			this.config.device = options.device;
			this.device = options.device || null;
		}
		if ( options.dtype ) {
			this.config.dtype = options.dtype;
		}
		if ( options.models ) {
			Object.assign( this.models, options.models );
		}
	}
`;

code = code.replace(
  /(this\.log\(\s*'TransformersTasksClient initialized'\s*\);\s*\})/,
  '$1' + configureMethod
);

// Step 5: Strip the trailing global-window initialization block.
console.log('   → Removing window globals initialization');
code = code.replace(
  /\/\/ Initialize global instance\s*if \( typeof window !== 'undefined' \) \{[\s\S]*?\}\s*$/m,
  ''
);

// Step 6: ES module exports
code = code.trim() + '\n\n// ES Module exports\nexport { TransformersTasksClient };\nexport default TransformersTasksClient;\n';

// Step 7: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

const outputFile = path.join(distDir, 'nvoos-transformers-client.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-transformers-client.js');

// Step 8: TypeScript definitions
const dtsContent = `/**
 * HuggingFace Transformers.js task wrapper.
 * @package @nvdigitalsolutions/nvoos-transformers-client
 */

export interface TransformersTasksClientOptions {
  transformersUrl?: string;
  transformersImporter?: () => Promise<unknown>;
  device?: 'webgpu' | 'wasm' | null;
  dtype?: string;
  models?: Partial<TransformersModelMap>;
}

export interface TransformersModelMap {
  summarization: string;
  sentiment: string;
  ner: string;
  translation: string;
  qa: string;
  embedding: string;
  [key: string]: string;
}

export interface SummarizeOptions {
  maxLength?: number;
  minLength?: number;
}

export interface SummarizeResult {
  success: true;
  summary: string;
  originalLength: number;
  summaryLength: number;
}

export interface SentimentResult {
  success: true;
  label: string;
  score: number;
  confidence: string;
}

export interface NamedEntity {
  text: string;
  type: string;
  score: number;
}

export interface ExtractEntitiesResult {
  success: true;
  entities: NamedEntity[];
  count: number;
}

export interface TranslateOptions {
  sourceLang?: string;
  targetLang?: string;
}

export interface TranslateResult {
  success: true;
  translatedText: string;
  sourceLang: string;
  targetLang: string;
}

export interface QuestionAnsweringResult {
  success: true;
  answer: string;
  score: number;
  confidence: string;
  start: number;
  end: number;
}

export interface EmbedResult {
  success: true;
  embeddings: number[][];
  dimensions: number;
}

export declare class TransformersTasksClient {
  constructor(options?: TransformersTasksClientOptions);
  configure(options: TransformersTasksClientOptions): void;
  detectDevice(): Promise<'webgpu' | 'wasm'>;
  loadTransformers(): Promise<unknown>;
  getPipeline(task: string, model: string): Promise<unknown>;
  summarize(text: string, options?: SummarizeOptions): Promise<SummarizeResult>;
  sentiment(text: string): Promise<SentimentResult>;
  extractEntities(text: string): Promise<ExtractEntitiesResult>;
  translate(text: string, options?: TranslateOptions): Promise<TranslateResult>;
  questionAnswering(question: string, context: string): Promise<QuestionAnsweringResult>;
  embed(text: string | string[]): Promise<EmbedResult>;
  isTaskAvailable(task: string): boolean;
  getAvailableTasks(): string[];
  clearCache(): void;
}

export default TransformersTasksClient;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-transformers-client.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
