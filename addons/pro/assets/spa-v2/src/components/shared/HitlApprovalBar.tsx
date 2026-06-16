/**
 * Pro SPA v2 — HITL Approval Bar.
 *
 * A sticky notification bar that appears above the composer when there are
 * pending Human-in-the-Loop (HITL) approvals for the current assistant
 * session. Each card shows:
 *
 *   - The tool name being requested
 *   - A truncated summary of the tool arguments (JSON)
 *   - The reason string supplied by the orchestrator
 *   - "Approve" and "Deny" action buttons
 *
 * The component:
 *   - Polls every 6 s while `isStreaming` is true (the agentic loop is live).
 *   - Immediately re-polls after the streaming turn ends.
 *   - Stops polling when there are no pending items and streaming is idle.
 *   - Renders nothing when there are no pending approvals.
 *
 * Accessibility:
 *   - role="alert" so the bar is announced to screen-reader users.
 *   - Approve/Deny buttons have explicit aria-labels including the tool name.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
} from 'react';
import { HitlClient, type ApprovalRecord } from '../../api/hitl';

export interface HitlApprovalBarProps {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	/** Session key from useTranscripts — used to filter approvals. */
	sessionId?: string;
	/** True while useChat status is 'streaming' or 'submitted'. */
	isStreaming: boolean;
}

/** How often to poll while streaming is active (ms). */
const POLL_INTERVAL_MS = 6_000;

export function HitlApprovalBar( {
	endpoint,
	nonce,
	assistantId,
	sessionId,
	isStreaming,
}: HitlApprovalBarProps ): JSX.Element | null {
	const client = useMemo(
		() => new HitlClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const [ pending, setPending ] = useState< ApprovalRecord[] >( [] );
	const [ resolving, setResolving ] = useState< Record< number, boolean > >( {} );
	const abortRef = useRef< AbortController | null >( null );
	const timerRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	const fetchPending = useCallback(
		async ( signal: AbortSignal ) => {
			try {
				const list = await client.listPending(
					{
						assistantId: typeof assistantId === 'number' ? assistantId : undefined,
						sessionId: sessionId ?? undefined,
					},
					signal
				);
				if ( ! signal.aborted ) {
					setPending( list.filter( ( r ) => r.status === 'pending' ) );
				}
			} catch {
				// Silent — don't spam errors on a background poll.
			}
		},
		[ client, assistantId, sessionId ]
	);

	// Schedule the next poll cycle.
	const scheduleNext = useCallback(
		( signal: AbortSignal ) => {
			timerRef.current = setTimeout( () => {
				if ( ! signal.aborted ) void fetchPending( signal );
				if ( ! signal.aborted ) scheduleNext( signal );
			}, POLL_INTERVAL_MS );
		},
		[ fetchPending ]
	);

	// Start polling when streaming begins; also re-poll when streaming ends.
	useEffect( () => {
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		void fetchPending( controller.signal );

		if ( isStreaming ) {
			scheduleNext( controller.signal );
		}

		return () => {
			controller.abort();
			if ( timerRef.current !== null ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [ isStreaming, fetchPending, scheduleNext ] );

	const handleApprove = useCallback(
		async ( record: ApprovalRecord ) => {
			setResolving( ( r ) => ( { ...r, [ record.id ]: true } ) );
			try {
				await client.approve( record.id );
				setPending( ( prev ) => prev.filter( ( r ) => r.id !== record.id ) );
			} catch {
				// Surface the error without crashing.
			} finally {
				setResolving( ( r ) => {
					const next = { ...r };
					delete next[ record.id ];
					return next;
				} );
			}
		},
		[ client ]
	);

	const handleDeny = useCallback(
		async ( record: ApprovalRecord ) => {
			setResolving( ( r ) => ( { ...r, [ record.id ]: true } ) );
			try {
				await client.deny( record.id );
				setPending( ( prev ) => prev.filter( ( r ) => r.id !== record.id ) );
			} catch {
				// Surface the error without crashing.
			} finally {
				setResolving( ( r ) => {
					const next = { ...r };
					delete next[ record.id ];
					return next;
				} );
			}
		},
		[ client ]
	);

	if ( pending.length === 0 ) return null;

	return (
		<div
			className="nvoos-pro-spa-hitl-bar"
			role="alert"
			aria-label={ __( 'Pending approvals', 'nvoos-pro-spa' ) }
		>
			<p className="nvoos-pro-spa-hitl-bar-heading">
				{ sprintf(
					/* translators: %d: count of pending approvals */
					__( '\u26A0\uFE0F %d action(s) awaiting approval', 'nvoos-pro-spa' ),
					pending.length
				) }
			</p>
			<ul className="nvoos-pro-spa-hitl-list">
				{ pending.map( ( record ) => (
					<ApprovalCard
						key={ record.id }
						record={ record }
						isBusy={ !! resolving[ record.id ] }
						onApprove={ () => void handleApprove( record ) }
						onDeny={ () => void handleDeny( record ) }
					/>
				) ) }
			</ul>
		</div>
	);
}

interface ApprovalCardProps {
	record: ApprovalRecord;
	isBusy: boolean;
	onApprove: () => void;
	onDeny: () => void;
}

function ApprovalCard( {
	record,
	isBusy,
	onApprove,
	onDeny,
}: ApprovalCardProps ): JSX.Element {
	const argsSummary = useMemo( () => {
		try {
			const json = JSON.stringify( record.arguments, null, 0 );
			return json.length > 120 ? json.slice( 0, 117 ) + '…}' : json;
		} catch {
			return '';
		}
	}, [ record.arguments ] );

	return (
		<li className="nvoos-pro-spa-hitl-card">
			<div className="nvoos-pro-spa-hitl-card-meta">
				<strong className="nvoos-pro-spa-hitl-tool">{ record.tool }</strong>
				{ argsSummary && (
					<code className="nvoos-pro-spa-hitl-args">{ argsSummary }</code>
				) }
				{ record.reason && (
					<span className="nvoos-pro-spa-hitl-reason">{ record.reason }</span>
				) }
			</div>
			<div className="nvoos-pro-spa-hitl-card-actions">
				<button
					type="button"
					className="nvoos-pro-spa-hitl-approve"
					disabled={ isBusy }
					aria-label={
						/* translators: %s: tool name */
						sprintf( __( 'Approve %s', 'nvoos-pro-spa' ), record.tool )
					}
					onClick={ onApprove }
				>
					{ isBusy
						? __( '…', 'nvoos-pro-spa' )
						: __( '\u2713 Approve', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					className="nvoos-pro-spa-hitl-deny"
					disabled={ isBusy }
					aria-label={
						/* translators: %s: tool name */
						sprintf( __( 'Deny %s', 'nvoos-pro-spa' ), record.tool )
					}
					onClick={ onDeny }
				>
					{ isBusy
						? __( '…', 'nvoos-pro-spa' )
						: __( '\u2715 Deny', 'nvoos-pro-spa' ) }
				</button>
			</div>
		</li>
	);
}
