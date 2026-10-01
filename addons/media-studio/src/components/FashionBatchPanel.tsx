/**
 * NV oOS Media Studio — Fashion Batch Panel (Pro-backed).
 *
 * Batch job creation + review queue UI for the fashion-studio mode.
 * Only rendered when `/ai/capabilities` reports `batch: true` (the Pro
 * addon registers the `/ai/jobs*` routes and implementations).
 *
 * @since 0.3.0
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';
import {
	aiApi,
	type AiApiErrorShape,
	type BatchVariant,
	type FashionJob,
	type ReviewInfo,
} from '../hooks/useAiApi';

interface FashionBatchPanelProps {
	transform: string;
	description: string;
	color: string;
	backgroundStyle: string;
	aspectRatio: string;
	identityId: number;
}

const VARIANT_STATUS_LABELS: Record< string, string > = {
	pending: __( 'Queued', 'nvoos-media-studio' ),
	generated: __( 'Ready for review', 'nvoos-media-studio' ),
	approved: __( 'Approved', 'nvoos-media-studio' ),
	rejected: __( 'Rejected', 'nvoos-media-studio' ),
	replaced: __( 'Re-rolled', 'nvoos-media-studio' ),
	failed: __( 'Failed', 'nvoos-media-studio' ),
};

export function FashionBatchPanel( {
	transform,
	description,
	color,
	backgroundStyle,
	aspectRatio,
	identityId,
}: FashionBatchPanelProps ) {
	const [ jobs, setJobs ] = useState< FashionJob[] >( [] );
	const [ sourceIds, setSourceIds ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ review, setReview ] = useState< ReviewInfo | null >( null );
	const [ ackNeeded, setAckNeeded ] = useState( false );
	const [ expanded, setExpanded ] = useState< number | null >( null );

	const announce = useCallback( ( message: string ) => setStatus( message ), [] );

	const refreshJobs = useCallback(
		( fullJobId: number | null = null ) => {
			aiApi
				.listJobs()
				.then( setJobs )
				.catch( ( error: Error ) => announce( error.message ) );
			if ( fullJobId ) {
				aiApi
					.getJob( fullJobId )
					.then( ( full ) =>
						setJobs( ( current ) =>
							current.map( ( job ) => ( job.id === fullJobId ? full : job ) )
						)
					)
					.catch( () => {
						/* Job may have vanished between list and get — ignore. */
					} );
			}
		},
		[ announce ]
	);

	useEffect( () => {
		refreshJobs();
	}, [ refreshJobs ] );

	// Auto-refresh while any visible job is still processing.
	const processing = jobs.some( ( job ) => job.status === 'processing' || job.status === 'pending' );
	useEffect( () => {
		if ( ! processing ) {
			return;
		}
		const timer = window.setInterval( () => refreshJobs( expanded ), 8000 );
		return () => window.clearInterval( timer );
	}, [ processing, expanded, refreshJobs ] );

	const toggleExpand = useCallback(
		( jobId: number ) => {
			if ( expanded === jobId ) {
				setExpanded( null );
				return;
			}
			setExpanded( jobId );
			refreshJobs( jobId );
		},
		[ expanded, refreshJobs ]
	);

	const buildJobArgs = useCallback(
		( overrides: Partial< Parameters< typeof aiApi.createJob >[ 0 ] > = {} ) => {
			const ids = sourceIds
				.split( ',' )
				.map( ( value ) => parseInt( value.trim(), 10 ) )
				.filter( ( value ) => Number.isFinite( value ) && value > 0 );
			return {
				attachment_ids: [ ...new Set( ids ) ],
				transform,
				description,
				color,
				background_style: backgroundStyle,
				aspect_ratio: aspectRatio,
				identity_id: identityId > 0 ? identityId : undefined,
				...overrides,
			};
		},
		[ sourceIds, transform, description, color, backgroundStyle, aspectRatio, identityId ]
	);

	const runCreate = useCallback(
		async ( args: Parameters< typeof aiApi.createJob >[ 0 ] ) => {
			setBusy( true );
			setReview( null );
			setAckNeeded( false );
			try {
				const job = await aiApi.createJob( args );
				announce( __( 'Batch job created.', 'nvoos-media-studio' ) );
				setJobs( ( current ) => [ job, ...current.filter( ( item ) => item.id !== job.id ) ] );
				setSourceIds( '' );
				refreshJobs( job.id );
			} catch ( error ) {
				const apiError = error as AiApiErrorShape;
				if ( apiError.code === 'nvoos_ms_review_required' && apiError.review ) {
					setReview( apiError.review );
				} else if ( apiError.code === 'nvoos_ms_ack_required' ) {
					setAckNeeded( true );
				}
				announce( apiError.message ?? __( 'Batch creation failed.', 'nvoos-media-studio' ) );
			} finally {
				setBusy( false );
			}
		},
		[ announce ]
	);

	const handleCreate = useCallback( () => {
		const args = buildJobArgs();
		if ( args.attachment_ids.length === 0 ) {
			announce( __( 'Enter at least one source attachment ID.', 'nvoos-media-studio' ) );
			return;
		}
		void runCreate( args );
	}, [ buildJobArgs, runCreate, announce ] );

	const handleConfirm = useCallback( () => {
		void runCreate( buildJobArgs( { confirmed: true } ) );
	}, [ buildJobArgs, runCreate ] );

	const handleAck = useCallback( () => {
		void runCreate( buildJobArgs( { acknowledged: true } ) );
	}, [ buildJobArgs, runCreate ] );

	const handleVariantAction = useCallback(
		( jobId: number, variantKey: number, action: 'approve' | 'reject' | 'reroll' ) => {
			setBusy( true );
			aiApi
				.reviewJob( jobId, { variant_key: variantKey, action } )
				.then( () => {
					announce( __( 'Review saved.', 'nvoos-media-studio' ) );
					refreshJobs( jobId );
				} )
				.catch( ( error: Error ) => announce( error.message ) )
				.finally( () => setBusy( false ) );
		},
		[ announce, refreshJobs ]
	);

	const renderVariant = ( job: FashionJob, variant: BatchVariant ) => (
		<li
			key={ variant.key }
			className={ 'nvoos-ms-fs-variant nvoos-ms-fs-variant--' + variant.status }
		>
			{ variant.url ? (
				<img className="nvoos-ms-fs-result-img" src={ variant.url } alt={ __( 'Batch variant', 'nvoos-media-studio' ) } />
			) : (
				<div className="nvoos-ms-fs-variant-placeholder">
					{ __( 'Source', 'nvoos-media-studio' ) } #{ variant.source_id }
				</div>
			) }
			<div className="nvoos-ms-fs-chip">
				<span className="nvoos-ms-fs-chip-item">
					{ VARIANT_STATUS_LABELS[ variant.status ] ?? variant.status }
				</span>
				{ variant.export_error && (
					<span className="nvoos-ms-fs-chip-item nvoos-ms-fs-chip-item--warn">
						{ variant.export_error }
					</span>
				) }
			</div>
			{ variant.error && (
				<p className="nvoos-ms-error" role="alert">
					{ variant.error }
				</p>
			) }
			<div className="nvoos-ms-fs-result-actions">
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => handleVariantAction( job.id, variant.key, 'approve' ) }
					disabled={ busy || variant.status !== 'generated' }
				>
					{ __( 'Approve', 'nvoos-media-studio' ) }
				</button>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => handleVariantAction( job.id, variant.key, 'reject' ) }
					disabled={ busy || ( variant.status !== 'generated' && variant.status !== 'approved' ) }
				>
					{ __( 'Reject', 'nvoos-media-studio' ) }
				</button>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn"
					onClick={ () => handleVariantAction( job.id, variant.key, 'reroll' ) }
					disabled={ busy || ( variant.status !== 'generated' && variant.status !== 'approved' && variant.status !== 'failed' ) }
				>
					{ __( 'Re-roll', 'nvoos-media-studio' ) }
				</button>
			</div>
		</li>
	);

	return (
		<section className="nvoos-ms-fs-panel" aria-label={ __( 'Batch jobs', 'nvoos-media-studio' ) }>
			<h2 className="nvoos-ms-fs-heading">{ __( 'Batch jobs', 'nvoos-media-studio' ) }</h2>

			<div className="nvoos-ms-fs-source-actions">
				<label className="nvoos-ms-fs-inline">
					<span className="screen-reader-text">{ __( 'Source attachment IDs', 'nvoos-media-studio' ) }</span>
					<input
						type="text"
						value={ sourceIds }
						onChange={ ( event ) => setSourceIds( event.target.value ) }
						placeholder={ __( 'Source attachment IDs (comma-separated)', 'nvoos-media-studio' ) }
						className="nvoos-ms-fs-batch-ids"
					/>
				</label>
				<button
					type="button"
					className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
					onClick={ handleCreate }
					disabled={ busy }
				>
					{ busy ? __( 'Creating…', 'nvoos-media-studio' ) : __( 'Create batch', 'nvoos-media-studio' ) }
				</button>
				<button type="button" className="nvoos-ms-toolbar-btn" onClick={ () => refreshJobs() } disabled={ busy }>
					{ __( 'Refresh', 'nvoos-media-studio' ) }
				</button>
			</div>

			{ review && (
				<div className="nvoos-ms-fs-confirm" role="dialog" aria-label={ __( 'Review required', 'nvoos-media-studio' ) }>
					<p>{ __( 'This batch job requires review before running.', 'nvoos-media-studio' ) }</p>
					{ review.estimate_usd !== null && (
						<p>
							{ __( 'Estimated cost:', 'nvoos-media-studio' ) } ${ ' ' }
							{ review.estimate_usd.toFixed( 2 ) }
						</p>
					) }
					{ review.blocked ? (
						<p className="nvoos-ms-error" role="alert">
							{ __( 'This job is blocked by the configured hard cap.', 'nvoos-media-studio' ) }
						</p>
					) : (
						<button
							type="button"
							className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
							onClick={ handleConfirm }
							disabled={ busy }
						>
							{ __( 'Confirm and run', 'nvoos-media-studio' ) }
						</button>
					) }
					<button
						type="button"
						className="nvoos-ms-toolbar-btn"
						onClick={ () => setReview( null ) }
						disabled={ busy }
					>
						{ __( 'Cancel', 'nvoos-media-studio' ) }
					</button>
				</div>
			) }

			{ ackNeeded && (
				<div className="nvoos-ms-fs-confirm" role="dialog" aria-label={ __( 'Disclosure acknowledgment', 'nvoos-media-studio' ) }>
					<p>
						{ __(
							'I understand that AI face transforms produce synthetic content, that outputs carry provenance metadata and a visible AI-generated watermark, and that disclosure obligations apply when publishing.',
							'nvoos-media-studio'
						) }
					</p>
					<button
						type="button"
						className="nvoos-ms-toolbar-btn nvoos-ms-toolbar-btn--primary"
						onClick={ handleAck }
						disabled={ busy }
					>
						{ __( 'Acknowledge and run', 'nvoos-media-studio' ) }
					</button>
					<button
						type="button"
						className="nvoos-ms-toolbar-btn"
						onClick={ () => setAckNeeded( false ) }
						disabled={ busy }
					>
						{ __( 'Cancel', 'nvoos-media-studio' ) }
					</button>
				</div>
			) }

			{ jobs.length === 0 ? (
				<p className="nvoos-ms-fs-hint">
					{ __( 'No batch jobs yet. Enter source attachment IDs above to start one.', 'nvoos-media-studio' ) }
				</p>
			) : (
				<ul className="nvoos-ms-fs-jobs">
					{ jobs.map( ( job ) => (
						<li key={ job.id } className="nvoos-ms-fs-job">
							<button
								type="button"
								className="nvoos-ms-fs-job-head"
								aria-expanded={ expanded === job.id }
								onClick={ () => toggleExpand( job.id ) }
							>
								<span className="nvoos-ms-fs-chip-item">{ job.transform }</span>
								<span className="nvoos-ms-fs-chip-item">{ job.status }</span>
								<span className="nvoos-ms-fs-chip-item">
									{ job.done }/{ job.total }
								</span>
								{ job.estimate_usd !== null && (
									<span className="nvoos-ms-fs-chip-item">${ job.estimate_usd.toFixed( 2 ) }</span>
								) }
							</button>
							{ expanded === job.id && (
								<div className="nvoos-ms-fs-job-body">
									{ job.variants.length > 0 ? (
										<ul className="nvoos-ms-fs-variants">{ job.variants.map( ( variant ) => renderVariant( job, variant ) ) }</ul>
									) : (
										<p className="nvoos-ms-fs-hint">{ __( 'Loading variants…', 'nvoos-media-studio' ) }</p>
									) }
								</div>
							) }
						</li>
					) ) }
				</ul>
			) }

			<p className="nvoos-ms-status" role="status" aria-live="polite">
				{ status }
			</p>
		</section>
	);
}
