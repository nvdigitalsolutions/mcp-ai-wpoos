/**
 * Prompt versions metabox controls (Proposal 091, Track B).
 *
 * Restore / Promote buttons on the assistant edit screen; both actions hit
 * the prompt-versions REST routes with the localized config
 * (`window.wpMcpAiPromptVersions`), then reload the page.
 */
( function () {
	'use strict';

	var cfg = window.wpMcpAiPromptVersions || {};
	if ( ! cfg.restBase ) {
		return;
	}

	function request( version, action, done ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.restBase + '/' + version + '/' + action, true );
		xhr.setRequestHeader( 'Content-Type', 'application/json' );
		xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
		xhr.onreadystatechange = function () {
			if ( 4 !== xhr.readyState ) {
				return;
			}
			var data = {};
			try {
				data = JSON.parse( xhr.responseText );
			} catch ( e ) {
				data = {};
			}
			done( xhr.status, data );
		};
		xhr.send( '{}' );
	}

	function handle( event, action, confirmMessage ) {
		var el = event.target;
		if ( ! el.classList || ! el.classList.contains( 'wp-mcp-ai-prompt-' + action ) ) {
			return;
		}
		event.preventDefault();
		var version = el.getAttribute( 'data-version' );
		if ( window.confirm( confirmMessage + ' v' + version + '?' ) ) {
			request( version, action, function ( status ) {
				if ( 200 === status ) {
					window.location.reload();
				} else {
					window.alert( 'Action failed.' ); // eslint-disable-line no-alert
				}
			} );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		handle( event, 'restore', 'Restore prompt version' );
	} );
	document.addEventListener( 'click', function ( event ) {
		handle( event, 'promote', 'Promote prompt version' );
	} );
} )();
