/**
 * Public status surface: allowlisted summary + Statuspage-style HTML page.
 *
 * The public shape is deliberately minimal — slug, status, message, and
 * last-seen timestamps only. Versions, latencies, component detail, and
 * internals require authentication (/api/status/*).
 */

const ALLOWED_PUBLIC_FIELDS = [ 'slug', 'status', 'message', 'last_heartbeat_at', 'checked_at', 'since' ];

/**
 * Reduce a full summary to its public (allowlisted) form.
 *
 * @param {Object} summary Full summary object.
 * @return {Object} Public summary.
 */
export function publicSummary( summary ) {
	const sites = ( summary.sites || [] ).map( ( site ) => {
		const out = {};
		for ( const field of ALLOWED_PUBLIC_FIELDS ) {
			if ( Object.prototype.hasOwnProperty.call( site, field ) ) {
				out[ field ] = site[ field ];
			}
		}
		return out;
	} );

	return {
		overall_status: summary.overall_status || 'unknown',
		generated_at: summary.generated_at || null,
		sites,
	};
}

const STATUS_LABELS = {
	operational: 'Operational',
	under_maintenance: 'Under Maintenance',
	degraded_performance: 'Degraded Performance',
	partial_outage: 'Partial Outage',
	major_outage: 'Major Outage',
	at_risk: 'At Risk',
	unknown: 'Unknown',
};

const STATUS_DOTS = {
	operational: '#2ecc71',
	under_maintenance: '#f1c40f',
	degraded_performance: '#f39c12',
	partial_outage: '#e67e22',
	major_outage: '#e74c3c',
	at_risk: '#f1c40f',
	unknown: '#95a5a6',
};

const escapeHtml = ( value ) =>
	String( value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );

/**
 * Render the Statuspage-style HTML page for a public summary.
 *
 * @param {Object} summary Public summary (see publicSummary()).
 * @return {string} HTML document.
 */
export function renderStatusPage( summary ) {
	const rows = ( summary.sites || [] )
		.map( ( site ) => {
			const label = STATUS_LABELS[ site.status ] || site.status;
			const color = STATUS_DOTS[ site.status ] || STATUS_DOTS.unknown;
			const lastSeen = site.last_heartbeat_at
				? new Date( site.last_heartbeat_at ).toISOString()
				: '—';
			return [
				'<tr>',
				`<td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${ color };margin-right:8px;"></span>${ escapeHtml( site.slug ) }</td>`,
				`<td>${ escapeHtml( label ) }</td>`,
				`<td>${ escapeHtml( site.message || '' ) }</td>`,
				`<td>${ escapeHtml( lastSeen ) }</td>`,
				'</tr>',
			].join( '' );
		} )
		.join( '\n' );

	const overallLabel = STATUS_LABELS[ summary.overall_status ] || summary.overall_status;

	return [
		'<!DOCTYPE html>',
		'<html lang="en">',
		'<head>',
		'<meta charset="utf-8">',
		'<meta name="viewport" content="width=device-width, initial-scale=1">',
		'<title>NV oOS Fleet Status</title>',
		'<style>',
		'body{font-family:system-ui,-apple-system,sans-serif;max-width:960px;margin:40px auto;padding:0 20px;color:#1a1a2e;background:#f7f8fa;}',
		'h1{font-size:1.6rem;}',
		'table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);}',
		'th,td{padding:12px 16px;text-align:left;border-bottom:1px solid #eee;}',
		'th{background:#fafbfc;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#666;}',
		'</style>',
		'</head>',
		'<body>',
		`<h1>NV oOS Fleet Status — ${ escapeHtml( overallLabel ) }</h1>`,
		'<table>',
		'<thead><tr><th>Site</th><th>Status</th><th>Message</th><th>Last Heartbeat (UTC)</th></tr></thead>',
		'<tbody>',
		rows,
		'</tbody>',
		'</table>',
		'</body>',
		'</html>',
	].join( '\n' );
}
