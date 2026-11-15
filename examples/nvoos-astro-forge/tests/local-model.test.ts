import { beforeEach, describe, expect, it } from 'vitest';

import { detectLocalModel, resetLocalModelCache } from '../lib/local-model.ts';

const TAGS = {
	models: [
		{ name: 'llama3.1:8b', capabilities: [ 'completion', 'tools' ] },
		{ name: 'qwen2.5vl:7b', capabilities: [ 'completion', 'vision' ] },
	],
};

const respond = ( body: unknown, ok = true ) =>
	( async () => ( { ok, json: async () => body } ) ) as unknown as typeof fetch;

const explode = ( async () => {
	throw new Error( 'ECONNREFUSED' );
} ) as unknown as typeof fetch;

describe( 'local model detection (Ollama)', () => {
	beforeEach( () => resetLocalModelCache() );

	it( 'reports the daemon and prefers a vision-capable model', async () => {
		const local = await detectLocalModel( {}, respond( TAGS ) );
		expect( local ).toMatchObject( {
			baseUrl: 'http://127.0.0.1:11434/v1',
			model: 'qwen2.5vl:7b',
			vision: true,
		} );
		expect( local?.models ).toEqual( [ 'llama3.1:8b', 'qwen2.5vl:7b' ] );
	} );

	it( 'honours an explicit model choice', async () => {
		const local = await detectLocalModel( { NVOOS_FORGE_OLLAMA_MODEL: 'llama3.1:8b' }, respond( TAGS ) );
		expect( local?.model ).toBe( 'llama3.1:8b' );
		expect( local?.vision ).toBe( false );
	} );

	it( 'honours a custom host and normalises a bare host:port', async () => {
		const local = await detectLocalModel( { OLLAMA_HOST: '127.0.0.1:9999' }, respond( TAGS ) );
		expect( local?.baseUrl ).toBe( 'http://127.0.0.1:9999/v1' );
	} );

	it( 'is absent (not an error) when the daemon refuses the connection', async () => {
		await expect( detectLocalModel( {}, explode ) ).resolves.toBeUndefined();
	} );

	it( 'is absent when the daemon answers with a non-200', async () => {
		await expect( detectLocalModel( {}, respond( {}, false ) ) ).resolves.toBeUndefined();
	} );

	it( 'is absent when no models are installed', async () => {
		await expect( detectLocalModel( {}, respond( { models: [] } ) ) ).resolves.toBeUndefined();
	} );

	it( 'can be switched off entirely', async () => {
		let called = false;
		const spy = ( async () => {
			called = true;
			return { ok: true, json: async () => TAGS };
		} ) as unknown as typeof fetch;
		await expect( detectLocalModel( { NVOOS_FORGE_NO_LOCAL: '1' }, spy ) ).resolves.toBeUndefined();
		expect( called ).toBe( false );
	} );

	it( 'probes once per process', async () => {
		let calls = 0;
		const counting = ( async () => {
			calls++;
			return { ok: true, json: async () => TAGS };
		} ) as unknown as typeof fetch;
		await detectLocalModel( {}, counting );
		await detectLocalModel( {}, counting );
		expect( calls ).toBe( 1 );
	} );
} );
