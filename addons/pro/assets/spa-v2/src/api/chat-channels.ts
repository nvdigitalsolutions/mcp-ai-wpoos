/**
 * Pro SPA v2 — Chat Channels client (Proposal 084 Phase 4, W14).
 *
 * Consumes the existing mcp-ai-pro/v1/chat-channels surface for the
 * console's channel command center:
 *   GET  /chat-channels/conversations            — filtered conversation list
 *   GET  /chat-channels/conversations/{id}/messages — thread
 *   POST /chat-channels/reply                    — send a reply
 *
 * The REST controller predates this port; the client mirrors its
 * envelopes ({ items, total, page, per_page }).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.4.0
 */

import { __ } from '@wordpress/i18n';

export interface ChatConversation {
	id: number;
	channel: string;
	channel_contact_id: string;
	connection_id: string;
	conversation_type: string;
	display_name: string;
	bot_username: string;
	phone_number: string;
	email: string;
	tags: string[];
	crm_status: string;
	notes: string;
	assigned_agent: string;
	human_takeover: boolean;
	last_message_at: number;
	[ k: string ]: unknown;
}

export interface ConversationsEnvelope {
	items: ChatConversation[];
	total: number;
	page: number;
	per_page: number;
}

export interface ChatMessage {
	id?: number;
	sender?: string;
	direction?: string;
	message?: string;
	timestamp?: number;
	[ k: string ]: unknown;
}

export interface ChatChannelsClientOptions {
	endpoint: string;
	nonce: string;
}

export class ChatChannelsClient {
	private readonly endpoint: string;
	private readonly nonce: string;

	constructor( opts: ChatChannelsClientOptions ) {
		this.endpoint = opts.endpoint.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/** List conversations with optional filters. */
	async conversations(
		filters: { channel?: string; status?: string; search?: string } = {},
		signal?: AbortSignal
	): Promise< ConversationsEnvelope > {
		const params = new URLSearchParams( { page: '1', per_page: '50' } );
		if ( filters.channel ) {
			params.set( 'channel', filters.channel );
		}
		if ( filters.status ) {
			params.set( 'status', filters.status );
		}
		if ( filters.search ) {
			params.set( 'search', filters.search );
		}
		const data = await this.request< Partial< ConversationsEnvelope > >(
			'GET',
			`${ this.endpoint }/conversations?${ params.toString() }`,
			undefined,
			signal
		);
		return {
			items: Array.isArray( data?.items ) ? data.items : [],
			total: typeof data?.total === 'number' ? data.total : 0,
			page: typeof data?.page === 'number' ? data.page : 1,
			per_page: typeof data?.per_page === 'number' ? data.per_page : 50,
		};
	}

	/** Fetch one conversation's messages. */
	async messages( contactId: number, signal?: AbortSignal ): Promise< ChatMessage[] > {
		const data = await this.request< { items?: ChatMessage[] } >(
			'GET',
			`${ this.endpoint }/conversations/${ contactId }/messages`,
			undefined,
			signal
		);
		return Array.isArray( data?.items ) ? data.items : [];
	}

	/** Send a reply into a conversation. */
	async reply(
		contactId: number,
		message: string,
		connectionId: string,
		signal?: AbortSignal
	): Promise< void > {
		await this.request< unknown >(
			'POST',
			`${ this.endpoint }/reply`,
			{ contact_id: contactId, message, connection_id: connectionId },
			signal
		);
	}

	private async request< T >(
		method: 'GET' | 'POST',
		url: string,
		body?: unknown,
		signal?: AbortSignal
	): Promise< T > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
			'X-WP-Nonce': this.nonce,
		};
		if ( body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined ? JSON.stringify( body ) : undefined,
			signal,
		} );
		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string };
				detail = err?.message ?? '';
			} catch {
				// Not JSON — keep the generic message.
			}
			throw new Error( detail || __( 'Chat channels request failed.', 'nvoos-pro-spa' ) );
		}
		try {
			return ( await response.json() ) as T;
		} catch {
			return undefined as unknown as T;
		}
	}
}
