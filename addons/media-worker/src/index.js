import express from 'express';
import helmet from 'helmet';
import cors from 'cors';
import dotenv from 'dotenv';
import { authMiddleware } from './middleware/auth.js';
import { requestLogger } from './middleware/log.js';
import {
	globalLimiter,
	imageLimiter,
	videoLimiter,
	browserLimiter,
	crawlLimiter,
	workflowLimiter,
} from './middleware/rate-limit.js';
import { detectCapabilities } from './utils/capabilities.js';
import { isMultiTenant, cleanupSiteTemp, tempStats } from './utils/site-paths.js';
import { configuredSites } from './middleware/auth.js';
import { configuredProviders, reloadProviderKeysFromFile, startProviderKeysWatcher } from './utils/provider-keys.js';
import { getUsage } from './utils/usage.js';
import { initRateLimitStore } from './middleware/rate-limit.js';
import { imageRouter } from './routes/image.js';
import { videoRouter } from './routes/video.js';
import { socialRouter } from './routes/social.js';
import { workflowRouter } from './routes/workflow.js';
import { pdfRouter } from './routes/pdf.js';
import { documentRouter } from './routes/document.js';
import { ocrRouter } from './routes/ocr.js';
import { emailRouter } from './routes/email.js';
import { codeRouter } from './routes/code.js';
import { dataRouter } from './routes/data.js';
import { browserRouter } from './routes/browser.js';
import { crawlRouter } from './routes/crawl.js';
import { crawl4aiRouter } from './routes/crawl4ai.js';

dotenv.config();

const app = express();
const PORT = process.env.PORT || 3100;
const isProduction = 'production' === process.env.NODE_ENV;

// Behind NGINX on managed hosts (Cloudways Velocity), X-Forwarded-For is the
// only client-IP signal. TRUST_PROXY=1 enables it for rate limiting.
if ( '1' === process.env.TRUST_PROXY ) {
	app.set( 'trust proxy', 1 );
}

app.disable( 'x-powered-by' );
app.use( helmet( { crossOriginResourcePolicy: { policy: 'cross-origin' } } ) );
app.use( requestLogger );
app.use( globalLimiter );

// CORS: server-to-server traffic needs no CORS. When ALLOWED_ORIGINS is set
// (comma-separated origins), browser-based consumers are restricted to it.
const allowedOrigins = ( process.env.ALLOWED_ORIGINS || '' )
	.split( ',' )
	.map( ( origin ) => origin.trim() )
	.filter( Boolean );
if ( allowedOrigins.length ) {
	app.use( cors( { origin: allowedOrigins } ) );
}

// OWASP: per-content-type size limits — JSON parsing is blocking and memory
// heavy; keep the default small and let upload routes use multer's 50 MB.
app.use( express.json( { limit: process.env.MAX_JSON_BODY || '10mb' } ) );
app.use( express.urlencoded( { extended: true, limit: '10mb' } ) );

// ── Health (public, minimal) ───────────────────────────────
app.get( '/api/health', (_req, res) => {
	res.json( {
		status: 'ok',
		service: 'design-media-worker',
		version: '3.2.0',
		uptime: process.uptime(),
	} );
} );

// ── Authentication gate (everything below /api) ────────────
app.use( '/api', authMiddleware );

