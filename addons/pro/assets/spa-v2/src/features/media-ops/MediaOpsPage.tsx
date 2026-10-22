/**
 * MediaOpsPage — media library command-center metrics (Proposal 084
 * Phase 4, W14).
 *
 * Read-only health snapshot of the media library (attachments, storage,
 * alt-text/next-gen coverage, compression savings, processing queue,
 * schedule health) plus the compression-sweep trigger. Library mutations
 * (optimization, alt-text repair, cleanup) stay wp-admin.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	MediaOpsClient,
	formatBytes,
	formatPercent,
	type MediaHealthSnapshot,
} from '../../api/media-ops';

function useMediaOps( endpoint: string, nonce: string ): {
	snapshot: MediaHealthSnapshot | null;
	loading: boolean;
	error: string | null;
	refresh: () => void;
} {
	const [ snapshot, setSnapshot ] = useState< MediaHealthSnapshot | null >( null );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const client = useMemo(
		() => new MediaOpsClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const refresh = useCallback( () => {
		if ( ! endpoint ) {
			return;
		}
		setLoading( true );
		setError( null );
		client
			.health()
			.then( ( data ) => setSnapshot( data ) )
			.catch( ( err: Error ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [ client, endpoint ] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	return { snapshot, loading, error, refresh };
}

export function MediaOpsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { snapshot, loading, error, refresh } = useMediaOps(
		runtime?.endpoints.mediaCc ?? '',
		runtime?.nonce ?? ''
	);

	const [ sweepBusy, setSweepBusy ] = useState< boolean >( false );
	const [ sweepMessage, setSweepMessage ] = useState< string | null >( null );

	const runSweep = useCallback( () => {
		if ( ! runtime ) {
			return;
		}
		const client = new MediaOpsClient( {
			endpoint: runtime.endpoints.mediaCc ?? '',
			nonce: runtime.nonce,
		} );
		setSweepBusy( true );
		setSweepMessage( null );
		client
			.runCompressionSweep()
			.then( ( result ) => {
				setSweepMessage( result.message );
				refresh();
			} )
			.catch( ( err: Error ) => setSweepMessage( err.message ) )
			.finally( () => setSweepBusy( false ) );
	}, [ runtime, refresh ] );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Media Ops', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Collecting media library metrics…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime || ! snapshot ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Media Ops', 'nvoos-pro-spa' ) }</h2>
				{ error && <p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">{ error }</p> }
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const { attachments, queue, schedule } = snapshot;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Media Ops', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Media library health: coverage, storage, compression and background jobs.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Library coverage', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-records-detail__title">{ __( 'Library Coverage', 'nvoos-pro-spa' ) }</h3>
				<dl className="nvoos-pro-spa-records-detail__grid" role="list">
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Attachments', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( attachments.total ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Images', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( attachments.image_count ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Alt-text coverage', 'nvoos-pro-spa' ) }</dt>
						<dd>{ formatPercent( snapshot.alt_pct ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Next-gen formats', 'nvoos-pro-spa' ) }</dt>
						<dd>{ formatPercent( snapshot.nextgen_pct ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Descriptive filenames', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( attachments.descriptive_filename_count ) }</dd>
					</div>
				</dl>
			</section>

			<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Storage', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-records-detail__title">{ __( 'Storage', 'nvoos-pro-spa' ) }</h3>
				<dl className="nvoos-pro-spa-records-detail__grid" role="list">
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Used by attachments', 'nvoos-pro-spa' ) }</dt>
						<dd>{ formatBytes( snapshot.storage ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Compression savings', 'nvoos-pro-spa' ) }</dt>
						<dd>{ formatBytes( snapshot.compression ) }</dd>
					</div>
				</dl>
			</section>

			<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Processing queue', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-records-detail__title">{ __( 'Processing Queue', 'nvoos-pro-spa' ) }</h3>
				<dl className="nvoos-pro-spa-records-detail__grid" role="list">
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Pending', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( queue.pending ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Completed today', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( queue.completed_today ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Failed', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( queue.failed ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Total processed', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( queue.total_processed ) }</dd>
					</div>
				</dl>
			</section>

			<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Schedules', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-records-detail__title">{ __( 'Schedules', 'nvoos-pro-spa' ) }</h3>
				<dl className="nvoos-pro-spa-records-detail__grid" role="list">
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Active', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( schedule.active ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Ran today', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( schedule.ran_today ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Overdue', 'nvoos-pro-spa' ) }</dt>
						<dd>{ new Intl.NumberFormat( 'en-US' ).format( schedule.overdue ) }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item" role="listitem">
						<dt>{ __( 'Next run', 'nvoos-pro-spa' ) }</dt>
						<dd>{ schedule.next_run || '—' }</dd>
					</div>
				</dl>
			</section>

			<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Actions', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-records-detail__title">{ __( 'Actions', 'nvoos-pro-spa' ) }</h3>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ sweepBusy }
					onClick={ () => {
						void runSweep();
					} }
				>
					{ sweepBusy
						? __( 'Queuing…', 'nvoos-pro-spa' )
						: __( 'Run compression sweep', 'nvoos-pro-spa' ) }
				</button>
				{ sweepMessage && (
					<p className="nvoos-pro-spa-page__message" role="status">
						{ sweepMessage }
					</p>
				) }
			</section>
		</div>
	);
}
