/**
 * CommerceToolkitPage — one page per commerce toolkit (Proposal 089).
 *
 * Tabs:
 *  - Overview  — toolkit description, quick links (Products, Connections),
 *                compliance notes (FlowHub read-mostly).
 *  - Settings  — the schema-driven settings form narrowed to this toolkit's
 *                tab (the enable toggle is pulled in from the base `tools`
 *                tab by key).
 *  - Sync Runs — read-only receipts from the sync-runs bridge.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import { SettingsForm } from '../settings/SettingsForm';
import { CommerceClient, type SyncRunSummary } from '../../api/commerce';

export interface CommerceToolkitConfig {
	key: 'ecommerce' | 'ezuite' | 'shopify_sync' | 'flowhub';
	title: string;
	description: string;
	/** Schema tab hosting this toolkit's guardrail fields. */
	settingsTab: string;
	/** Enable toggle key (owned by the base `tools` settings tab). */
	enableKey: string;
	/** Sync Log Manager slug backing the sync-runs bridge. */
	logSlug: string;
	/** Optional compliance / scope note rendered on the Overview tab. */
	note?: string;
	/** Optional route link (e.g. the Products records page). */
	link?: { label: string; href: string };
}

export const COMMERCE_TOOLKITS: Record< string, CommerceToolkitConfig > = {
	ecommerce: {
		key: 'ecommerce',
		title: __( 'E-Commerce', 'nvoos-pro-spa' ),
		description: __(
			'WooCommerce product, order, customer and inventory tooling with cross-channel sync guardrails.',
			'nvoos-pro-spa'
		),
		settingsTab: 'ecommerce',
		enableKey: 'enable_ecommerce_toolkit',
		logSlug: 'woocommerce',
		link: { label: __( 'Browse the product catalogue', 'nvoos-pro-spa' ), href: '#/products' },
	},
	ezuite: {
		key: 'ezuite',
		title: __( 'EZuite ERP', 'nvoos-pro-spa' ),
		description: __(
			'EZuite ERP inventory synchronisation with WooCommerce, including CCT caching and low-stock alerts.',
			'nvoos-pro-spa'
		),
		settingsTab: 'ezuite',
		enableKey: 'enable_ezuite_toolkit',
		logSlug: 'ezuite',
	},
	shopify_sync: {
		key: 'shopify_sync',
		title: __( 'Shopify Sync', 'nvoos-pro-spa' ),
		description: __(
			'Background Shopify↔WooCommerce inventory synchronisation with CCT caching and webhook intake.',
			'nvoos-pro-spa'
		),
		settingsTab: 'shopify_sync',
		enableKey: 'enable_shopify_sync_toolkit',
		logSlug: 'shopify_sync',
	},
	flowhub: {
		key: 'flowhub',
		title: __( 'FlowHub', 'nvoos-pro-spa' ),
		description: __( 'FlowHub POS inventory synchronisation with WooCommerce.', 'nvoos-pro-spa' ),
		settingsTab: 'flowhub',
		enableKey: 'enable_flowhub_toolkit',
		logSlug: 'flowhub',
		note: __(
			'Compliance traceability (Metrc/BioTrack) stays inside FlowHub — this console is read-mostly and every write is audit-logged.',
			'nvoos-pro-spa'
		),
	},
};

type CommerceTab = 'overview' | 'settings' | 'sync';

export function CommerceToolkitPage( { toolkit }: { toolkit: string } ): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const config = COMMERCE_TOOLKITS[ toolkit ];
	const [ tab, setTab ] = useState< CommerceTab >( 'overview' );

	const client = useMemo(
		() => new CommerceClient( { base: runtime?.proApi ?? '', nonce: runtime?.nonce ?? '' } ),
		[ runtime?.proApi, runtime?.nonce ]
	);

	if ( ! config ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Commerce', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Unknown commerce toolkit.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ config.title }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ config.title }</h2>
				<p className="nvoos-pro-spa-page__subtitle">{ config.description }</p>
			</header>

			<div className="nvoos-pro-spa-settings__tabs" role="tablist" aria-label={ config.title }>
				{ (
					[
						[ 'overview', __( 'Overview', 'nvoos-pro-spa' ) ],
						[ 'settings', __( 'Settings', 'nvoos-pro-spa' ) ],
						[ 'sync', __( 'Sync Runs', 'nvoos-pro-spa' ) ],
					] as const
				).map( ( [ tabKey, tabLabel ] ) => (
					<button
						key={ tabKey }
						type="button"
						role="tab"
						aria-selected={ tab === tabKey }
						className={
							tab === tabKey
								? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
								: 'nvoos-pro-spa-settings__tab'
						}
						onClick={ () => setTab( tabKey ) }
					>
						{ tabLabel }
					</button>
				) ) }
			</div>

			{ tab === 'overview' && (
				<section className="nvoos-pro-spa-settings__section" aria-label={ __( 'Overview', 'nvoos-pro-spa' ) }>
					<p className="nvoos-pro-spa-settings__hint">{ config.description }</p>
					{ config.note && <p className="nvoos-pro-spa-settings__hint">{ config.note }</p> }
					<p>
						<a className="nvoos-pro-spa-page__retry" href="#/connections">
							{ __( 'Manage remote connections', 'nvoos-pro-spa' ) }
						</a>
					</p>
					{ config.link && (
						<p>
							<a className="nvoos-pro-spa-page__retry" href={ config.link.href }>
								{ config.link.label }
							</a>
						</p>
					) }
				</section>
			) }

			{ tab === 'settings' && (
				<SettingsForm title={ config.title } tabFilter={ config.settingsTab } includeKeys={ [ config.enableKey ] } />
			) }

			{ tab === 'sync' && <SyncRunsPanel client={ client } toolkit={ config.logSlug } /> }
		</div>
	);
}

function SyncRunsPanel( { client, toolkit }: { client: CommerceClient; toolkit: string } ): JSX.Element {
	const [ runs, setRuns ] = useState< SyncRunSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			setRuns( await client.syncRuns( toolkit ) );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, toolkit ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	if ( loading ) {
		return (
			<div className="nvoos-pro-spa-page__loader" role="status">
				<span className="nvoos-pro-spa-page__spinner" />
				{ __( 'Loading sync runs…', 'nvoos-pro-spa' ) }
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
				<p>{ error }</p>
				<button type="button" className="nvoos-pro-spa-page__retry" onClick={ () => void load() }>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	if ( runs.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-tools-page__empty" role="status">
				<p>{ __( 'No sync runs recorded yet.', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	return (
		<section className="nvoos-pro-spa-settings__section" aria-label={ __( 'Sync runs', 'nvoos-pro-spa' ) }>
			<table className="nvoos-pro-spa-tools-page__table">
				<thead>
					<tr>
						<th>{ __( 'Run', 'nvoos-pro-spa' ) }</th>
						<th>{ __( 'Status', 'nvoos-pro-spa' ) }</th>
						<th>{ __( 'Updated', 'nvoos-pro-spa' ) }</th>
						<th>{ __( 'Skipped', 'nvoos-pro-spa' ) }</th>
						<th>{ __( 'Errored', 'nvoos-pro-spa' ) }</th>
						<th>{ __( 'Started', 'nvoos-pro-spa' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ runs.map( ( run ) => (
						<tr key={ run.run_id }>
							<td>{ run.run_id }</td>
							<td>{ run.status || '—' }</td>
							<td>{ run.summary.items_updated }</td>
							<td>{ run.summary.items_skipped }</td>
							<td>{ run.summary.items_errored }</td>
							<td>{ run.started_at }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}
