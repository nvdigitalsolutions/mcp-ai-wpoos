/**
 * Pro SPA v2 — typed wrappers around `mcp-ai/v1/assistants`.
 */

import { __ } from '@wordpress/i18n';

export interface AssistantShortcut {
	label: string;
	payload: string;
	/** Optional tool slug. The server keeps it only when the tool exists. */
	tool?: string;
	description?: string;
	hidden?: boolean;
}

/** Per-tool override map: `tool slug → { mode: 'custom', shortcuts }`. */
export interface AssistantPrebuiltShortcuts {
	[ tool: string ]: {
		mode: 'custom';
		shortcuts: AssistantShortcut[];
	};
}

/** Per-tool visibility rule. A rule needs at least one of roles/groups/flags. */
export interface AssistantToolRoleRule {
	tool: string;
	roles?: string[];
	groups?: number[];
	flags?: string[];
}

/** Base-knowledge dataset preference (max 10). `dataset` is required. */
export interface AssistantPreferredDataset {
	dataset: string;
	name?: string;
	category?: string;
	priority?: string;
}

export interface AssistantAdvancedFields {
	fallback_chain: string[];
	adaptive_tool_cap: '' | 'on' | 'off';
	memory_files: number[];
	vector_store_id: string;
	corpus_name: string;
	prompt_caching: boolean;
	disable_tool_shortcuts: boolean;
	tool_shortcuts: AssistantShortcut[];
	tool_prebuilt_shortcuts: AssistantPrebuiltShortcuts;
	tool_role_rules: AssistantToolRoleRule[];
	external_action_id: string;
	external_action_type: '' | 'workflow' | 'assistant';
	required_capability: string;
	preferred_datasets: AssistantPreferredDataset[];
	/** Assigned Agent Skill names (`_wp_mcp_ai_skills`). */
	skills: string[];
	/** Progressive disclosure: inject a skill index, load full instructions on demand. */
	skills_progressive: boolean;
}

/**
 * Defaults matching the server read shape (WP `advanced` + Laravel
 * `advancedFields()`): empty string / empty list / false, never null.
 */
export function emptyAdvancedFields(): AssistantAdvancedFields {
	return {
		fallback_chain: [],
		adaptive_tool_cap: '',
		memory_files: [],
		vector_store_id: '',
		corpus_name: '',
		prompt_caching: false,
		disable_tool_shortcuts: false,
		tool_shortcuts: [],
		tool_prebuilt_shortcuts: {},
		tool_role_rules: [],
		external_action_id: '',
		external_action_type: '',
		required_capability: '',
		preferred_datasets: [],
		skills: [],
		skills_progressive: false,
	};
}

/**
 * Coerce a server payload into the typed shape.
 *
 * WordPress returns the same keys with type defaults; Laravel promotes them
 * from `meta`. Unknown or malformed values fall back to the default so a
 * partial record never breaks the form.
 */
export function normaliseAdvancedFields(
	raw: unknown
): AssistantAdvancedFields {
	const base = emptyAdvancedFields();

	if ( ! raw || typeof raw !== 'object' ) {
		return base;
	}

	const input = raw as Record< string, unknown >;

	const isRecord = ( value: unknown ): value is Record< string, unknown > =>
		!! value && typeof value === 'object' && ! Array.isArray( value );

	const text = ( value: unknown ): string =>
		typeof value === 'string' ? value : '';

	const stringList = ( value: unknown ): string[] =>
		Array.isArray( value )
			? value.filter( ( item ): item is string => typeof item === 'string' )
			: [];

	const intList = ( value: unknown ): number[] =>
		Array.isArray( value )
			? value
					.map( ( item ) => Number( item ) )
					.filter( ( item ) => Number.isInteger( item ) && item > 0 )
			: [];

	const shortcutList = ( value: unknown ): AssistantShortcut[] =>
		Array.isArray( value )
			? value.filter( isRecord ).map( ( raw ) => {
					const shortcut: AssistantShortcut = {
						label: text( raw.label ),
						payload: text( raw.payload ),
					};

					if ( typeof raw.tool === 'string' && raw.tool !== '' ) {
						shortcut.tool = raw.tool;
					}

					if (
						typeof raw.description === 'string' &&
						raw.description !== ''
					) {
						shortcut.description = raw.description;
					}

					if ( raw.hidden ) {
						shortcut.hidden = true;
					}

					return shortcut;
			  } )
			: [];

	const prebuiltMap = ( value: unknown ): AssistantPrebuiltShortcuts => {
		if ( ! isRecord( value ) ) {
			return {};
		}

		const map: AssistantPrebuiltShortcuts = {};

		Object.entries( value ).forEach( ( [ tool, settings ] ) => {
			if ( tool === '' || ! isRecord( settings ) ) {
				return;
			}

			map[ tool ] = {
				mode: 'custom',
				shortcuts: shortcutList( settings.shortcuts ),
			};
		} );

		return map;
	};

	const roleRules = ( value: unknown ): AssistantToolRoleRule[] =>
		Array.isArray( value )
			? value.filter( isRecord ).map( ( raw ) => {
					const rule: AssistantToolRoleRule = { tool: text( raw.tool ) };
					const roles = stringList( raw.roles );
					const groups = intList( raw.groups );
					const flags = stringList( raw.flags );

					if ( roles.length ) {
						rule.roles = roles;
					}

					if ( groups.length ) {
						rule.groups = groups;
					}

					if ( flags.length ) {
						rule.flags = flags;
					}

					return rule;
			  } )
			: [];

	const datasets = ( value: unknown ): AssistantPreferredDataset[] =>
		Array.isArray( value )
			? value
					.filter( isRecord )
					.map( ( raw ) => {
						const dataset: AssistantPreferredDataset = {
							dataset: text( raw.dataset ),
						};

						if ( typeof raw.name === 'string' && raw.name !== '' ) {
							dataset.name = raw.name;
						}

						if (
							typeof raw.category === 'string' &&
							raw.category !== ''
						) {
							dataset.category = raw.category;
						}

						if (
							typeof raw.priority === 'string' &&
							raw.priority !== ''
						) {
							dataset.priority = raw.priority;
						}

						return dataset;
					} )
					.filter( ( item ) => item.dataset !== '' )
			: [];

	return {
		fallback_chain: stringList( input.fallback_chain ),
		adaptive_tool_cap:
			input.adaptive_tool_cap === 'on' || input.adaptive_tool_cap === 'off'
				? input.adaptive_tool_cap
				: '',
		memory_files: intList( input.memory_files ),
		vector_store_id: text( input.vector_store_id ),
		corpus_name: text( input.corpus_name ),
		prompt_caching: Boolean( input.prompt_caching ),
		disable_tool_shortcuts: Boolean( input.disable_tool_shortcuts ),
		tool_shortcuts: shortcutList( input.tool_shortcuts ),
		tool_prebuilt_shortcuts: prebuiltMap( input.tool_prebuilt_shortcuts ),
		tool_role_rules: roleRules( input.tool_role_rules ),
		external_action_id: text( input.external_action_id ),
		external_action_type:
			input.external_action_type === 'workflow' ||
			input.external_action_type === 'assistant'
				? input.external_action_type
				: '',
		required_capability: text( input.required_capability ),
		preferred_datasets: datasets( input.preferred_datasets ),
		skills: stringList( input.skills ),
		skills_progressive: Boolean( input.skills_progressive ),
	};
}

