#!/usr/bin/env node
/**
 * MCP Bridge over SSH — for NV oOS sites that are only reachable via SSH.
 *
 * Opens an SSH port-forward to the site's web server, waits until it accepts
 * connections, then runs bin/mcp-bridge.js (stdio ↔ HTTP relay) against the
 * forwarded port. When the relay exits — or this process is killed — the SSH
 * tunnel is torn down.
 *
 * Environment variables (also read from MCP_AI_ENV_FILE, default
 * ~/.nvoos-bridge.env; values already present in the process environment win
 * over the file, so Zed's per-project overrides still work):
 *
 *   MCP_AI_SSH_USER          SSH login user (required)
 *   MCP_AI_SSH_HOST          SSH host (required)
 *   MCP_AI_SSH_PORT          SSH port (default 22)
 *   MCP_AI_SSH_REMOTE_HOST   Host the web server listens on, from the
 *                            server's perspective (default localhost)
 *   MCP_AI_SSH_REMOTE_PORT   Port the web server listens on (default 80)
 *   MCP_AI_LOCAL_PORT        Local port for the forward (default: auto-picked
 *                            free port — prefer that unless you must pin one)
 *   MCP_AI_SSH_CMD           ssh binary (default: ssh)
 *   MCP_AI_SSH_EXTRA_ARGS    Extra args passed to ssh before the managed
 *                            ones, double-quote values containing spaces
 *                            (e.g. "-i C:/keys/id_ed25519 -o ProxyJump=bastion")
 *   MCP_AI_SSH_BATCH_MODE    Set to 0 to disable BatchMode=yes. Only useful
 *                            if an SSH_ASKPASS helper is configured — a
 *                            spawned process has no TTY for password prompts.
 *   MCP_AI_SSH_READY_MS      How long to wait for the tunnel (default 15000)
 *   MCP_AI_BASE_PATH         MCP route path (default /wp-json/mcp-ai/v1/mcp)
 *   MCP_AI_TOKEN             Bearer credential (cred_xxxxx.SECRET or
 *                            op_xxxxx.SECRET)
 *   MCP_AI_HOST_HEADER       Forwarded to the relay as the HTTP Host header
 *                            (use if the web server canonical-redirects on
 *                            Host and rejects the 127.0.0.1 address)
 *   MCP_AI_HTTP_TIMEOUT      Forwarded to the relay (ms, default 120000)
 *   MCP_AI_ENV_FILE          Env file path (default ~/.nvoos-bridge.env)
 *
 * SSH auth is key-only by default: `ssh <user>@<host> -p <port>` must succeed
 * non-interactively before this bridge will work. Put your public key in the
 * server's ~/.ssh/authorized_keys first.
 *
 * Example Zed context server entry (Settings → AI → MCP Servers → Add Local
 * Server, or settings.json):
 *   {
 *     "command": "node",
 *     "args": ["bin/mcp-bridge-ssh.js"],
 *     "env": {
 *       "MCP_AI_SSH_USER": "your-ssh-user",
 *       "MCP_AI_SSH_HOST": "203.0.113.10",
 *       "MCP_AI_SSH_PORT": "2222",
 *       "MCP_AI_TOKEN":    "op_xxxx.SECRET"
 *     }
 *   }
 */

'use strict';

const net = require( 'net' );
const path = require( 'path' );
const readline = require( 'readline' );
const { spawn } = require( 'child_process' );
const { parseEnvFile, loadEnvFile } = require( './utils/env-file.js' );

const LOG = '[mcp-bridge-ssh]';

/**
 * Log a diagnostic line to stderr (stdout is reserved for MCP messages).
 *
 * @param {string} msg  Message to log.
 */
function log( msg ) {
	process.stderr.write( `${ LOG } ${ msg }\n` );
}

// ── Small helpers ────────────────────────────────────────────────────────────

/**
 * Split a command-args string, honouring single/double quotes (no shell
 * interpolation — safer than delegating to a shell).
 *
 * @param {string} input  e.g. '-i "C:/my keys/id" -o ProxyJump=bastion'.
 * @returns {string[]} Arg list.
 */
function splitArgs( input ) {
	const out = [];
	const re = /"([^"]*)"|'([^']*)'|(\S+)/g;
	let m;
	while ( null !== ( m = re.exec( input ) ) ) {
		out.push( undefined !== m[1] ? m[1] : ( undefined !== m[2] ? m[2] : m[3] ) );
	}
	return out;
}