// ── Health (full matrix, authenticated) ────────────────────
app.get( '/api/health/full', async (_req, res) => {
	try {
		const caps = await detectCapabilities();
		const providers = {
			openai: !!process.env.OPENAI_API_KEY,
			gemini: !!process.env.GEMINI_API_KEY,
			deepseek: !!process.env.DEEPSEEK_API_KEY,
			stability: !!process.env.STABILITY_API_KEY,
			replicate: !!process.env.REPLICATE_API_KEY,
			midjourney: !!process.env.MIDJOURNEY_API_KEY,
			leonardo: !!process.env.LEONARDO_API_KEY,
			ideogram: !!process.env.IDEOGRAM_API_KEY,
			getimg: !!process.env.GETIMG_API_KEY,
			deepai: !!process.env.DEEPAI_API_KEY,
			firefly: !!( process.env.FIREFLY_CLIENT_ID && process.env.FIREFLY_CLIENT_SECRET ),
			clipdrop: !!process.env.STABILITY_API_KEY,
			anthropic: !!process.env.ANTHROPIC_API_KEY,
		};

		const social = {
			twitter: !!( process.env.TWITTER_API_KEY && process.env.TWITTER_ACCESS_TOKEN ),
			facebook: !!process.env.FACEBOOK_PAGE_TOKEN,
			instagram: !!process.env.INSTAGRAM_ACCESS_TOKEN,
			linkedin: !!process.env.LINKEDIN_TOKEN,
		};

		const tenants = {
			mode: isMultiTenant() ? 'multi' : 'single',
			count: configuredSites().length,
			slugs: configuredSites(),
			sites: {},
			usage: getUsage(),
			temp: tempStats(),
		};
		if ( isMultiTenant() ) {
			for ( const slug of configuredSites() ) {
				tenants.sites[ slug ] = { providers: configuredProviders( slug ) };
			}
		}

		res.json( {
			status: 'ok',
			service: 'design-media-worker',
			version: '3.2.0',
			uptime: process.uptime(),
			environment: process.env.NODE_ENV || 'development',
			tenants,
			auth: {
				enabled: Boolean( process.env.WORKER_API_TOKEN ),
				mode: process.env.WORKER_API_TOKEN
					? 'strict'
					: ( 'strict' === ( process.env.AUTH_MODE || '' ).toLowerCase() ? 'fail-closed' : 'lenient' ),
			},
			capabilities: {
				ai_image_generation: Object.values( providers ).some( Boolean ),
				ai_providers: providers,
				ai_content: !!process.env.OPENAI_API_KEY,
				image_optimization: true,
				video_generation: !!process.env.REPLICATE_API_KEY,
				video_processing: caps.ffmpeg && caps.ffprobe,
				pdf_extraction: true,
				pdf_generation: true,
				pdf_rendering: true,
				document_excel: true,
				document_word: true,
				document_ocr: true,
				code_formatting: true,
				email: true,
				email_parsing: true,
				translation: true,
				language_detection: true,
				phone_formatting: true,
				qrcode: true,
				math_rendering: true,
				math_eval: true,
				calendar_ics: true,
				chart_rendering: true,
				geospatial: true,
				csv_processing: true,
				markdown: true,
				regression: true,
				currency: true,
				validation: true,
				browser_automation: caps.chromium,
				web_crawling: true,
				social_publishing: Object.values( social ).some( Boolean ),
				social_platforms: social,
				workflows: true,
				job_queue: caps.redis,
			},
			endpoints: {
				image: [ '/api/image/generate', '/api/image/optimize', '/api/image/optimize-batch', '/api/image/vectorize', '/api/image/providers' ],
				video: [ '/api/video/generate', '/api/video/process', '/api/video/info', '/api/video/models', '/api/video/prediction/:id' ],
				social: [ '/api/social/post', '/api/social/generate-content', '/api/social/accounts' ],
				workflow: [ '/api/workflow/social-package', '/api/workflow/brand-assets', '/api/workflow/video-pipeline', '/api/workflow/status' ],
				pdf: [ '/api/pdf/extract', '/api/pdf/render', '/api/pdf/generate', '/api/pdf/merge', '/api/pdf/watermark' ],
				document: [ '/api/document/excel', '/api/document/word' ],
				ocr: [ '/api/ocr/recognize' ],
				email: [ '/api/email/send', '/api/email/compile-mjml', '/api/email/parse' ],
				code: [ '/api/code/format', '/api/code/check-syntax' ],
				data: [ '/api/data/translate', '/api/data/language-detect', '/api/data/phone-format', '/api/data/qrcode', '/api/data/csv-parse', '/api/data/csv-generate', '/api/data/markdown', '/api/data/math', '/api/data/render-math', '/api/data/regression', '/api/data/currency', '/api/data/validate', '/api/data/generate-ics', '/api/data/render-chart', '/api/data/analyze-geospatial' ],
				browser: [ '/api/browser/screenshot', '/api/browser/pdf' ],
				crawl: [ '/api/crawl/markdown', '/api/crawl/markdown-batch', '/api/crawl/links' ],
				crawl4ai: [ '/api/crawl4ai/crawl', '/api/crawl4ai/task/:id' ],
			},
		} );
	} catch {
		res.status( 500 ).json( { error: 'Internal server error' } );
	}
} );

