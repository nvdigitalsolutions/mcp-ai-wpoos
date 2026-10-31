// ---------------------------------------------------------------------------
// vault.ts — the forge credential store (Proposal 096 Phase 3.1).
//
// Secrets the deploy tools need (GitHub tokens, app passwords) never live in
// site profiles, the generated repo, or the CLI output. They live in an
// encrypted vault file under the forge home directory:
//
//   NVOOS_FORGE_HOME        override for the home dir (default ~/.nvoos-forge)
//   NVOOS_FORGE_VAULT_KEY   encryption passphrase (any length; hashed to the
//                           AES-256 key). Without it the vault refuses to
//                           open — there is no silent plaintext.
//   NVOOS_FORGE_VAULT_PLAINTEXT=1
//                           documented fallback: entries are stored in the
//                           clear (0600 file, gitignored home dir) — for
//                           fixtures and local convenience only.
//
// Every entry is encrypted independently (AES-256-GCM, random 96-bit IV,
// entry name as AAD). A wrong key or any tampering fails the GCM auth tag,
// so the store fails closed. The only value that ever leaves this module is
// the decrypted secret handed to the exact caller that requested it — the
// CLI prints masks, never secrets.
// ---------------------------------------------------------------------------

import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto';
import { mkdir, readFile, rename, unlink, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

/** Error type for vault failures (wrong key, corruption, plaintext guard). */
export class VaultError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'VaultError';
	}
}

/** Resolved vault configuration (env or explicit options). */
export interface VaultOptions {
	/** Vault directory. Default NVOOS_FORGE_HOME or ~/.nvoos-forge. */
	dir?: string;
	/** Encryption passphrase. Default NVOOS_FORGE_VAULT_KEY. */
	key?: string;
	/** Force plaintext storage (documented fallback). */
	plaintext?: boolean;
}

const FILE_VERSION = 1;
const AAD_PREFIX = 'forge-vault:v1:';
const FILE_NAME = 'vault.json';
const GITIGNORE_NAME = '.gitignore';
const SITES_NAME = 'sites.json';

/** On-disk shape. `encrypted` is recorded so plaintext files never decrypt. */
interface VaultFile {
	version: number;
	encrypted: boolean;
	entries: Record<string, string>;
}

/** Resolve the forge home directory (override first, then the user home). */
export function forgeHome(): string {
	return process.env.NVOOS_FORGE_HOME ?? join( homedir(), '.nvoos-forge' );
}

/** A secret's display mask — constant width, never reversible. */
export function maskSecret( value: string ): string {
	if ( value.length <= 2 ) {
		return '••••';
	}
	return '••••••' + value.slice( -2 );
}

/**
 * The encrypted credential vault. One instance per process is the norm;
 * every mutation reads-modifies-writes the single vault file.
 */
export class Vault {
	private readonly dir: string;
	private readonly key: Buffer | undefined;
	private readonly plaintext: boolean;
	private readonly file: string;
	private cache: VaultFile | undefined;

	constructor( options: VaultOptions = {} ) {
		this.dir = options.dir ?? forgeHome();
		this.plaintext = options.plaintext ?? process.env.NVOOS_FORGE_VAULT_PLAINTEXT === '1';
		const passphrase = options.key ?? process.env.NVOOS_FORGE_VAULT_KEY;
		this.key = passphrase ? createHash( 'sha256' ).update( passphrase, 'utf8' ).digest() : undefined;
		this.file = join( this.dir, FILE_NAME );
	}

	/** The vault directory (never the file contents). */
	get path(): string {
		return this.dir;
	}

	/** Store a secret under a name. */
	async set( name: string, value: string ): Promise<void> {
		this.assertName( name );
		const entries = await this.readEntries();
		entries[ name ] = this.encrypt( name, value );
		await this.writeFile( entries );
	}

	/** Read a secret back. Throws VaultError on a wrong key or corruption. */
	async get( name: string ): Promise<string | undefined> {
		this.assertName( name );
		const entries = await this.readEntries();
		const stored = entries[ name ];
		if ( stored === undefined ) {
			return undefined;
		}
		return this.decrypt( name, stored );
	}

	/** Whether a name exists (no decryption needed). */
	async has( name: string ): Promise<boolean> {
		this.assertName( name );
		const entries = await this.readEntries();
		return entries[ name ] !== undefined;
	}

	/** Remove a secret. */
	async delete( name: string ): Promise<void> {
		this.assertName( name );
		const entries = await this.readEntries();
		delete entries[ name ];
		await this.writeFile( entries );
	}

	/** Entry names only — never values. */
	async list(): Promise<string[]> {
		const entries = await this.readEntries();
		return Object.keys( entries ).sort();
	}