/**
 * Find a free TCP port on 127.0.0.1 by binding port 0.
 *
 * @returns {Promise<number>} Free port number.
 */
function findFreePort() {
	return new Promise( ( resolve, reject ) => {
		const srv = net.createServer();
		srv.once( 'error', reject );
		srv.listen( 0, '127.0.0.1', () => {
			const port = srv.address().port;
			srv.close( ( err ) => ( err ? reject( err ) : resolve( port ) ) );
		} );
	} );
}

/**
 * Test whether a TCP connect to 127.0.0.1:port succeeds.
 *
 * @param {number} port  Port to probe.
 * @returns {Promise<boolean>}
 */
function canConnect( port ) {
	return new Promise( ( resolve ) => {
		const sock = net.connect( { host: '127.0.0.1', port } );
		sock.once( 'connect', () => {
			sock.destroy();
			resolve( true );
		} );
		sock.once( 'error', () => resolve( false ) );
	} );
}

/**
 * Wait until the forwarded port accepts connections.
 *
 * @param {number} port        Port to probe.
 * @param {number} timeoutMs   Overall budget in ms.
 * @param {object} sshChild    The ssh child — aborts early if it dies.
 * @returns {Promise<void>}
 */
async function waitForPort( port, timeoutMs, sshChild ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( null !== sshChild.exitCode || null !== sshChild.signalCode ) {
			throw new Error(
				`ssh exited before the tunnel was ready (exit code ${ sshChild.exitCode ?? 'signal ' + sshChild.signalCode })`
			);
		}
		if ( await canConnect( port ) ) {
			return;
		}
		await new Promise( ( r ) => setTimeout( r, 200 ) );
	}
	throw new Error( `tunnel on 127.0.0.1:${ port } did not accept connections within ${ timeoutMs }ms` );
}

// ── Orchestration ────────────────────────────────────────────────────────────

