// ---------------------------------------------------------------------------
// vault.test.ts — Phase 3.1: encrypted credential store, masking, fail-closed
// behavior. All fixtures live in a temp dir; nothing touches the user home.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { forgeHome, maskSecret, Vault, VaultError } from '../lib/vault.ts';

let dir: string;
const KEY = 'test-passphrase';

beforeEach( async () => {
	dir = await mkdtemp( join( tmpdir(), 'forge-vault-' ) );
} );

afterEach( () => {
	delete process.env.NVOOS_FORGE_HOME;
	delete process.env.NVOOS_FORGE_VAULT_KEY;
	delete process.env.NVOOS_FORGE_VAULT_PLAINTEXT;
} );

describe( 'Vault (encrypted)', () => {
	it( 'round-trips a secret and never stores plaintext on disk', async () => {
		const vault = new Vault( { dir, key: KEY } );
		await vault.set( 'github', 'ghp_secret123' );
		expect( await vault.get( 'github' ) ).toBe( 'ghp_secret123' );

		const raw = await readFile( join( dir, 'vault.json' ), 'utf8' );
		expect( raw ).not.toContain( 'ghp_secret123' );
		expect( JSON.parse( raw ).encrypted ).toBe( true );
	} );

	it( 'returns undefined for an absent name', async () => {
		const vault = new Vault( { dir, key: KEY } );
		expect( await vault.get( 'missing' ) ).toBeUndefined();
	} );

	it( 'deletes, lists names only, and supports has()', async () => {
		const vault = new Vault( { dir, key: KEY } );
		await vault.set( 'github', 'a' );
		await vault.set( 'wp-app', 'b' );
		expect( await vault.has( 'github' ) ).toBe( true );
		expect( await vault.list() ).toEqual( [ 'github', 'wp-app' ] );
		await vault.delete( 'github' );
		expect( await vault.has( 'github' ) ).toBe( false );
		expect( await vault.list() ).toEqual( [ 'wp-app' ] );
	} );

	it( 'fails closed on a wrong key', async () => {
		const vault = new Vault( { dir, key: KEY } );
		await vault.set( 'github', 'ghp_secret123' );
		const wrong = new Vault( { dir, key: 'different-key' } );
		await expect( wrong.get( 'github' ) ).rejects.toThrow( VaultError );
	} );

	it( 'fails closed on tampered ciphertext', async () => {
		const vault = new Vault( { dir, key: KEY } );
		await vault.set( 'github', 'ghp_secret123' );
		const file = join( dir, 'vault.json' );
		const parsed = JSON.parse( await readFile( file, 'utf8' ) );
		// Flip the last base64 char of the ciphertext segment — a guaranteed
		// change to the encrypted data, so the GCM auth tag must reject it.
		const stored = parsed.entries.github as string;
		const segments = stored.split( '.' );
		const cipher = segments[ segments.length - 1 ];
		// Flip a char inside the first fully-encoded base64 group — the
		// trailing group can hold padding bits where a flip decodes back to
		// the same byte. Position 2 always encodes a real ciphertext byte.
		const flipped = cipher.charAt( 2 ) === 'A' ? 'B' : 'A';
		segments[ segments.length - 1 ] = cipher.slice( 0, 2 ) + flipped + cipher.slice( 3 );
		parsed.entries.github = segments.join( '.' );
		await writeFile( file, JSON.stringify( parsed ), 'utf8' );
		// A fresh instance (no in-memory cache) — tampering happens between
		// processes in reality, and the GCM auth tag must reject it.
		const fresh = new Vault( { dir, key: KEY } );
		await expect( fresh.get( 'github' ) ).rejects.toThrow( VaultError );
	} );

	it( 'refuses to open a plaintext file in encrypted mode (and vice versa)', async () => {
		const plain = new Vault( { dir, plaintext: true } );
		await plain.set( 'github', 'ghp_secret123' );
		const encrypted = new Vault( { dir, key: KEY } );
		await expect( encrypted.get( 'github' ) ).rejects.toThrow( VaultError );
		await expect( encrypted.list() ).rejects.toThrow( VaultError );
	} );

	it( 'throws without a key and without the plaintext opt-in', async () => {
		const vault = new Vault( { dir } );
		await expect( vault.set( 'github', 'x' ) ).rejects.toThrow( /NVOOS_FORGE_VAULT_KEY/ );
	} );

	it( 'rejects invalid entry names', async () => {
		const vault = new Vault( { dir, key: KEY } );
		await expect( vault.set( 'BAD NAME!', 'x' ) ).rejects.toThrow( VaultError );
		await expect( vault.set( '', 'x' ) ).rejects.toThrow( VaultError );
	} );

	it( 'writes the vault file with 0600 perms on POSIX', async () => {
		if ( process.platform === 'win32' ) {
			return;
		}
		const vault = new Vault( { dir, key: KEY } );
		await vault.set( 'github', 'x' );
		const mode = ( await stat( join( dir, 'vault.json' ) ) ).mode & 0o777;
		expect( mode ).toBe( 0o600 );
	} );

	it( 'uses NVOOS_FORGE_VAULT_KEY from the environment when no key option is given', async () => {
		process.env.NVOOS_FORGE_VAULT_KEY = KEY;
		const vault = new Vault( { dir } );
		await vault.set( 'github', 'env-key-secret' );
		expect( await vault.get( 'github' ) ).toBe( 'env-key-secret' );
	} );
} );

describe( 'Vault (plaintext fallback)', () => {
	it( 'round-trips and writes a .gitignore guard', async () => {
		process.env.NVOOS_FORGE_VAULT_PLAINTEXT = '1';
		const vault = new Vault( { dir } );
		await vault.set( 'github', 'ghp_secret123' );
		expect( await vault.get( 'github' ) ).toBe( 'ghp_secret123' );
		const raw = await readFile( join( dir, 'vault.json' ), 'utf8' );
		expect( raw ).toContain( 'ghp_secret123' );
		expect( JSON.parse( raw ).encrypted ).toBe( false );
		expect( await readFile( join( dir, '.gitignore' ), 'utf8' ) ).toBe( '*\n' );
	} );

	it( 'is explicit on disk — encrypted mode refuses to read it', async () => {
		process.env.NVOOS_FORGE_VAULT_PLAINTEXT = '1';
		const plain = new Vault( { dir } );
		await plain.set( 'github', 'x' );
		const encrypted = new Vault( { dir, key: KEY, plaintext: false } );
		await expect( encrypted.get( 'github' ) ).rejects.toThrow( /plaintext/ );
	} );
} );

describe( 'maskSecret', () => {
	it( 'masks long and short values and never round-trips', () => {
		expect( maskSecret( 'ghp_abcdef123456' ) ).toBe( '••••••56' );
		expect( maskSecret( 'ab' ) ).toBe( '••••' );
		expect( maskSecret( 'ghp_abcdef123456' ) ).not.toContain( 'abcdef' );
	} );
} );

describe( 'forgeHome', () => {
	it( 'honours NVOOS_FORGE_HOME and falls back to ~/.nvoos-forge', () => {
		expect( forgeHome() ).toContain( '.nvoos-forge' );
		process.env.NVOOS_FORGE_HOME = join( dir, 'custom' );
		expect( forgeHome() ).toBe( join( dir, 'custom' ) );
	} );
} );
