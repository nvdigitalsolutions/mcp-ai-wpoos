#!/usr/bin/env node
/**
 * Local dev seed script — creates a wallet + connect token in the local D1
 * database without needing Stripe.
 *
 * Usage:
 *   node scripts/seed-local.mjs
 *
 * Prerequisites:
 *   - wrangler installed (npm install in addons/cloud-worker)
 *   - D1 schema already applied (npx wrangler d1 execute --local)
 */

import { randomUUID, randomBytes, createHash } from 'node:crypto';
import { execSync } from 'node:child_process';
import { writeFileSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { tmpdir } from 'node:os';

// =========================================================================
// Configure these to match your local WordPress setup
// =========================================================================
const SITE_URL = process.env.WP_SITE_URL || 'http://localhost:8000';
const EMAIL    = process.env.NV_CLOUD_EMAIL || 'dev@localhost.test';
const BALANCE_USD = parseFloat(process.env.NV_CLOUD_BALANCE || '100');

// =========================================================================
// Generate token + hash (mirrors Worker's generateConnectToken / sha256Hex)
// =========================================================================

function generateConnectToken() {
	const bytes = randomBytes(32);
	const base64url = bytes.toString('base64')
		.replace(/\+/g, '-')
		.replace(/\//g, '_')
		.replace(/=+$/, '');
	return 'nvc_' + base64url;
}

function sha256Hex(input) {
	return createHash('sha256').update(input).digest('hex');
}

function normalizeSiteUrl(raw) {
	const parsed = new URL(raw);
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new Error('site_url must be http or https');
	}
	return `${parsed.protocol}//${parsed.host.toLowerCase()}`;
}

function d1Execute(sql) {
	const cwd = resolve(dirname(fileURLToPath(import.meta.url)), '..');
	// Write SQL to a temp file to avoid shell escaping issues
	const tmpFile = resolve(tmpdir(), `nvoos-seed-${Date.now()}.sql`);
	writeFileSync(tmpFile, sql, 'utf-8');
	try {
		execSync(
			`npx wrangler d1 execute nvoos-cloud-prod --local --file="${tmpFile}"`,
			{ stdio: 'pipe', cwd }
		);
	} catch (err) {
		console.error('❌ Failed to execute SQL. Is wrangler installed?');
		const stderr = err.stderr ? err.stderr.toString() : err.message;
		console.error(stderr);
		process.exit(1);
	} finally {
		try { unlinkSync(tmpFile); } catch { /* ok */ }
	}
}

// =========================================================================
// Main
// =========================================================================

console.log('🔑 Generating Connect Token for local dev...\n');

const siteUrl     = normalizeSiteUrl(SITE_URL);
const walletId    = randomUUID();
const tokenId     = randomUUID();
const plaintext   = generateConnectToken();
const tokenHash   = sha256Hex(plaintext);
const now         = Date.now();
const stripeCust  = `cus_local_${now}`;
const balanceMicro = Math.round(BALANCE_USD * 1_000_000);

// 1. Create wallet
console.log(`Creating wallet (balance: $${BALANCE_USD.toFixed(2)} USD)...`);
d1Execute(
	`INSERT INTO wallets (id, stripe_customer_id, email, balance_micro_usd, created_at, updated_at)
	 VALUES ('${walletId}', '${stripeCust}', '${EMAIL}', ${balanceMicro}, ${now}, ${now})`
);

// 2. Issue connect token
console.log(`Issuing connect token bound to ${siteUrl}...`);
d1Execute(
	`INSERT INTO connect_tokens (id, wallet_id, token_hash, site_url, label, created_at)
	 VALUES ('${tokenId}', '${walletId}', '${tokenHash}', '${siteUrl}', 'local-dev', ${now})`
);

// 3. Output
console.log('');
console.log('═'.repeat(64));
console.log('  CONNECT TOKEN  (save this — it is shown ONLY ONCE)');
console.log('═'.repeat(64));
console.log(`  ${plaintext}`);
console.log('═'.repeat(64));
console.log('');
console.log('Wallet ID:', walletId);
console.log('Token ID:', tokenId);
console.log('Site URL:', siteUrl);
console.log('Balance:  ', `$${BALANCE_USD.toFixed(2)} USD`);
console.log('');
console.log('Next steps:');
console.log('  1. Add to wp-config.php:');
console.log(`     define('WP_MCP_AI_NV_CLOUD_BASE_URL', 'http://localhost:8787/v1');`);
console.log('  2. Start the worker:  npm run dev');
console.log('  3. In WP-Admin → NV oOS → NV oOS Cloud → paste the token above');
console.log('  4. Click "Save token"');
