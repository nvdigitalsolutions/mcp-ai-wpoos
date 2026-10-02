/**
 * OpenMetrics (Prometheus text exposition) rendering for the status module.
 *
 * Counters are monotonic; gauges reflect the latest computed state. The
 * exposition follows the Prometheus text format: HELP/TYPE lines precede
 * each metric group. Endpoint: GET /api/status/metrics (authenticated).
 */

const escapeLabel = ( value ) => String( value ).replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );

const escapeHelp = ( value ) => String( value ).replace( /\\/g, '\\\\' ).replace( /\n/g, '\\n' );

/**
 * Render OpenMetrics text for a fleet summary + counters.
 *
 * @param {Object} summary   Fleet summary (see routes/status.js summary shape).
 * @param {Object} counters  Counter bag ({ heartbeats, synthetic, transitions }).
 * @return {string} OpenMetrics text.
 */
export function renderMetrics( summary, counters ) {
	const lines = [];

	lines.push( '# HELP nvoos_site_up Whether a monitored site is operational (1) or not (0).' );
	lines.push( '# TYPE nvoos_site_up gauge' );
	for ( const site of summary.sites ) {
		const up = 'operational' === site.status || 'under_maintenance' === site.status ? 1 : 0;
		lines.push( `nvoos_site_up{site="${ escapeLabel( site.slug ) }"} ${ up }` );
	}

	lines.push( '# HELP nvoos_site_status_severity Worst-status severity rank (0 unknown .. 6 major_outage).' );
	lines.push( '# TYPE nvoos_site_status_severity gauge' );
	for ( const site of summary.sites ) {
		lines.push( `nvoos_site_status_severity{site="${ escapeLabel( site.slug ) }"} ${ site.severity }` );
	}

	lines.push( '# HELP nvoos_heartbeat_age_s Seconds since the last accepted heartbeat.' );
	lines.push( '# TYPE nvoos_heartbeat_age_s gauge' );
	for ( const site of summary.sites ) {
		lines.push( `nvoos_heartbeat_age_s{site="${ escapeLabel( site.slug ) }"} ${ site.heartbeat_age_s }` );
	}

	lines.push( '# HELP nvoos_site_latency_ms Last synthetic check latency in milliseconds.' );
	lines.push( '# TYPE nvoos_site_latency_ms gauge' );
	for ( const site of summary.sites ) {
		if ( null !== site.latency_ms ) {
			lines.push( `nvoos_site_latency_ms{site="${ escapeLabel( site.slug ) }"} ${ site.latency_ms }` );
		}
	}

	lines.push( '# HELP nvoos_heartbeats_total Accepted heartbeats per site.' );
	lines.push( '# TYPE nvoos_heartbeats_total counter' );
	for ( const [ slug, count ] of Object.entries( counters.heartbeats || {} ) ) {
		lines.push( `nvoos_heartbeats_total{site="${ escapeLabel( slug ) }"} ${ count }` );
	}

	lines.push( '# HELP nvoos_synthetic_checks_total Synthetic check outcomes per site.' );
	lines.push( '# TYPE nvoos_synthetic_checks_total counter' );
	for ( const [ slug, outcomes ] of Object.entries( counters.synthetic || {} ) ) {
		for ( const [ result, count ] of Object.entries( outcomes ) ) {
			lines.push(
				`nvoos_synthetic_checks_total{site="${ escapeLabel( slug ) }",result="${ escapeLabel( result ) }"} ${ count }`
			);
		}
	}

	lines.push( '# HELP nvoos_status_transitions_total Status transitions observed per site.' );
	lines.push( '# TYPE nvoos_status_transitions_total counter' );
	for ( const [ slug, count ] of Object.entries( counters.transitions || {} ) ) {
		lines.push( `nvoos_status_transitions_total{site="${ escapeLabel( slug ) }"} ${ count }` );
	}

	lines.push( '# HELP nvoos_status_info Metadata about the status service itself.' );
	lines.push( '# TYPE nvoos_status_info gauge' );
	lines.push(
		`nvoos_status_info{version="${ escapeLabel( summary.version || '' ) }",sites="${ summary.sites.length }"} 1`
	);

	lines.push( '# EOF' );
	return lines.join( '\n' );
}

/** Re-export label escapers for tests. */
export { escapeLabel, escapeHelp };
