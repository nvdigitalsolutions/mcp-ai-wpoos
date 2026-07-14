/**
 * Intro/outro card utilities for demo video scripts.
 *
 * Renders styled title cards at the start and end of each video using
 * page.setContent() to display full-screen HTML overlays.
 *
 * Based on the pattern by Justin Abrahms (Feb 2026):
 *   https://justin.abrah.ms/blog/2026-02-12-generating-demo-videos-with-playwright.html
 */

const CARD_TEMPLATE = `
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
	* { margin: 0; padding: 0; box-sizing: border-box; }
	body {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 100vh;
		background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		color: #ffffff;
		overflow: hidden;
	}
	.card {
		text-align: center;
		max-width: 800px;
		padding: 2rem;
	}
	.card .icon {
		font-size: 3rem;
		margin-bottom: 1.5rem;
		opacity: 0.8;
	}
	.card h1 {
		font-size: 2.6rem;
		font-weight: 700;
		margin-bottom: 0.75rem;
		line-height: 1.2;
		background: linear-gradient(90deg, #a78bfa, #60a5fa);
		-webkit-background-clip: text;
		-webkit-text-fill-color: transparent;
		background-clip: text;
	}
	.card p.subtitle {
		font-size: 1.3rem;
		color: rgba(255,255,255,0.7);
		margin-bottom: 0.5rem;
		line-height: 1.5;
	}
	.card .brand {
		font-size: 0.9rem;
		color: rgba(255,255,255,0.35);
		margin-top: 2.5rem;
		letter-spacing: 0.05em;
		text-transform: uppercase;
	}
	.card .badge {
		display: inline-block;
		padding: 0.35rem 1rem;
		border-radius: 20px;
		font-size: 0.8rem;
		font-weight: 600;
		margin-top: 1rem;
		letter-spacing: 0.03em;
	}
	.badge-base { background: rgba(96, 165, 250, 0.2); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.3); }
	.badge-pro  { background: rgba(167, 139, 250, 0.2); color: #a78bfa; border: 1px solid rgba(167, 139, 250, 0.3); }
</style>
</head>
<body>
	<div class="card">
		<div class="icon">{{ICON}}</div>
		<h1>{{TITLE}}</h1>
		<p class="subtitle">{{SUBTITLE}}</p>
		{{BADGE}}
		<div class="brand">NV oOS — Open Operator System</div>
	</div>
</body>
</html>`;

const ICONS = {
	assistant: '🤖',
	provider: '🔌',
	chat: '💬',
	tools: '🔧',
	guest: '👤',
	presets: '📋',
	profession: '🎓',
	dashboard: '📊',
	orchestration: '🔄',
	security: '🛡️',
	site: '🏗️',
	federation: '🌐',
	schedule: '📅',
	workflow: '⚡',
	blueprint: '📦',
	default: '🎬',
};

/**
 * Render an intro card for a video.
 *
 * @param {import('playwright').Page} page
 * @param {object} options
 * @param {string} options.title - Card title (feature name).
 * @param {string} [options.subtitle=''] - Subtitle or user story.
 * @param {string} [options.icon='default'] - Icon key from ICONS map.
 * @param {boolean} [options.isPro=false] - Show Pro badge.
 * @param {number} [options.duration=3000] - Display duration in ms.
 * @returns {Promise<void>}
 */
async function showIntroCard(page, {
	title,
	subtitle = '',
	icon = 'default',
	isPro = false,
	duration = 3000,
}) {
	const iconChar = ICONS[icon] || ICONS.default;
	const badgeHtml = isPro
		? '<div class="badge badge-pro">PRO</div>'
		: '';

	const html = CARD_TEMPLATE
		.replace('{{ICON}}', iconChar)
		.replace('{{TITLE}}', escapeHtml(title))
		.replace('{{SUBTITLE}}', escapeHtml(subtitle))
		.replace('{{BADGE}}', badgeHtml);

	await page.setContent(html);
	await page.waitForTimeout(duration);
}

/**
 * Render an outro/end card for a video.
 *
 * @param {import('playwright').Page} page
 * @param {object} options
 * @param {string} [options.title='Thank You'] - Outro title.
 * @param {string} [options.subtitle='Learn more at nvdigitalsolutions.com/wpoos'] - CTA text.
 * @param {number} [options.duration=3000] - Display duration in ms.
 * @returns {Promise<void>}
 */
async function showOutroCard(page, {
	title = 'Ready to Get Started?',
	subtitle = 'Visit nvdigitalsolutions.com/wpoos to download the plugin.',
	duration = 3000,
} = {}) {
	const html = CARD_TEMPLATE
		.replace('{{ICON}}', '🚀')
		.replace('{{TITLE}}', escapeHtml(title))
		.replace('{{SUBTITLE}}', escapeHtml(subtitle))
		.replace('{{BADGE}}', '');

	await page.setContent(html);
	await page.waitForTimeout(duration);
}

/**
 * Escape HTML special characters for safe insertion.
 *
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
	return str
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

module.exports = { showIntroCard, showOutroCard, ICONS };