export interface AssistantRecord {
	id: number;
	title: string;
	model?: string;
	provider?: string;
	temperature?: number;
	system_prompt?: string;
	/** Tool slugs enabled for this assistant (the REST `tools` field). */
	tools?: string[];
	/**
	 * Profession catalogue slugs (max 3) assigned to this assistant.
	 *
	 * Laravel returns these; WordPress stores profession post IDs in
	 * `_wp_mcp_ai_primary_roles` and does not expose them through this payload.
	 */
	primary_role_slugs?: string[];
	/**
	 * Advanced settings (090-B assistant edit parity). Both runtimes serve the
	 * same shape from `GET /assistants/{id}`; absent on legacy list rows.
	 */
	advanced?: AssistantAdvancedFields;
	description?: string;
	is_default?: boolean;
	is_preset?: boolean;
}

export interface AssistantsListResponse {
	assistants: AssistantRecord[];
	total: number;
}

export interface AssistantsClientOptions {
	endpoint: string;
	nonce: string;
}

export class AssistantsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: AssistantsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	async list( signal?: AbortSignal ): Promise< AssistantsListResponse > {
		const url = new URL( this.endpoint, window.location.origin );
		url.searchParams.set( 'per_page', '100' );
		// The REST endpoint returns { assistants: [...], default_assistant, rest } directly.
		const data = await this.request< AssistantsListResponse >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return {
			assistants: Array.isArray( data?.assistants ) ? data.assistants : [],
			total: typeof data?.total === 'number' ? data.total : 0,
		};
	}

	async get( id: number, signal?: AbortSignal ): Promise< AssistantRecord > {
		const url = new URL( `${ this.endpoint }/${ id }`, window.location.origin );
		const data = await this.request< { success: boolean; data: AssistantRecord } >( {
			method: 'GET',
			url: url.toString(),
			signal,
		} );
		return data?.data ?? ( {} as AssistantRecord );
	}

	async update(
		id: number,
		changes: Partial< AssistantRecord >,
		signal?: AbortSignal
	): Promise< AssistantRecord > {
		const data = await this.request< { success: boolean; data: AssistantRecord } >( {
			method: 'PUT',
			url: `${ this.endpoint }/${ id }`,
			body: changes,
			signal,
		} );
		return data?.data ?? ( {} as AssistantRecord );
	}

	async create(
		fields: Partial< AssistantRecord >,
		signal?: AbortSignal
	): Promise< AssistantRecord > {
		// The create handler returns the directory summary directly (201),
		// not a { success, data } envelope.
		return this.request< AssistantRecord >( {
			method: 'POST',
			url: this.endpoint,
			body: fields,
			signal,
		} );
	}

	async delete( id: number, signal?: AbortSignal ): Promise< void > {
		await this.request( {
			method: 'DELETE',
			url: `${ this.endpoint }/${ id }`,
			signal,
		} );
	}

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST' | 'PUT' | 'DELETE';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		if ( req.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const response = await fetch( req.url, {
			method: req.method,
			credentials: 'same-origin',
			headers,
			body: req.body !== undefined ? JSON.stringify( req.body ) : undefined,
			signal: req.signal,
		} );
		if ( ! response.ok ) {
			throw new Error(
				__( 'Assistants request failed.', 'nvoos-pro-spa' )
			);
		}
		if ( response.status === 204 ) {
			return undefined as unknown as T;
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
