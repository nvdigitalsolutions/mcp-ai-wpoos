/**
 * Run Timeline admin page — JavaScript.
 *
 * Loads run summaries into the sidebar list, then loads full run detail
 * (steps, trace, cost) into the main panel when a run is selected.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const cfg = window.wpMcpAiRunTimeline || {};
	const ajax = cfg.ajaxUrl || '';
	const nonce = cfg.nonce || '';
	const i18n = cfg.i18n || {};

	let currentPage = 1;
	let currentAssistantId = '';
	let selectedRunId = null;

	/**
	 * Format milliseconds as a human-readable string.
	 *
	 * @param {number} ms
	 * @return {string}
	 */
	function formatMs( ms ) {
		if ( ms >= 1000 ) {
			return ( ms / 1000 ).toFixed( 2 ) + 's';
		}
		return ms + ' ' + i18n.ms;
	}

	/**
	 * Format USD cost.
	 *
	 * @param {number} usd
	 * @return {string}
	 */
	function formatUsd( usd ) {
		return '$' + usd.toFixed( 4 ) + ' ' + i18n.usd;
	}

	/**
	 * Format a Unix timestamp.
	 *
	 * @param {number} ts
	 * @return {string}
	 */
	function formatTime( ts ) {
		if ( ! ts ) {
			return '—';
		}
		return new Date( ts * 1000 ).toLocaleString();
	}

	/**
	 * Truncate a run ID for display.
	 *
	 * @param {string} runId
	 * @return {string}
	 */
	function shortRunId( runId ) {
		if ( ! runId ) {
			return '—';
		}
		const parts = runId.split( ':' );
		const last = parts[ parts.length - 1 ];
		return last.length > 12 ? last.slice( 0, 8 ) + '…' : last;
	}

	/**
	 * Extract a human-readable error message from a jQuery xhr object,
	 * falling back to a default string.
	 *
	 * @param {Object} xhr     jQuery XHR.
	 * @param {string} defaultMessage Default message.
	 * @return {string}
	 */
	function extractError( xhr, defaultMessage ) {
		if ( xhr && xhr.responseJSON ) {
			const r = xhr.responseJSON;
			if ( r.data && r.data.message ) {
				return r.data.message;
			}
			if ( r.message ) {
				return r.message;
			}
		}
		if ( xhr && typeof xhr.responseText === 'string' && xhr.responseText.length && xhr.responseText.length < 500 ) {
			return xhr.responseText;
		}
		return defaultMessage;
	}

	/**
	 * Fetch and render the run list.
	 */
	function loadRunList() {
		const $list = $( '#rt-run-list' );
		$list.html( '<li class="rt-loading">' + i18n.loading + '</li>' );

		$.get( ajax, {
			action: 'wp_mcp_ai_run_timeline_list_runs',
			nonce,
			page: currentPage,
			assistant_id: currentAssistantId,
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					$list.html( '<li class="rt-error">' + ( response.data && response.data.message ? response.data.message : 'Error' ) + '</li>' );
					return;
				}
				const data = response.data;
				if ( ! data.runs || ! data.runs.length ) {
					$list.html( '<li class="rt-empty">' + i18n.noRuns + '</li>' );
					renderPagination( 0, 1 );
					return;
				}
				renderRunList( data.runs );
				renderPagination( data.total, data.page );
			} )
			.fail( function ( xhr ) {
				const msg = extractError( xhr, 'Request failed.' );
				$list.html( $( '<li>' ).addClass( 'rt-error' ).text( msg ) );
			} );
	}

	/**
	 * Render run summaries into the sidebar list.
	 *
	 * @param {Array} runs
	 */
	function renderRunList( runs ) {
		const $list = $( '#rt-run-list' );
		$list.empty();
		runs.forEach( function ( run ) {
			const isSelected = run.run_id === selectedRunId;
			const $item = $( '<li>' )
				.addClass( 'rt-run-item' + ( isSelected ? ' rt-run-item--selected' : '' ) )
				.attr( 'data-run-id', run.run_id );

			const tokens = run.total_tokens ? run.total_tokens + ' ' + i18n.tokens : '—';
			const cost = run.cost_usd ? formatUsd( run.cost_usd ) : '—';
			const latency = run.latency_ms ? formatMs( run.latency_ms ) : '—';
			const when = formatTime( run.started_at );

			$item.html(
				'<span class="rt-run-id">' + shortRunId( run.run_id ) + '</span>' +
				'<span class="rt-run-when">' + when + '</span>' +
				'<span class="rt-run-tokens">' + tokens + '</span>' +
				'<span class="rt-run-cost">' + cost + '</span>' +
				'<span class="rt-run-latency">' + latency + '</span>'
			);

			$item.on( 'click', function () {
				selectedRunId = run.run_id;
				$( '.rt-run-item' ).removeClass( 'rt-run-item--selected' );
				$item.addClass( 'rt-run-item--selected' );
				loadRunDetail( run.run_id );
			} );

			$list.append( $item );
		} );
	}

	/**
	 * Render pagination buttons.
	 *
	 * @param {number} total
	 * @param {number} page
	 */
	function renderPagination( total, page ) {
		const perPage = 20;
		const pages = Math.ceil( total / perPage );
		const $pg = $( '#rt-pagination' ).empty();
		if ( pages <= 1 ) {
			return;
		}
		for ( let p = 1; p <= pages; p++ ) {
			const $btn = $( '<button>' )
				.addClass( 'button' + ( p === page ? ' button-primary' : '' ) )
				.text( p )
				.on( 'click', ( function ( pg ) {
					return function () {
						currentPage = pg;
						loadRunList();
					};
				} )( p ) );
			$pg.append( $btn );
		}
	}

	/**
	 * Fetch and render full detail for a run.
	 *
	 * @param {string} runId
	 */
	function loadRunDetail( runId ) {
		const $detail = $( '#rt-detail' ).html( '<div class="rt-loading">' + i18n.loading + '</div>' );

		$.get( ajax, {
			action: 'wp_mcp_ai_run_timeline_get_run',
			nonce,
			run_id: runId,
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					$detail.html( '<div class="rt-error">' + ( response.data && response.data.message ? response.data.message : 'Error' ) + '</div>' );
					return;
				}
				renderRunDetail( response.data );
			} )
			.fail( function ( xhr ) {
				const msg = extractError( xhr, 'Request failed.' );
				$detail.html( $( '<div>' ).addClass( 'rt-error' ).text( msg ) );
			} );
	}

	/**
	 * Render run detail.
	 *
	 * @param {Object} run
	 */
	function renderRunDetail( run ) {
		const $detail = $( '#rt-detail' ).empty();

		// Header.
		const $header = $( '<div>' ).addClass( 'rt-detail-header' );
		$header.append( $( '<h2>' ).text( 'Run: ' + shortRunId( run.run_id ) ) );

		// Download JSON button.
		const $dlBtn = $( '<button>' )
			.addClass( 'button' )
			.text( i18n.downloadJSON )
			.on( 'click', function () {
				const blob = new Blob( [ JSON.stringify( run, null, 2 ) ], { type: 'application/json' } );
				const url = URL.createObjectURL( blob );
				const $a = $( '<a>' ).attr( { href: url, download: 'run-' + run.run_id + '.json' } );
				$( 'body' ).append( $a );
				$a[ 0 ].click();
				$a.remove();
				URL.revokeObjectURL( url );
			} );
		$header.append( $dlBtn );
		$detail.append( $header );

		// Reasoning trace section.
		if ( run.trace && Object.keys( run.trace ).length ) {
			$detail.append( renderTrace( run.trace ) );
		}

		// Steps / metrics section.
		if ( run.steps && run.steps.length ) {
			$detail.append( renderSteps( run.steps ) );
		}

		if ( ! ( run.trace && Object.keys( run.trace ).length ) && ! ( run.steps && run.steps.length ) ) {
			$detail.append( $( '<p>' ).addClass( 'rt-empty' ).text( 'No detail data available for this run.' ) );
		}
	}

	/**
	 * Render a reasoning trace section.
	 *
	 * @param {Object} trace
	 * @return {jQuery}
	 */
	function renderTrace( trace ) {
		const $section = $( '<section>' ).addClass( 'rt-section' );
		$section.append( $( '<h3>' ).text( 'Reasoning Trace' ) );
		const $table = $( '<table>' ).addClass( 'rt-trace-table widefat' );
		const fields = [ 'task_class', 'assumptions', 'constraints', 'plan', 'intermediate_results', 'verification', 'answer' ];
		fields.forEach( function ( field ) {
			if ( ! trace[ field ] ) {
				return;
			}
			const val = Array.isArray( trace[ field ] ) ? trace[ field ].join( '; ' ) : String( trace[ field ] );
			if ( ! val ) {
				return;
			}
			const $row = $( '<tr>' );
			$row.append( $( '<th>' ).text( field.replace( /_/g, ' ' ) ) );
			$row.append( $( '<td>' ).text( val ) );
			$table.append( $row );
		} );
		$section.append( $table );
		return $section;
	}

	/**
	 * Render metric steps as a timeline.
	 *
	 * @param {Array} steps
	 * @return {jQuery}
	 */
	function renderSteps( steps ) {
		const $section = $( '<section>' ).addClass( 'rt-section' );
		$section.append( $( '<h3>' ).text( 'Metric Steps' ) );
		const $timeline = $( '<ol>' ).addClass( 'rt-timeline' );
		steps.forEach( function ( step ) {
			const $item = $( '<li>' ).addClass( 'rt-step' );
			$item.append( $( '<strong>' ).text( step.step || 'step' ) );
			if ( step.metrics && step.metrics.length ) {
				const $metrics = $( '<ul>' );
				step.metrics.forEach( function ( m ) {
					const label = m.id + ': ' + ( m.value !== null ? m.value : '—' ) + ( m.unit ? ' ' + m.unit : '' );
					$metrics.append( $( '<li>' ).text( label ) );
				} );
				$item.append( $metrics );
			}
			$timeline.append( $item );
		} );
		$section.append( $timeline );
		return $section;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	$( function () {
		loadRunList();

		$( '#rt-filter-assistant' ).on( 'change', function () {
			currentAssistantId = $( this ).val();
			currentPage = 1;
			selectedRunId = null;
			loadRunList();
			$( '#rt-detail' ).html(
				'<div class="rt-empty-state"><span class="dashicons dashicons-chart-area"></span><p>Select a run from the list to view its timeline.</p></div>'
			);
		} );
	} );
} )( jQuery );
