// SPDX-License-Identifier: MIT
/**
 * @fileoverview Emits scripts/doctor.mjs — the four-layer sign-in diagnostic.
 *
 * A managed site can fail a sign-in in four places (origin, credential,
 * permission, upstream) and from a laptop they look identical. This runs where
 * the egress lives and names the layer, so the next failure costs one command
 * instead of a deployment.
 */

/**
 * The emitted script. Plain Node 22, no dependencies, no template literals —
 * this string is a String.raw template, so a backtick or an interpolation here
 * would terminate it.
 */
export function renderDoctor(): string {
	return String.raw`// doctor.mjs — name the failing layer, from the box that serves the site.
//
//   node scripts/doctor.mjs
//   DOCTOR_EMAIL=you@example.com DOCTOR_PASSWORD='...' node scripts/doctor.mjs
//
// Reads the same env the app does, so it tests the app's real egress.

const TARGET = (process.env.NVOOS_TARGET_SITE || '').replace(/\/+$/, '');
const PUBLIC = (process.env.ASTRO_SITE || '').replace(/\/+$/, '');
const PORT = Number(process.env.PORT) || 4321;
const LIMIT = Number(process.env.NVOOS_MANAGE_TIMEOUT_MS) || 15000;
const EMAIL = process.env.DOCTOR_EMAIL || '';
const PASSWORD = process.env.DOCTOR_PASSWORD || '';

const rows = [];
function record(ok, layer, detail) {
	rows.push({ ok, layer, detail });
}

async function probe(url, init) {
	const started = Date.now();
	try {
		const res = await fetch(url, Object.assign({}, init, { signal: AbortSignal.timeout(LIMIT) }));
		const text = await res.text().catch(() => '');
		return { ok: true, status: res.status, ms: Date.now() - started, text };
	} catch (error) {
		const name = error && error.name ? error.name : 'net';
		return { ok: false, status: 0, ms: Date.now() - started, text: '', error: name };
	}
}

function looksJson(text) {
	try {
		JSON.parse(text);
		return true;
	} catch {
		return false;
	}
}

async function main() {
	if (!TARGET) {
		console.error('doctor: NVOOS_TARGET_SITE is not set — nothing to probe.');
		process.exit(2);
	}
	console.log('doctor: target ' + TARGET + ' | public ' + (PUBLIC || '(unset)') + ' | limit ' + LIMIT + 'ms');

	// 1. Upstream: is the CMS reachable, and is it WordPress answering?
	const index = await probe(TARGET + '/wp-json/');
	if (!index.ok) {
		record(false, 'upstream', 'the CMS is unreachable (' + index.error + ' after ' + index.ms + 'ms)');
	} else if (!looksJson(index.text) && index.status >= 400) {
		record(
			false,
			'upstream',
			'a firewall answered ' + index.status + ' with a NON-JSON body — allowlist this server IP or exclude /wp-json/* from the WAF'
		);
	} else {
		record(true, 'upstream', 'REST index answered ' + index.status + ' in ' + index.ms + 'ms');
	}

	// 2. The gate: the bridge route the runtime polls.
	const gate = await probe(TARGET + '/wp-json/nvoos-forge/v1/gate');
	if (!gate.ok) {
		record(false, 'gate', 'unreachable (' + gate.error + ')');
	} else if (gate.status === 404) {
		record(false, 'gate', 'no route (404) — the bridge plugin is not active on this CMS');
	} else {
		record(gate.status === 200, 'gate', 'answered ' + gate.status + ' in ' + gate.ms + 'ms');
	}

	// 3. The credential door + capability answer.
	const body = JSON.stringify({
		email: EMAIL || 'doctor-probe@invalid',
		password: PASSWORD || 'doctor-probe',
	});
	const contract = await probe(TARGET + '/wp-json/nvoos-commerce/v1/auth/login', {
		method: 'POST',
		headers: { 'content-type': 'application/json', accept: 'application/json' },
		body,
	});
	if (!contract.ok) {
		record(false, 'credential', 'the contract is unreachable (' + contract.error + ')');
	} else if (contract.status === 404) {
		record(false, 'credential', 'no bridge (404) — sign-in falls back to Basic + wp-login, which needs an Application Password');
	} else if (contract.status === 401) {
		record(
			!(EMAIL && PASSWORD),
			'credential',
			EMAIL && PASSWORD
				? 'the contract refused these credentials (401)'
				: 'contract is live (401 for the probe); set DOCTOR_EMAIL/DOCTOR_PASSWORD to test a real credential'
		);
	} else if (contract.status === 200) {
		let payload = {};
		try {
			payload = JSON.parse(contract.text) || {};
		} catch {
			payload = {};
		}
		record(
			payload.canManage === true,
			'permission',
			'signed in as ' + (payload.name || '(unnamed)') + ' | canManage=' + String(payload.canManage === true)
		);
	} else if (contract.status === 403 && !looksJson(contract.text)) {
		record(false, 'credential', 'a firewall answered 403 with a non-JSON body');
	} else {
		record(false, 'credential', 'the contract answered ' + contract.status);
	}

	// 4. The app itself, on loopback — proves the process is up and healthy.
	const health = await probe('http://127.0.0.1:' + PORT + '/api/health');
	if (!health.ok) {
		record(false, 'app', 'not listening on ' + PORT + ' (' + health.error + ')');
	} else {
		record(health.status === 200, 'app', 'health answered ' + health.status + ' in ' + health.ms + 'ms');
	}

	for (const row of rows) {
		console.log((row.ok ? 'PASS ' : 'FAIL ') + row.layer.padEnd(11) + ' ' + row.detail);
	}
	const failed = rows.filter((row) => !row.ok).length;
	console.log(failed === 0 ? 'doctor: all layers answered.' : 'doctor: ' + failed + ' layer(s) failed.');
	process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error('doctor: ' + (error && error.stack ? error.stack : String(error)));
	process.exit(1);
});
`;
}