	/** Remove the vault file entirely (documented as unrecoverable). */
	async clear(): Promise<void> {
		this.cache = undefined;
		await unlink( this.file ).catch( () => undefined );
	}

	// -- internals ------------------------------------------------------------

	private assertName( name: string ): void {
		if ( !/^[a-z0-9][a-z0-9._-]{0,63}$/.test( name ) ) {
			throw new VaultError( `Invalid vault entry name: ${ name }` );
		}
	}

	private assertKey(): Buffer {
		if ( this.plaintext ) {
			// Plaintext fallback needs no key.
			return Buffer.alloc( 0 );
		}
		if ( ! this.key ) {
			throw new VaultError(
				'No vault key configured — set NVOOS_FORGE_VAULT_KEY ' +
					'(or NVOOS_FORGE_VAULT_PLAINTEXT=1 for the documented plaintext fallback).'
			);
		}
		return this.key;
	}

	private encrypt( name: string, value: string ): string {
		if ( this.plaintext ) {
			this.assertKey();
			return value;
		}
		const key = this.assertKey();
		const iv = randomBytes( 12 );
		const cipher = createCipheriv( 'aes-256-gcm', key, iv );
		cipher.setAAD( Buffer.from( AAD_PREFIX + name, 'utf8' ) );
		const ciphertext = Buffer.concat( [ cipher.update( value, 'utf8' ), cipher.final() ] );
		const tag = cipher.getAuthTag();
		return [ iv.toString( 'base64' ), tag.toString( 'base64' ), ciphertext.toString( 'base64' ) ].join( '.' );
	}

	private decrypt( name: string, stored: string ): string {
		if ( this.plaintext ) {
			return stored;
		}
		const key = this.assertKey();
		const parts = stored.split( '.' );
		if ( parts.length !== 3 ) {
			throw new VaultError( `Vault entry "${ name }" is corrupt.` );
		}
		const [ ivB64, tagB64, cipherB64 ] = parts;
		const decipher = createDecipheriv( 'aes-256-gcm', key, Buffer.from( ivB64, 'base64' ) );
		decipher.setAAD( Buffer.from( AAD_PREFIX + name, 'utf8' ) );
		decipher.setAuthTag( Buffer.from( tagB64, 'base64' ) );
		try {
			return Buffer.concat( [ decipher.update( Buffer.from( cipherB64, 'base64' ) ), decipher.final() ] ).toString( 'utf8' );
		} catch {
			throw new VaultError(
				`Cannot decrypt vault entry "${ name }" — wrong NVOOS_FORGE_VAULT_KEY or corrupted vault.`
			);
		}
	}

	private async readEntries(): Promise<Record<string, string>> {
		if ( this.cache ) {
			return this.cache.entries;
		}
		let raw: string;
		try {
			raw = await readFile( this.file, 'utf8' );
		} catch {
			// No vault yet — start empty.
			this.cache = { version: FILE_VERSION, encrypted: ! this.plaintext, entries: {} };
			return this.cache.entries;
		}
		let parsed: VaultFile;
		try {
			parsed = JSON.parse( raw ) as VaultFile;
		} catch {
			throw new VaultError( `Vault file ${ this.file } is not valid JSON.` );
		}
		if ( parsed.version !== FILE_VERSION || typeof parsed.entries !== 'object' || parsed.entries === null ) {
			throw new VaultError( `Vault file ${ this.file } has an unsupported format.` );
		}
		if ( parsed.encrypted === this.plaintext ) {
			throw new VaultError(
				`Vault file ${ this.file } was written in ${ parsed.encrypted ? 'encrypted' : 'plaintext' } mode — ` +
					'open it with the same configuration that created it.'
			);
		}
		this.cache = parsed;
		return parsed.entries;
	}

	private async writeFile( entries: Record<string, string> ): Promise<void> {
		const next: VaultFile = { version: FILE_VERSION, encrypted: ! this.plaintext, entries };
		await mkdir( this.dir, { recursive: true, mode: 0o700 } );
		// Guard the gitignored-home guarantee for the plaintext fallback.
		await writeFile( join( this.dir, GITIGNORE_NAME ), '*\n', { flag: 'w' } );
		// Atomic replace: temp file in the same dir, then rename over.
		const tmp = this.file + '.tmp';
		await writeFile( tmp, JSON.stringify( next, null, '\t' ) + '\n', { mode: 0o600 } );
		await rename( tmp, this.file );
		this.cache = next;
	}
}

/** Convenience: the sites registry lives next to the vault (not secret). */
export function sitesFile( dir: string = forgeHome() ): string {
	return join( dir, SITES_NAME );
}
