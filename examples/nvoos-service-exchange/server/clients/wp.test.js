import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	buildInstallCycle,
	buildBundleImportCommand,
	buildCredentialIssueCommand,
	renderPasteBlock,
	resolveTier,
	createWpExecutor,
	WP_BUNDLE_SLUG,
} from './wp.js';

test( 'install cycle is the proven deactivate → delete → install → activate sequence', () => {
	const commands = buildInstallCycle();
	assert.equal( commands.length, 5 );
	assert.match( commands[ 0 ], /^wp plugin deactivate / );
	assert.match( commands[ 1 ], /^wp plugin delete / );
	assert.match( commands[ 2 ], /^wp plugin install "\$\(ls -t \.\.\// );
	assert.match( commands[ 3 ], /^wp plugin activate / );
	assert.equal( commands[ 4 ], 'wp cache flush' );
	assert.ok( commands[ 0 ].includes( WP_BUNDLE_SLUG ) );
} );

test( 'unsafe zip globs are rejected', () => {
	assert.throws( () => buildInstallCycle( { zipGlob: '$(rm -rf /)' } ), /Unsafe zip glob/ );
	assert.throws( () => buildInstallCycle( { zipGlob: 'a;b' } ), /Unsafe zip glob/ );
} );

test( 'bundle import rejects traversal paths', () => {
	buildBundleImportCommand( { file: 'blueprints/clinic-ops.json' } );
	assert.throws( () => buildBundleImportCommand( { file: '../../etc/passwd' } ), /Unsafe/ );
	assert.throws( () => buildBundleImportCommand( { file: '/abs/path' } ), /Unsafe/ );
} );

test( 'credential issue requires a positive assistant id', () => {
	assert.deepEqual( buildCredentialIssueCommand( { assistantId: 42 } ), [
		'wp mcp-ai credential issue 42',
	] );
	assert.throws( () => buildCredentialIssueCommand( { assistantId: '1; rm -rf /' } ), /Invalid assistantId/ );
} );

test( 'tier resolution prefers WP Manager, then SSH, then manual', () => {
	assert.equal( resolveTier( { wpManager: true, sshKeyPresent: true } ), 'wp_manager' );
	assert.equal( resolveTier( { wpManager: false, sshKeyPresent: true } ), 'ssh' );
	assert.equal( resolveTier( {} ), 'manual' );
} );

test( 'paste-block terminates every command line with a semicolon', () => {
	const block = renderPasteBlock( {
		sysUser: 'abcdef12',
		domain: 'clinic.example.test',
		commands: buildInstallCycle(),
	} );
	const lines = block.split( '\n' );
	assert.match( lines[ 0 ], /^# Estate:/ );
	assert.equal( lines[ 1 ], 'cd ~/applications/abcdef12/public_html' );
	for ( const line of lines.slice( 2 ) ) {
		assert.ok( line.endsWith( ';' ), `line should end with ;: ${ line }` );
	}
} );

test( 'paste-block rejects unsafe sys_user and domain', () => {
	assert.throws(
		() => renderPasteBlock( { sysUser: 'bad user', commands: [ 'wp --info' ] } ),
		/Invalid Cloudways sys_user/
	);
	assert.throws(
		() => renderPasteBlock( { sysUser: 'abcdef12', domain: 'bad domain', commands: [ 'wp --info' ] } ),
		/Invalid domain/
	);
} );

test( 'manual tier refuses to execute', () => {
	assert.throws( () => createWpExecutor( { tier: 'manual' } ), /Manual tier/ );
} );

test( 'executor runs commands sequentially and wraps failures', async () => {
	const seen = [];
	const executor = createWpExecutor( {
		tier: 'ssh',
		runner: async ( command ) => {
			seen.push( command );
			if ( command.includes( 'delete' ) ) {
				throw new Error( 'plugin already deleted' );
			}
			return { stdout: 'ok' };
		},
	} );
	await assert.rejects(
		() => executor.run( [ 'wp plugin deactivate x', 'wp plugin delete x' ] ),
		( error ) => error.code === 'provisioning_failed' && /already deleted/.test( error.message )
	);
	assert.deepEqual( seen, [ 'wp plugin deactivate x', 'wp plugin delete x' ] );
} );
