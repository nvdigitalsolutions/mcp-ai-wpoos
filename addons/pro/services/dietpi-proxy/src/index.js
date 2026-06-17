/**
 * DietPi SSH Proxy Service
 *
 * Lightweight Express server that proxies SSH commands from the NV oOS
 * DietPi Pro Toolkit to a DietPi device.  Used as a fallback when the
 * WordPress host lacks both the PHP ssh2 extension and proc_open.
 *
 * Startup:
 *   node src/index.js
 *   PORT=3099 node src/index.js
 *
 * The toolkit sends POST /exec with { command, timeout } and receives
 * { stdout, stderr, exit_code, duration_ms } or an error object.
 *
 * Authentication: shared secret token in X-Proxy-Token header.
 * Configure via PROXY_TOKEN env var (default: auto-generated and
 * logged to stdout on first start).
 *
 * @package    NV oOS DietPi Toolkit
 * @since      1.3.0
 */

const express  = require( 'express' );
const { NodeSSH } = require( 'node-ssh' );
const crypto   = require( 'crypto' );

const PORT       = parseInt( process.env.PORT || '3099', 10 );
const PROXY_TOKEN = process.env.PROXY_TOKEN || crypto.randomBytes( 24 ).toString( 'hex' );
const SSH_HOST    = process.env.SSH_HOST    || '';
const SSH_PORT    = parseInt( process.env.SSH_PORT || '22', 10 );
const SSH_USER    = process.env.SSH_USER    || 'root';
const SSH_KEY     = process.env.SSH_KEY     || '';  // Path to private key file or inline PEM
const SSH_PASSPHRASE = process.env.SSH_PASSPHRASE || '';
const SSH_PASSWORD   = process.env.SSH_PASSWORD   || '';

const app = express();
app.use( express.json() );

// ── Auth middleware ──
app.use( ( req, res, next ) => {
	const token = req.headers['x-proxy-token'];
	if ( ! token || token !== PROXY_TOKEN ) {
		return res.status( 401 ).json( { error: 'Unauthorized. Provide X-Proxy-Token header.' } );
	}
	next();
} );

// ── Health check ──
app.get( '/health', ( _req, res ) => {
	res.json( { status: 'ok', uptime: process.uptime() } );
} );

// ── Execute SSH command ──
app.post( '/exec', async ( req, res ) => {
	const { command, timeout = 30 } = req.body;

	if ( ! command || typeof command !== 'string' ) {
		return res.status( 400 ).json( { error: 'Missing or invalid "command" field.' } );
	}

	if ( ! SSH_HOST ) {
		return res.status( 500 ).json( { error: 'SSH_HOST not configured on proxy server.' } );
	}

	const ssh = new NodeSSH();
	const start = Date.now();

	try {
		const connectConfig = {
			host: SSH_HOST,
			port: SSH_PORT,
			username: SSH_USER,
			readyTimeout: Math.max( 5000, timeout * 1000 ),
		};

		if ( SSH_KEY ) {
			connectConfig.privateKey = SSH_KEY;
			if ( SSH_PASSPHRASE ) {
				connectConfig.passphrase = SSH_PASSPHRASE;
			}
		} else if ( SSH_PASSWORD ) {
			connectConfig.password = SSH_PASSWORD;
		}

		await ssh.connect( connectConfig );

		const result = await ssh.execCommand( command, {
			cwd: '/root',
			timeout: timeout * 1000,
		} );

		ssh.dispose();

		const duration_ms = Date.now() - start;

		return res.json( {
			stdout: result.stdout ? result.stdout.trim() : '',
			stderr: result.stderr ? result.stderr.trim() : '',
			exit_code: result.code ?? ( result.stderr ? 1 : 0 ),
			duration_ms,
		} );
	} catch ( err ) {
		ssh.dispose();
		const duration_ms = Date.now() - start;

		return res.status( 500 ).json( {
			error: err.message || 'SSH connection or execution failed.',
			stdout: '',
			stderr: err.message || '',
			exit_code: 1,
			duration_ms,
		} );
	}
} );

// ── Start ──
app.listen( PORT, () => {
	console.log( `[dietpi-proxy] Listening on port ${PORT}` );
	if ( ! process.env.PROXY_TOKEN ) {
		console.log( `[dietpi-proxy] Auto-generated PROXY_TOKEN: ${PROXY_TOKEN}` );
		console.log( `[dietpi-proxy] Set PROXY_TOKEN env var to persist across restarts.` );
	}
	if ( ! SSH_HOST ) {
		console.warn( `[dietpi-proxy] WARNING: SSH_HOST is not set. Set it via environment variable.` );
	}
} );