async function main() {
	loadEnvFile( log );

	const user = process.env.MCP_AI_SSH_USER || '';
	const host = process.env.MCP_AI_SSH_HOST || '';
	if ( ! user || ! host ) {
		log( 'ERROR: MCP_AI_SSH_USER and MCP_AI_SSH_HOST are required.' );
		process.exit( 2 );
	}

	const sshPort = process.env.MCP_AI_SSH_PORT || '22';
	const remoteHost = process.env.MCP_AI_SSH_REMOTE_HOST || 'localhost';
	const remotePort = process.env.MCP_AI_SSH_REMOTE_PORT || '80';

	let basePath = process.env.MCP_AI_BASE_PATH || '/wp-json/mcp-ai/v1/mcp';
	if ( ! basePath.startsWith( '/' ) ) {
		basePath = '/' + basePath;
	}
	basePath = basePath.replace( /\/+$/, '' );

	const sshCmd = process.env.MCP_AI_SSH_CMD || 'ssh';
	const extraArgs = splitArgs( process.env.MCP_AI_SSH_EXTRA_ARGS || '' );
	const batchMode = '0' !== process.env.MCP_AI_SSH_BATCH_MODE;

	const readyRaw = parseInt( process.env.MCP_AI_SSH_READY_MS || '', 10 );
	const readyMs = Number.isFinite( readyRaw ) && readyRaw >= 1000 ? readyRaw : 15000;

	const localPort = process.env.MCP_AI_LOCAL_PORT
		? parseInt( process.env.MCP_AI_LOCAL_PORT, 10 )
		: await findFreePort();
	if ( ! Number.isInteger( localPort ) || localPort < 1 || localPort > 65535 ) {
		log( 'ERROR: MCP_AI_LOCAL_PORT must be a valid port number.' );
		process.exit( 2 );
	}

	// Bind the forward to 127.0.0.1 explicitly: the bridge is a local-only
	// hop, and an IPv4 bind avoids IPv6/IPv4 probe mismatches on Windows.
	const sshArgs = [
		...extraArgs,
		'-N',
		'-L', `127.0.0.1:${ localPort }:${ remoteHost }:${ remotePort }`,
		'-p', sshPort,
		'-o', 'ExitOnForwardFailure=yes',
		'-o', 'ServerAliveInterval=30',
		'-o', 'ServerAliveCountMax=3',
		'-o', 'StrictHostKeyChecking=accept-new',
		'-o', 'ConnectTimeout=15',
		...( batchMode ? [ '-o', 'BatchMode=yes' ] : [] ),
		`${ user }@${ host }`,
	];

	log( `Starting tunnel: ${ sshCmd } ${ sshArgs.join( ' ' ) }` );
	const ssh = spawn( sshCmd, sshArgs, {
		stdio: [ 'pipe', 'ignore', 'pipe' ],
		windowsHide: true,
	} );

	// stdin is held open on purpose: `ssh -N` exits when stdin reaches EOF,
	// so if this process is hard-killed (e.g. Zed terminates the server) the
	// OS closes the pipe and the orphaned tunnel tears itself down.
	const sshErr = readline.createInterface( { input: ssh.stderr, crlfDelay: Infinity } );
	sshErr.on( 'line', ( line ) => log( `ssh: ${ line }` ) );

	ssh.once( 'error', ( err ) => {
		log( `ERROR: could not start ${ sshCmd }: ${ err.message }` );
		log( `HINT: make sure OpenSSH is installed and \`ssh ${ user }@${ host }\` works non-interactively with a key (see header docs).` );
		process.exit( 1 );
	} );

	try {
		await waitForPort( localPort, readyMs, ssh );
	} catch ( err ) {
		log( `ERROR: ${ err.message }` );
		process.exit( 1 );
	}
	log( `tunnel ready on 127.0.0.1:${ localPort } → ${ remoteHost }:${ remotePort }` );

	const relayEnv = {
		...process.env,
		MCP_AI_BASE_URL: `http://127.0.0.1:${ localPort }${ basePath }`,
	};
	if ( process.env.MCP_AI_HOST_HEADER ) {
		relayEnv.MCP_AI_HOST_HEADER = process.env.MCP_AI_HOST_HEADER;
	}
	if ( process.env.MCP_AI_HTTP_TIMEOUT ) {
		relayEnv.MCP_AI_HTTP_TIMEOUT = process.env.MCP_AI_HTTP_TIMEOUT;
	}

	log( `Starting relay against ${ relayEnv.MCP_AI_BASE_URL }` );
	const relay = spawn( process.execPath, [ path.join( __dirname, 'mcp-bridge.js' ) ], {
		stdio: [ 'inherit', 'inherit', 'inherit' ],
		env: relayEnv,
		windowsHide: true,
	} );

	let shuttingDown = false;

	/**
	 * Tear down cleanly: EOF the ssh stdin (lets the tunnel exit on its own),
	 * then force-kill it after a grace period. Never unref() the grace timer
	 * — on Windows that would let the process exit before the kill lands and
	 * orphan the tunnel.
	 *
	 * @param {number} code  Process exit code.
	 */
	function shutdown( code ) {
		if ( shuttingDown ) {
			return;
		}
		shuttingDown = true;
		try {
			ssh.stdin.end();
		} catch ( e ) {
			// Pipe already gone.
		}
		setTimeout( () => {
			try {
				ssh.kill();
			} catch ( e ) {
				// Already exited.
			}
		}, 1000 );
		process.exitCode = code;
	}

	relay.once( 'error', ( err ) => {
		log( `ERROR: could not start relay: ${ err.message }` );
		shutdown( 1 );
	} );

	relay.once( 'exit', ( code, signal ) => {
		if ( shuttingDown ) {
			return;
		}
		log( `relay exited (${ signal ?? code }); closing tunnel` );
		shutdown( 'number' === typeof code ? code : 0 );
	} );

	ssh.once( 'exit', ( code, signal ) => {
		if ( shuttingDown ) {
			return;
		}
		log( `ERROR: ssh tunnel exited unexpectedly (${ signal ?? code }) — network blip or server restart.` );
		try {
			relay.kill();
		} catch ( e ) {
			// Already gone.
		}
		shutdown( 1 );
	} );

	const onSignal = () => {
		log( 'signal received, shutting down' );
		try {
			relay.kill();
		} catch ( e ) {
			// Already gone.
		}
		shutdown( 0 );
	};
	process.once( 'SIGINT', onSignal );
	process.once( 'SIGTERM', onSignal );

	// Backstop for normal exit paths; a hard kill (TerminateProcess) is
	// covered by the stdin-EOF contract with ssh instead.
	process.once( 'exit', () => {
		try {
			ssh.stdin.end();
			ssh.kill();
		} catch ( e ) {
			// Already gone.
		}
		try {
			relay.kill();
		} catch ( e ) {
			// Already gone.
		}
	} );
}

if ( require.main === module ) {
	main().catch( ( err ) => {
		log( `ERROR: ${ err.message }` );
		process.exit( 1 );
	} );
}

module.exports = { parseEnvFile, splitArgs, findFreePort, canConnect, waitForPort, main };
