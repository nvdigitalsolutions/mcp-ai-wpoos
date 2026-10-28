/**
 * GatewayPage — fleet gateway catalog & policy view for the console shell.
 *
 * Read-only surface over the gateway's operator endpoints
 * (`GET /admin/catalog`, `GET /admin/policy`) — Proposal 094, Phase 5.
 * The gateway admin URL + operator token come from the host-injected
 * `window.NVOOS_GATEWAY` config (the site's vault holds the token in real
 * deployments; the SPA never persists it). Unconfigured → empty state with
 * the env contract.
 */

import { useCallback, useEffect, useState, type JSX } from 'react';

interface GatewaySite {
	slug: string;
	url: string;
	lifecycle: 'draft' | 'test' | 'published' | 'retired';
	health: string;
	lastOkAt: string | null;
	toolsAreNamespacedAs: string;
}

interface GatewayCatalog {
	gateway: {
		name: string;
		version: string;
		registryId: string;
		lifecycle: string;
	};
	sites: GatewaySite[];
}

interface QuotaRow {
	pattern: string;
	rate: number;
	window_seconds: number;
	burst: number;
}

interface PolicyView {
	policy: { rules: Array<{ id: string; effect: string }> } | null;
	shadowPolicy: { rules: Array<{ id: string; effect: string }> } | null;
	quotas: QuotaRow[];
}

interface GatewayConfig {
	adminUrl: string;
	adminToken: string;
}

const badgeColor: Record<string, string> = {
	draft: '#b45309',
	test: '#2b6cb0',
	published: '#15803d',
	retired: '#718096',
};

function readGatewayConfig(): GatewayConfig | null {
	const raw = ( window as unknown as { NVOOS_GATEWAY?: Partial<GatewayConfig> } ).NVOOS_GATEWAY;
	if ( ! raw?.adminUrl || ! raw?.adminToken ) {
		return null;
	}
	return { adminUrl: raw.adminUrl, adminToken: raw.adminToken };
}

async function fetchJson<T>( url: string, token: string ): Promise<T> {
	const res = await fetch( url, { headers: { Authorization: `Bearer ${ token }` } } );
	if ( ! res.ok ) {
		throw new Error( `Gateway responded ${ res.status }` );
	}
	return ( await res.json() ) as T;
}

export function GatewayPage(): JSX.Element {
	const [ config ] = useState<GatewayConfig | null>( readGatewayConfig );
	const [ catalog, setCatalog ] = useState<GatewayCatalog | null>( null );
	const [ policy, setPolicy ] = useState<PolicyView | null>( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	const load = useCallback( async (): Promise<void> => {
		if ( ! config ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ catalogData, policyData ] = await Promise.all( [
				fetchJson<GatewayCatalog>( `${ config.adminUrl.replace( /\/$/, '' ) }/admin/catalog`, config.adminToken ),
				fetchJson<PolicyView>( `${ config.adminUrl.replace( /\/$/, '' ) }/admin/policy`, config.adminToken ),
			] );
			setCatalog( catalogData );
			setPolicy( policyData );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : 'Gateway request failed.' );
		} finally {
			setLoading( false );
		}
	}, [ config ] );

	useEffect( () => {
		void load();
	}, [ load ] );

	if ( ! config ) {
		return (
			<section aria-label="Gateway">
				<h2>Gateway</h2>
				<p className="nvoos-gateway-empty">
					The fleet gateway is not connected. Inject{' '}
					<code>window.NVOOS_GATEWAY = { '{ adminUrl, adminToken }' }</code> from the host
					(site vault holds the operator token) to surface the catalog and policy.
				</p>
			</section>
		);
	}

	return (
		<section aria-label="Gateway">
			<h2>Gateway</h2>
			{ loading && <p>Loading gateway…</p> }
			{ error && <p className="nvoos-gateway-error">⚠ { error }</p> }

			{ catalog && (
				<>
					<p>
						<strong>{ catalog.gateway.name }</strong> · v{ catalog.gateway.version } ·{' '}
						<code>{ catalog.gateway.registryId }</code>
					</p>
					<table className="nvoos-gateway-table">
						<thead>
							<tr>
								<th>Site</th>
								<th>Lifecycle</th>
								<th>Health</th>
								<th>Tools surface as</th>
							</tr>
						</thead>
						<tbody>
							{ catalog.sites.map( ( site ) => (
								<tr key={ site.slug }>
									<td>{ site.slug }</td>
									<td>
										<span
											className="nvoos-gateway-badge"
											style={ {
												color: badgeColor[ site.lifecycle ] ?? '#718096',
												borderColor: badgeColor[ site.lifecycle ] ?? '#718096',
											} }
										>
											{ site.lifecycle }
										</span>
									</td>
									<td>{ site.health }</td>
									<td>
										<code>{ site.toolsAreNamespacedAs }</code>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }

			{ policy && (
				<>
					<h3>Policy</h3>
					<p>
						{ policy.policy
							? `${ policy.policy.rules.length } live rule(s), ${ policy.shadowPolicy?.rules.length ?? 0 } shadow rule(s).`
							: 'No live policy — all calls allowed.' }
					</p>
					{ policy.policy && (
						<ul>
							{ policy.policy.rules.map( ( rule ) => (
								<li key={ rule.id }>
									<strong>{ rule.id }</strong> → { rule.effect }
								</li>
							) ) }
						</ul>
					) }
					<h3>Quotas</h3>
					{ policy.quotas.length ? (
						<table className="nvoos-gateway-table">
							<thead>
								<tr>
									<th>Pattern</th>
									<th>Rate</th>
									<th>Window (s)</th>
									<th>Burst</th>
								</tr>
							</thead>
							<tbody>
								{ policy.quotas.map( ( quota ) => (
									<tr key={ quota.pattern }>
										<td>
											<code>{ quota.pattern }</code>
										</td>
										<td>{ quota.rate }</td>
										<td>{ quota.window_seconds }</td>
										<td>{ quota.burst }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) : (
						<p>No per-tool quotas configured.</p>
					) }
				</>
			) }
		</section>
	);
}
