/**
 * Pro SPA v2 — CollaborativePresence.
 *
 * Polls a REST endpoint every 15 seconds to discover other users editing
 * the same content. Renders avatar chips and a descriptive label.
 *
 * Returns null when no other users are present.
 *
 * Accessibility:
 *   - The container uses role="status" for live-region awareness.
 *   - Each avatar `<img>` carries a descriptive alt attribute.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

export interface PresenceUser {
	user_id: number;
	display_name: string;
	avatar_url: string;
	activity: string;
}

export interface CollaborativePresenceProps {
	/** Full URL to the REST presence endpoint. */
	endpoint: string;
	/** WordPress REST nonce for the X-WP-Nonce header. */
	nonce: string;
	/** Current post ID, if any. */
	postId?: number;
	/** Current thread ID, if any. */
	threadId?: number;
}

/** How often to poll for presence updates (ms). */
const POLL_INTERVAL_MS = 15_000;

/**
 * Component that shows which other users are present on the same content.
 *
 * @param props          - Component properties.
 * @param props.endpoint - REST endpoint URL.
 * @param props.nonce    - WP REST nonce.
 * @param props.postId   - Post ID.
 * @param props.threadId - Thread ID.
 *
 * @returns The rendered presence bar, or null if no other users.
 */
export function CollaborativePresence( {
	endpoint,
	nonce,
	postId,
	threadId,
}: CollaborativePresenceProps ): JSX.Element | null {
	const [ presence, setPresence ] = useState< PresenceUser[] >( [] );
	const timerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	const fetchPresence = useCallback( async () => {
		try {
			const body = new URLSearchParams();
			if ( postId ) {
				body.append( 'post_id', String( postId ) );
			}
			if ( threadId ) {
				body.append( 'thread_id', String( threadId ) );
			}
			body.append( 'activity', 'active' );

			const res = await fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-WP-Nonce': nonce,
				},
				body: body.toString(),
			} );

			if ( ! res.ok ) return;

			const data: unknown = await res.json();
			if (
				data &&
				typeof data === 'object' &&
				'success' in data &&
				( data as { success: boolean } ).success &&
				'data' in data
			) {
				const payload = ( data as { data: { presence?: PresenceUser[] } } ).data;
				if ( payload?.presence ) {
					setPresence( payload.presence );
				}
			}
		} catch {
			// Non-critical — silently ignore.
		}
	}, [ endpoint, nonce, postId, threadId ] );

	useEffect( () => {
		if ( ! postId ) return;

		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		void fetchPresence();

		timerRef.current = setInterval( () => {
			void fetchPresence();
		}, POLL_INTERVAL_MS );

		return () => {
			controller.abort();
			if ( timerRef.current !== null ) {
				clearInterval( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [ postId, threadId, fetchPresence ] );

	if ( presence.length === 0 ) return null;

	return (
		<div
			className="nvoos-pro-spa-collab-presence"
			role="status"
			aria-label={ __( 'Collaborative presence', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-collab-presence__avatars">
				{ presence.slice( 0, 5 ).map( ( user ) => (
					<img
						key={ user.user_id }
						src={ user.avatar_url }
						alt={ sprintf(
							/* translators: %s: display name */
							__( 'Avatar for %s', 'nvoos-pro-spa' ),
							user.display_name
						) }
						className="nvoos-pro-spa-collab-presence__avatar"
						title={ sprintf(
							/* translators: 1: display name, 2: activity description */
							__( '%1$s — %2$s', 'nvoos-pro-spa' ),
							user.display_name,
							user.activity
						) }
					/>
				) ) }
				{ presence.length > 5 && (
					<span className="nvoos-pro-spa-collab-presence__more">
						+{ presence.length - 5 }
					</span>
				) }
			</div>
			<span className="nvoos-pro-spa-collab-presence__label">
				{ presence.length === 1
					? sprintf(
							/* translators: %s: display name */
							__( '%s is also here', 'nvoos-pro-spa' ),
							presence[ 0 ].display_name
					  )
					: sprintf(
							/* translators: %d: number of other users */
							__( '%d others here', 'nvoos-pro-spa' ),
							presence.length
					  ) }
			</span>
		</div>
	);
}
