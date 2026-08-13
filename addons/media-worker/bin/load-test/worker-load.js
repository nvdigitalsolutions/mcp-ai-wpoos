/**
 * k6 load test for the Design Stack Media Worker (proposal 027, Phase 2e).
 *
 * Usage (k6 binary, not an npm dependency):
 *
 *   # Single site
 *   k6 run -e WORKER_URL=https://worker.example.com -e WORKER_TOKEN=token bin/load-test/worker-load.js
 *
 *   # Two sites (exercises per-site rate-limit isolation)
 *   k6 run -e WORKER_URL=https://worker.example.com \
 *          -e SITE_A_TOKEN=tokenA -e SITE_B_TOKEN=tokenB \
 *          bin/load-test/worker-load.js
 *
 *   # Scale the ramp
 *   k6 run -e RPS=20 -e DURATION=5m ...
 *
 * Assertions: no 5xx, error rate < 1%, p95 < 2s on fast routes.
 * Watch the worker's memory RSS via PM2 while running (video/browser
 * routes are deliberately NOT exercised here — they need files and
 * dominate CPU; see README for heavier scenarios).
 */

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.WORKER_URL || 'http://localhost:3100';
const RPS = Number( __ENV.RPS || 5 );
const DURATION = __ENV.DURATION || '2m';

const TOKENS = [ __ENV.WORKER_TOKEN, __ENV.SITE_A_TOKEN, __ENV.SITE_B_TOKEN ].filter( Boolean );

export const options = {
	scenarios: {
		mixed: {
			executor: 'constant-arrival-rate',
			rate: RPS,
			timeUnit: '1s',
			duration: DURATION,
			preAllocatedVUs: Math.min( 50, RPS * 4 ),
			maxVUs: 100,
		},
	},
	thresholds: {
		http_req_failed: [ 'rate<0.01' ],
		http_req_duration: [ 'p(95)<2000' ],
	},
};

function headersFor( index ) {
	const token = TOKENS[ index % TOKENS.length ];
	return token ? { 'Content-Type': 'application/json', 'X-Site-Token': token } : { 'Content-Type': 'application/json' };
}

export default function () {
	const iteration = __ITER || 0;
	const headers = headersFor( iteration );

	// Fast, pure-JS route — health of the request pipeline.
	const phone = http.post(
		`${ BASE }/api/data/phone-format`,
		JSON.stringify( { phone: '+442079460958' } ),
		{ headers }
	);
	check( phone, { 'phone-format 200': ( r ) => r.status === 200 } );

	// CPU-bound route (native QR encoding) — stresses the event loop.
	const qr = http.post(
		`${ BASE }/api/data/qrcode`,
		JSON.stringify( { text: `load-test-${ iteration }`, options: { width: 128 } } ),
		{ headers }
	);
	check( qr, { 'qrcode 200': ( r ) => r.status === 200 } );

	// Queue/scoped state route — per-site stats must not leak across tokens.
	const status = http.get( `${ BASE }/api/workflow/status`, { headers } );
	check( status, { 'status 200': ( r ) => r.status === 200 } );

	// Auth negative control (missing token must 401, never 5xx).
	const unauth = http.get( `${ BASE }/api/health/full`, {
		headers: { 'X-Site-Token': 'not-a-real-token' },
	} );
	check( unauth, { 'unauth 401': ( r ) => r.status === 401 } );

	sleep( 1 );
}
