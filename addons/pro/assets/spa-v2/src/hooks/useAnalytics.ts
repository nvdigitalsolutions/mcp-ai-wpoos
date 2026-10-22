/**
 * useAnalytics — Hook for the console Analytics page (Proposal 084 Phase 5b).
 *
 * Loads the site-wide cost surfaces (dashboard summary, trend, provider
 * breakdown) for the selected period. Mirrors the useDiagnostics shape.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import {
	AnalyticsClient,
	type CostChartData,
	type CostDashboardSummary,
} from '../api/analytics';

export interface UseAnalyticsOptions {
	/** mcp-ai/v1/cost base URL (empty for non-admin sessions). */
	endpoint: string;
	nonce: string;
	/** Period window in days (7/30/90). */
	days: number;
}

export interface UseAnalyticsReturn {
	summary: CostDashboardSummary | null;
	trend: CostChartData | null;
	byProvider: CostChartData | null;
	loading: boolean;
	error: string | null;
	refresh: () => Promise< void >;
}

export function useAnalytics( options: UseAnalyticsOptions ): UseAnalyticsReturn {
	const client = useMemo(
		() => new AnalyticsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ summary, setSummary ] = useState< CostDashboardSummary | null >( null );
	const [ trend, setTrend ] = useState< CostChartData | null >( null );
	const [ byProvider, setByProvider ] = useState< CostChartData | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ summaryData, trendData, providerData ] = await Promise.all( [
				client.summary( options.days ),
				client.trend( options.days ),
				client.byProvider( options.days ),
			] );
			setSummary( summaryData );
			setTrend( trendData );
			setByProvider( providerData );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint, options.days ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

	return { summary, trend, byProvider, loading, error, refresh };
}
