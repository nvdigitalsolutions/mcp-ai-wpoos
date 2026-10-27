/**
 * Workflow DSL export/import controls for the DAG Builder admin page
 * (Proposal 091, Track A).
 *
 * Uses the DSL REST routes (mcp-ai/v1/orchestration/workflows) with the
 * localized `mcpAiDagBuilder` config (restUrl + X-WP-Nonce). Imports run
 * dry-run first and only write after the findings pass.
 */
( function () {
	'use strict';

	const cfg = window.mcpAiDagBuilder || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	function download( text, filename ) {
		const blob = new Blob( [ text ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = filename;
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	function request( path, body, done ) {
		const xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.restUrl + path, true );
		xhr.setRequestHeader( 'Content-Type', 'application/json' );
		xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
		xhr.onreadystatechange = function () {
			if ( 4 !== xhr.readyState ) {
				return;
			}
			let data = {};
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				data = {};
			}
			done( xhr.status, data );
		};
		xhr.send( JSON.stringify( body || {} ) );
	}

	function reportFindings( findings ) {
		return findings
			.map( function ( f ) {
				return '[' + f.severity + '] ' + f.path + ' — ' + f.message;
			} )
			.join( '\n' );
	}

	document.addEventListener( 'click', function ( event ) {
		const el = event.target;
		if ( ! el.classList || ! el.classList.contains( 'mcp-ai-dag-export-btn' ) ) {
			return;
		}
		const id = el.getAttribute( 'data-workflow-id' );
		request( '/' + id + '/export', {}, function ( status, data ) {
			if ( 200 !== status ) {
				window.alert( 'Export failed.' ); // eslint-disable-line no-alert
				return;
			}
			download( JSON.stringify( data, null, 2 ), 'workflow-' + id + '.json' );
		} );
	} );

	const importBtn = document.querySelector( '.mcp-ai-dag-import-btn' );
	if ( importBtn ) {
		const fileInput = document.createElement( 'input' );
		fileInput.type = 'file';
		fileInput.accept = 'application/json,.json';
		fileInput.style.display = 'none';
		document.body.appendChild( fileInput );

		importBtn.addEventListener( 'click', function () {
			fileInput.click();
		} );

		fileInput.addEventListener( 'change', function () {
			const file = fileInput.files[ 0 ];
			if ( ! file ) {
				return;
			}
			const reader = new FileReader();
			reader.onload = function () {
				let dsl;
				try {
					dsl = JSON.parse( reader.result );
				} catch ( e ) {
					window.alert( 'File is not valid JSON.' ); // eslint-disable-line no-alert
					return;
				}
				request( '/import', { workflow_json: dsl, dry_run: true }, function ( status, data ) {
					if ( 200 !== status || ! data.dry_run ) {
						window.alert( 'Validation failed.' ); // eslint-disable-line no-alert
						return;
					}
					const blockers = ( data.findings || [] ).filter( function ( f ) {
						return 'error' === f.severity;
					} );
					if ( blockers.length ) {
						window.alert( 'Import blocked:\n\n' + reportFindings( blockers ) ); // eslint-disable-line no-alert
						return;
					}
					if ( ! window.confirm( 'Dry run passed. Import this workflow?' ) ) {
						return;
					}
					request( '/import', { workflow_json: dsl }, function ( status2 ) {
						if ( 200 === status2 ) {
							window.location.reload();
						} else {
							window.alert( 'Import failed.' ); // eslint-disable-line no-alert
						}
					} );
				} );
			};
			reader.readAsText( file );
		} );
	}
} )();