// ── Routes ─────────────────────────────────────────────────
app.use( '/api/image', imageLimiter, imageRouter );
app.use( '/api/video', videoLimiter, videoRouter );
app.use( '/api/social', socialRouter );
app.use( '/api/workflow', workflowLimiter, workflowRouter );
app.use( '/api/pdf', pdfRouter );
app.use( '/api/document', documentRouter );
app.use( '/api/ocr', ocrRouter );
app.use( '/api/email', emailRouter );
app.use( '/api/code', codeRouter );
app.use( '/api/data', dataRouter );
app.use( '/api/browser', browserLimiter, browserRouter );
app.use( '/api/crawl', crawlLimiter, crawlRouter );
app.use( '/api/crawl4ai', crawlLimiter, crawl4aiRouter );

// ── 404 ────────────────────────────────────────────────────
app.use( (_req, res) => {
	res.status( 404 ).json( { error: 'Not found' } );
} );

// ── Error handler (no stack traces in production) ──────────
app.use( ( err, _req, res, _next ) => {
	console.error( '[Worker Error]', err.message, err.stack || '' );
	if ( res.headersSent ) {
		return;
	}
	res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 500 ).json( {
		error: isProduction ? 'Internal server error' : err.message,
	} );
} );

// ── Startup ────────────────────────────────────────────────
const server = app.listen( PORT, async () => {
	// Phase 3 W4: swap rate-limit stores before serving traffic (opt-in).
	await initRateLimitStore();

	// Phase 3 W5: load + watch the provider-keys file (opt-in).
	if ( process.env.PROVIDER_KEYS_FILE ) {
		reloadProviderKeysFromFile();
		startProviderKeysWatcher();
	}

	console.log( `[Design Worker] Running on http://localhost:${ PORT }` );
	console.log( `[Design Worker] Environment: ${ process.env.NODE_ENV || 'development' }` );
	console.log(
		`[Design Worker] Auth: ${ process.env.WORKER_API_TOKEN ? 'strict (X-Site-Token required)' : 'lenient (no WORKER_API_TOKEN set)' }`
	);
	if ( isMultiTenant() ) {
		console.log(
			`[Design Worker] Multi-tenant mode: ${ configuredSites().length } site(s) — ${ configuredSites().join( ', ' ) }`
		);
		for ( const slug of configuredSites() ) {
			const configured = Object.values( configuredProviders( slug ) ).filter( Boolean ).length;
			console.log( `[Design Worker] Site "${ slug }": ${ configured } provider credential(s) configured` );
		}
	}
	console.log( `[Design Worker] SSRF guard: ${ '1' === process.env.SSRF_ALLOW_PRIVATE ? 'DISABLED (SSRF_ALLOW_PRIVATE=1)' : 'enabled' }` );
	console.log( `[Design Worker] Browser sandbox: ${ '1' === process.env.ALLOW_NO_SANDBOX ? 'fallback allowed (ALLOW_NO_SANDBOX=1)' : 'enforced' }` );

	const caps = await detectCapabilities();
	console.log( '' );
	console.log( '[Design Worker] AI Image providers:' );
	console.log( '  OpenAI:', process.env.OPENAI_API_KEY ? '✅ DALL·E 3' : '❌' );
	console.log( '  Gemini:', process.env.GEMINI_API_KEY ? '✅ Imagen / Flash' : '❌' );
	console.log( '  Stability:', process.env.STABILITY_API_KEY ? '✅ SDXL / SD3' : '❌' );
	console.log( '  Replicate:', process.env.REPLICATE_API_KEY ? '✅ Flux / SDXL / Video' : '❌' );
	console.log( '  Midjourney:', process.env.MIDJOURNEY_API_KEY ? '✅ v6 / Niji' : '❌' );
	console.log( '' );
	console.log( '[Design Worker] Document pipeline:' );
	console.log( '  PDF extract:   ✅ pdf-parse + pdfjs-dist' );
	console.log( '  PDF generate:  ✅ puppeteer + pdfkit' );
	console.log( '  Excel/Word:    ✅ exceljs + docx' );
	console.log( '  OCR:           ✅ tesseract.js' );
	console.log( '  Browser:       ', caps.chromium ? '✅ chromium (sandboxed)' : '❌ chromium not found — browser routes return 503' );
	console.log( '  Crawl:         ✅ readability + turndown', caps.chromium ? '(chromium fallback)' : '(static-only — no chromium)' );
	console.log( '  Code format:   ✅ prettier' );
	console.log( '  Email:         ✅ nodemailer + mjml + mailparser' );
	console.log( '' );
	console.log( '[Design Worker] Data utilities:' );
	console.log( '  Translate:     ✅ google-translate-api-x' );
	console.log( '  Language:      ✅ franc + iso-639-1' );
	console.log( '  Phone:         ✅ libphonenumber-js' );
	console.log( '  QR Code:       ✅ qrcode' );
	console.log( '  Math:          ✅ katex + mathjs' );
	console.log( '  CSV:           ✅ csv-parse + csv-stringify' );
	console.log( '  Markdown:      ✅ marked' );
	console.log( '  Regression:    ✅ regression' );
	console.log( '  Currency:      ✅ currency.js' );
	console.log( '  Validation:    ✅ validator' );
	console.log( '  Charts:        ✅ chart.js' );
	console.log( '  Geospatial:    ✅ @turf/turf' );
	console.log( '  Vectorize:     ✅ @neplex/vectorizer' );
	console.log( '' );
	console.log( '[Design Worker] Social platforms:' );
	console.log( '  Twitter:', process.env.TWITTER_ACCESS_TOKEN ? '✅' : '❌' );
	console.log( '  Facebook:', process.env.FACEBOOK_PAGE_TOKEN ? '✅' : '❌' );
	console.log( '  Instagram:', process.env.INSTAGRAM_ACCESS_TOKEN ? '✅' : '❌' );
	console.log( '  LinkedIn:', process.env.LINKEDIN_TOKEN ? '✅' : '❌' );
	console.log( '' );
	console.log( '[Design Worker] Video:', caps.ffmpeg && caps.ffprobe ? '✅ ffmpeg + ffprobe' : '❌ ffmpeg not found — video routes return 503' );
	console.log( '[Design Worker] Job Queue:', process.env.REDIS_URL ? '✅ Redis' : '⚠️  in-memory (single process only)' );

	// PM2 sets NODE_APP_INSTANCE in cluster mode. Both the in-memory rate
	// limiter store and the in-memory queue are per-process; cluster mode
	// silently multiplies rate-limit budgets and isolates queue state, so
	// warn loudly instead of degrading silently (proposal 027, Phase 2d).
	if ( undefined !== process.env.NODE_APP_INSTANCE ) {
		console.warn( '[Design Worker] PM2 cluster mode detected:' );
		console.warn( '  - In-memory rate-limit counters multiply by instance count — set RATE_LIMIT_REDIS=1 with REDIS_URL.' );
		console.warn( '  - The in-memory job queue is single-process — REDIS_URL is required in cluster mode.' );
	}

	// Phase 3 W6 (revised): notice, not a flip — the default stays
	// permissive in single-tenant mode until the shared-volume audit lands.
	if ( ! isMultiTenant() && '1' !== process.env.STRICT_PATHS && '1' !== process.env.STRICT_PDF_PATHS ) {
		console.warn( '[Design Worker] PDF path checks are permissive in single-tenant mode.' );
		console.warn( '  Set STRICT_PATHS=1 to enforce the site namespace now (see proposal 028, W6).' );
	}
} );

// ── Hard limits & graceful shutdown (PM2-compatible) ───────
server.requestTimeout = Number( process.env.REQUEST_TIMEOUT_MS || 60000 );
server.headersTimeout = 30000;
server.keepAliveTimeout = 5000;

// ── Multi-tenant scratch TTL cleanup (no-op in single-tenant mode) ──
// Per-group TTLs: TEMP_TTL_<GROUP> (defaults in site-paths.js TTL_GROUPS).
cleanupSiteTemp();
setInterval( () => cleanupSiteTemp(), 15 * 60 * 1000 ).unref();

function shutdown( signal ) {
	console.log( `[Design Worker] ${ signal } received — shutting down.` );
	server.close( () => process.exit( 0 ) );
	setTimeout( () => process.exit( 1 ), 10000 ).unref();
}

process.on( 'SIGTERM', () => shutdown( 'SIGTERM' ) );
process.on( 'SIGINT', () => shutdown( 'SIGINT' ) );
