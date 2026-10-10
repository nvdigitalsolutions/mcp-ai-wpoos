// Adaptation script: Convert WordPress plugin code to standalone NPM package
// This script removes WordPress-specific code and makes the module framework-agnostic

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting progressive-model-loader.js for NPM distribution...\n');

const sourceFile = path.join(__dirname, 'progressive-model-loader.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Replace the global CreateMLCEngine reference with a configurable engine factory.
console.log('   → Replacing CreateMLCEngine global with injectable engine factory');
code = code.replace(/\/\* global CreateMLCEngine \*\//, '/* eslint-disable no-undef */');

// Replace direct CreateMLCEngine call inside loadWithUI with this.engineFactory
code = code.replace(
  /const engine = await CreateMLCEngine\(modelId, \{[\s\S]*?\}\);/,
  `if ( typeof this.engineFactory !== 'function' ) {
					throw new Error( 'nvoos-model-loader: engineFactory is not configured. Call configure({ engineFactory }) or pass via constructor.' );
				}
				const engine = await this.engineFactory(modelId, {
					initProgressCallback: (report) => {
						const progress = Math.min(95, report.progress * 95);
						this.updateProgress(ui, progress);
						this.updateDetails(ui, report.text);
					}
				});`
);

// Step 2: Update log prefix
console.log('   → Updating log prefixes');
code = code.replace(/\[Progressive Loader\]/g, '[nvoos-model-loader]');

// Step 3: Convert from IIFE to ES module
console.log('   → Converting to ES module format');
code = code.replace(/\(function\(\) \{\s*'use strict';/, '');
// Remove global window export and CommonJS export at the bottom
code = code.replace(/\/\/ Export to global scope[\s\S]*?\}\)\(\);/, '');

// Step 4: Rename class to a clean export name; add constructor options + configure().
console.log('   → Renaming class and adding configure() method');
code = code.replace(
  /class ProgressiveModelLoader \{\s*constructor\(\) \{\s*this\.loadingStages = \[/,
  `class ProgressiveModelLoader {
		constructor(options = {}) {
			this.engineFactory = options.engineFactory || null;
			this.classNames = Object.assign({
				container: 'nvoos-model-loading',
				stage: 'loading-stage',
				progressBar: 'progress-bar',
				progressFill: 'progress-fill',
				progressText: 'progress-text',
				details: 'loading-details',
				error: 'loading-error'
			}, options.classNames || {});
			this.loadingStages = options.stages || [`
);

// Step 5: Replace the hardcoded class names in the createLoadingUI HTML template.
code = code.replace(
  /ui\.className = 'wp-mcp-ai-model-loading';/,
  'ui.className = this.classNames.container;'
);

code = code.replace(
  /ui\.innerHTML = `[\s\S]*?<div class="loading-animation">[\s\S]*?<div class="spinner"><\/div>[\s\S]*?<\/div>[\s\S]*?<div class="loading-stage"><\/div>[\s\S]*?<div class="loading-progress">[\s\S]*?<div class="progress-bar">[\s\S]*?<div class="progress-fill"><\/div>[\s\S]*?<\/div>[\s\S]*?<div class="progress-text">0%<\/div>[\s\S]*?<\/div>[\s\S]*?<div class="loading-details"><\/div>[\s\S]*?`;/,
  `ui.innerHTML = \`
				<div class="loading-animation">
					<div class="spinner"></div>
				</div>
				<div class="\${this.classNames.stage}"></div>
				<div class="loading-progress">
					<div class="\${this.classNames.progressBar}">
						<div class="\${this.classNames.progressFill}"></div>
					</div>
					<div class="\${this.classNames.progressText}">0%</div>
				</div>
				<div class="\${this.classNames.details}"></div>
			\`;`
);

// Replace querySelector references inside updateStage / updateProgress / updateDetails so they use class names.
code = code.replace(
  /ui\.querySelector\('\.loading-stage'\)/g,
  "ui.querySelector('.' + this.classNames.stage)"
);
code = code.replace(
  /ui\.querySelector\('\.progress-fill'\)/g,
  "ui.querySelector('.' + this.classNames.progressFill)"
);
code = code.replace(
  /ui\.querySelector\('\.progress-text'\)/g,
  "ui.querySelector('.' + this.classNames.progressText)"
);
code = code.replace(
  /ui\.querySelector\('\.loading-details'\)/g,
  "ui.querySelector('.' + this.classNames.details)"
);

// Replace error UI class names.
code = code.replace(
  /ui\.innerHTML = `\s*<div class="loading-error">/,
  'ui.innerHTML = `\n\t\t\t\t<div class="${this.classNames.error}">'
);

// Step 6: Add configure() method
const configureMethod = `
		/**
		 * Configure the loader after construction.
		 * @param {Object} options
		 * @param {Function} [options.engineFactory] (modelId, opts) => Promise<engine>
		 * @param {Object} [options.classNames] CSS class name overrides.
		 */
		configure(options = {}) {
			if (typeof options.engineFactory === 'function') {
				this.engineFactory = options.engineFactory;
			}
			if (options.classNames) {
				this.classNames = Object.assign(this.classNames, options.classNames);
			}
		}

`;

// Insert configure() right after constructor close brace.
code = code.replace(
  /(this\.loadingStages = options\.stages \|\| \[\s*\{ name: 'checking'[\s\S]*?\{ name: 'ready', progress: 100, message: 'Ready!' \}\s*\];\s*\})/,
  '$1\n' + configureMethod
);

// Step 7: ES module exports
code = code.trim() + '\n\n// ES Module exports\nexport { ProgressiveModelLoader };\nexport default ProgressiveModelLoader;\n';

// Step 8: Write dist
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

const outputFile = path.join(distDir, 'nvoos-model-loader.js');
fs.writeFileSync(outputFile, code);
console.log('   → Generated dist/nvoos-model-loader.js');

// Step 9: TypeScript definitions
const dtsContent = `/**
 * Progressive AI model loading UI with 4-stage progress tracking.
 * @package @nvdigitalsolutions/nvoos-model-loader
 */

export interface LoadingStage {
  name: string;
  progress: number;
  message: string;
}

export interface ProgressiveModelLoaderClassNames {
  container?: string;
  stage?: string;
  progressBar?: string;
  progressFill?: string;
  progressText?: string;
  details?: string;
  error?: string;
}

export interface InitProgressReport {
  progress: number;
  text: string;
  [key: string]: unknown;
}

export type EngineFactory = (
  modelId: string,
  opts: { initProgressCallback: (report: InitProgressReport) => void }
) => Promise<unknown>;

export interface ProgressiveModelLoaderOptions {
  engineFactory?: EngineFactory;
  classNames?: ProgressiveModelLoaderClassNames;
  stages?: LoadingStage[];
}

export declare class ProgressiveModelLoader {
  loadingStages: LoadingStage[];
  classNames: Required<ProgressiveModelLoaderClassNames>;
  engineFactory: EngineFactory | null;

  constructor(options?: ProgressiveModelLoaderOptions);
  configure(options: ProgressiveModelLoaderOptions): void;
  loadWithUI(modelId: string, container: HTMLElement): Promise<unknown>;
  checkModelCache(modelId: string): Promise<boolean>;
  downloadModel(modelId: string, onProgress: (progress: number) => void): Promise<void>;
  createLoadingUI(container: HTMLElement): HTMLElement;
  updateStage(ui: HTMLElement, stageIndex: number): void;
  updateProgress(ui: HTMLElement, progress: number): void;
  updateDetails(ui: HTMLElement, details: string): void;
  showError(ui: HTMLElement, error: Error): void;
}

export default ProgressiveModelLoader;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-model-loader.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
