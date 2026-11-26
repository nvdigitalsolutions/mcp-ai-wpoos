// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * MessageView memo comparator — regression tests.
 *
 * Streaming rewrites only the trailing message object, so the memo must bail
 * out for every settled message on each delta. The comparator therefore
 * ignores fresh identities for props the agent panel rebuilds on every token
 * (`delegations`, `jobs`) and compares those collections by size instead.
 * These tests pin that behaviour so a future "just use shallow equality"
 * change cannot silently reintroduce the quadratic re-render.
 */

import { describe, it, expect } from 'vitest';
import type { Message } from '@ai-sdk/react';
import { areMessageViewPropsEqual, type MessageViewProps } from '../MessageView';

function message( id: string, content: string ): Message {
	return { id, role: 'assistant', content } as Message;
}

function baseProps(
	overrides: Partial< MessageViewProps > = {}
): MessageViewProps {
	return {
		message: message( 'm1', 'hello' ),
		index: 0,
		totalCount: 1,
		isStreaming: false,
		...overrides,
	};
}

describe( 'areMessageViewPropsEqual', () => {
	it( 'bails out when the message identity and scalars are unchanged', () => {
		const shared = message( 'm1', 'hello' );
		expect(
			areMessageViewPropsEqual(
				baseProps( { message: shared } ),
				baseProps( { message: shared } )
			)
		).toBe( true );
	} );

	it( 're-renders when the message object is replaced', () => {
		expect(
			areMessageViewPropsEqual(
				baseProps( { message: message( 'm1', 'hello' ) } ),
				baseProps( { message: message( 'm1', 'hello wor' ) } )
			)
		).toBe( false );
	} );

	it( 're-renders when scalar props change', () => {
		const shared = message( 'm1', 'hello' );
		for ( const change of [
			{ isStreaming: true },
			{ index: 2 },
			{ totalCount: 5 },
			{ feedback: 'up' as const },
		] ) {
			expect(
				areMessageViewPropsEqual(
					baseProps( { message: shared } ),
					baseProps( { message: shared, ...change } )
				)
			).toBe( false );
		}
	} );

	it( 'ignores fresh empty collection identities', () => {
		const shared = message( 'm1', 'hello' );
		expect(
			areMessageViewPropsEqual(
				baseProps( { message: shared, delegations: [], jobs: {} } ),
				baseProps( { message: shared, delegations: [], jobs: {} } )
			)
		).toBe( true );
	} );

	it( 're-renders when delegation notices appear', () => {
		const shared = message( 'm1', 'hello' );
		expect(
			areMessageViewPropsEqual(
				baseProps( { message: shared, delegations: [] } ),
				baseProps( {
					message: shared,
					delegations: [
						{ agentName: 'researcher', task: 'look it up' },
					] as never,
				} )
			)
		).toBe( false );
	} );

	it( 're-renders when the job map gains an entry', () => {
		const shared = message( 'm1', 'hello' );
		expect(
			areMessageViewPropsEqual(
				baseProps( { message: shared, jobs: {} } ),
				baseProps( {
					message: shared,
					jobs: {
						job1: { jobId: 'job1', status: 'running' },
					} as never,
				} )
			)
		).toBe( false );
	} );
} );
