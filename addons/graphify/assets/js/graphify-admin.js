/**
 * NV oOS Graphify — Admin Dashboard JS
 *
 * Handles Build Graph button, report generation, and stats refresh.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

/* global nvoosGraphifyConfig */
( function () {
	'use strict';

	/**
	 * Make a REST API request.
	 *
	 * @param {string} endpoint Relative endpoint path.
	 * @param {Object} options  Fetch options override.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function apiRequest( endpoint, options ) {
		var defaults = {
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nvoosGraphifyConfig.nonce,
			},
		};

		var merged = Object.assign( {}, defaults, options || {} );

		if ( options && options.headers ) {
			merged.headers = Object.assign( {}, defaults.headers, options.headers );
		}

		return fetch( nvoosGraphifyConfig.restUrl + '/' + endpoint, merged )
			.then( function ( response ) {
				return response.json();
			} );
	}

	/**
	 * Update stat elements on the dashboard.
	 *
	 * @param {Object} stats Stats object from the API.
	 */
	function updateStats( stats ) {
		var nodeEl = document.getElementById( 'nvoos-graphify-stat-nodes' );
		var edgeEl = document.getElementById( 'nvoos-graphify-stat-edges' );
		var commEl = document.getElementById( 'nvoos-graphify-stat-communities' );
		var statusEl = document.getElementById( 'nvoos-graphify-stat-status' );

		if ( nodeEl && stats.node_count !== undefined ) {
			nodeEl.textContent = Number( stats.node_count ).toLocaleString();
		}
		if ( edgeEl && stats.edge_count !== undefined ) {
			edgeEl.textContent = Number( stats.edge_count ).toLocaleString();
		}
		if ( commEl && stats.community_count !== undefined ) {
			commEl.textContent = Number( stats.community_count ).toLocaleString();
		}
		if ( statusEl ) {
			var status = stats.build_status || 'idle';
			statusEl.textContent = status.charAt( 0 ).toUpperCase() + status.slice( 1 );
			statusEl.className = 'nvoos-graphify-status-badge nvoos-graphify-status-' + status;
		}
	}

	/**
	 * Set button loading state.
	 *
	 * @param {HTMLElement} btn   Button element.
	 * @param {boolean}     state Whether loading.
	 * @param {string}      label Original label.
	 */
	function setLoading( btn, state, label ) {
		if ( state ) {
			btn.disabled = true;
			btn.innerHTML = '<span class="nvoos-graphify-spinner"></span> Building\u2026';
		} else {
			btn.disabled = false;
			btn.textContent = label;
		}
	}

	/**
	 * Initialize dashboard event handlers.
	 */
	function initDashboard() {
		var buildBtn = document.getElementById( 'nvoos-graphify-build-btn' );
		var reportBtn = document.getElementById( 'nvoos-graphify-report-btn' );

		if ( buildBtn ) {
			buildBtn.addEventListener( 'click', function () {
				var originalLabel = buildBtn.textContent;
				setLoading( buildBtn, true, originalLabel );

				apiRequest( 'build', { method: 'POST' } )
					.then( function ( data ) {
						setLoading( buildBtn, false, originalLabel );

						if ( data && data.success ) {
							updateStats( data );
						}

						// Refresh full stats from graph meta.
						return apiRequest( 'graph' );
					} )
					.then( function ( meta ) {
						if ( meta ) {
							updateStats( meta );
						}
					} )
					.catch( function () {
						setLoading( buildBtn, false, originalLabel );
					} );
			} );
		}

		if ( reportBtn ) {
			reportBtn.addEventListener( 'click', function () {
				reportBtn.disabled = true;
				reportBtn.textContent = 'Loading\u2026';

				apiRequest( 'report' )
					.then( function ( data ) {
						reportBtn.disabled = false;
						reportBtn.textContent = 'View Report';

						if ( data && data.summary ) {
							updateStats( data.summary );
						}

						// Reload to show the fresh report in the PHP template.
						window.location.reload();
					} )
					.catch( function () {
						reportBtn.disabled = false;
						reportBtn.textContent = 'View Report';
					} );
			} );
		}
	}

	// Boot on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initDashboard );
	} else {
		initDashboard();
	}
} )();
