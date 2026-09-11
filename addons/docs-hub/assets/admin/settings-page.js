/**
 * NV oOS Docs Hub — Admin Settings Page
 *
 * Scripts for the Settings → NV oOS Docs Hub page. Previously echoed as
 * inline <script> blocks in render_page(); moved to a static asset so the
 * page ships no inline scripts (WordPress.org plugin review feedback).
 *
 * Expectations:
 * - The rebuild panel (#nvoos-docs-hub-rebuild-panel) carries
 *   data-rest-base / data-rest-nonce / data-initial-state attributes.
 * - window.NVOOS_DH_SETTINGS_PAGE is set via wp_localize_script:
 *   {
 *     ajaxUrl:     string,  // admin-ajax.php URL
 *     importNonce: string,  // nvoos_docs_hub_import_settings nonce
 *     settings:    object,  // current plugin settings (tokens stripped on export)
 *     i18n: {
 *       importing:           string,
 *       imported:            string,
 *       importFailed:        string,
 *       importRequestFailed: string,
 *       invalidJson:         string
 *     }
 *   }
 *
 * @since 0.4.2
 */

// -------------------------------------------------------------------------
// Rebuild panel: start / resume / cancel buttons + 2 s polling loop.
// -------------------------------------------------------------------------
( function () {
	var panel = document.getElementById( 'nvoos-docs-hub-rebuild-panel' );
	if ( ! panel ) {
		return;
	}
	var base  = panel.getAttribute( 'data-rest-base' );
	var nonce = panel.getAttribute( 'data-rest-nonce' );
	var phaseEl    = panel.querySelector( '.nvoos-rebuild-phase' );
	var progressEl = panel.querySelector( '.nvoos-rebuild-progress' );
	var errorEl    = panel.querySelector( '.nvoos-rebuild-error' );
	var errorMsgEl = panel.querySelector( '.nvoos-rebuild-error-msg' );
	var startBtn   = panel.querySelector( '.nvoos-rebuild-start' );
	var resumeBtn  = panel.querySelector( '.nvoos-rebuild-resume' );
	var cancelBtn  = panel.querySelector( '.nvoos-rebuild-cancel' );
	var pollHandle = null;

	function applyState( state ) {
		if ( ! state ) {
			return;
		}
		phaseEl.textContent    = state.phase || '';
		progressEl.textContent = ( state.processed || 0 ) + ' / ' + ( state.total || 0 ) + ' (' + ( state.percentage || 0 ) + '%)';
		if ( state.last_error ) {
			errorEl.style.display = 'block';
			errorMsgEl.textContent = state.last_error;
		} else {
			errorEl.style.display = 'none';
		}
		var running = !! state.is_running;
		startBtn.disabled  = running;
		resumeBtn.disabled = running;
		cancelBtn.disabled = ! running;

		if ( running ) {
			if ( ! pollHandle ) {
				pollHandle = window.setInterval( pollStatus, 2000 );
			}
		} else if ( pollHandle ) {
			window.clearInterval( pollHandle );
			pollHandle = null;
		}
	}

	function call( path, method ) {
		return fetch( base + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function pollStatus() {
		call( '/rebuild/status', 'GET' )
			.then( applyState )
			.catch( function () {
				// Network blip — keep the polling loop alive and try again next tick.
			} );
	}

	function safeApply( promise ) {
		promise
			.then( applyState )
			.catch( function ( err ) {
				errorEl.style.display = 'block';
				errorMsgEl.textContent = ( err && err.message ) ? err.message : 'Request failed';
			} );
	}

	startBtn.addEventListener( 'click', function () {
		safeApply( call( '/rebuild', 'POST' ) );
	} );
	resumeBtn.addEventListener( 'click', function () {
		safeApply( call( '/rebuild/resume', 'POST' ) );
	} );
	cancelBtn.addEventListener( 'click', function () {
		safeApply( call( '/rebuild/cancel', 'POST' ) );
	} );

	try {
		applyState( JSON.parse( panel.getAttribute( 'data-initial-state' ) ) );
	} catch ( e ) {}
}() );

// -------------------------------------------------------------------------
// Dirty-state tracking: warn before leaving when the form has unsaved
// changes (repo rows added/removed, fields edited, etc.).
// -------------------------------------------------------------------------
( function () {
	var form = document.querySelector( 'form[action="options.php"]' );
	if ( ! form ) {
		return;
	}
	var isDirty = false;

	function markDirty() {
		if ( ! isDirty ) {
			isDirty = true;
			window.addEventListener( 'beforeunload', warnUnsaved );
		}
	}

	function warnUnsaved( e ) {
		e.preventDefault();
		e.returnValue = '';
		return '';
	}

	// Watch input/textarea/select changes.
	form.addEventListener( 'input', markDirty, { passive: true } );
	form.addEventListener( 'change', markDirty, { passive: true } );

	// The "Add Repository" button adds new rows via cloneNode() —
	// those fire DOM mutations but no input events on the form
	// itself, so we listen for click on the add button.
	var addBtn = document.getElementById( 'nvoos-dh-add-repo' );
	if ( addBtn ) {
		addBtn.addEventListener( 'click', markDirty );
	}

	// The "Remove this repository" button is handled via delegation
	// in repo-picker.js; we listen for the click on the wrapper.
	var wrap = document.getElementById( 'nvoos-dh-remote-repos-wrap' );
	if ( wrap ) {
		wrap.addEventListener( 'click', markDirty );
	}

	// Clear dirty flag on form submit so the warning doesn't fire
	// when the user intentionally saves.
	form.addEventListener( 'submit', function () {
		isDirty = false;
		window.removeEventListener( 'beforeunload', warnUnsaved );
	} );
}() );

// -------------------------------------------------------------------------
// Broken link fix buttons.
// -------------------------------------------------------------------------
( function () {
	var table  = document.querySelector( '#nvoos-docs-hub-broken-links-table table' );
	var fixAll = document.getElementById( 'nvoos-dh-fix-all' );
	var status = document.getElementById( 'nvoos-dh-fix-status' );
	if ( ! table ) {
		return;
	}

	// REST base/nonce live on the rebuild panel (always rendered on this page).
	var panel = document.getElementById( 'nvoos-docs-hub-rebuild-panel' );
	if ( ! panel ) {
		return;
	}
	var restBase  = panel.getAttribute( 'data-rest-base' );
	var restNonce = panel.getAttribute( 'data-rest-nonce' );

	function setStatus( msg, isError ) {
		if ( ! status ) {
			return;
		}
		status.textContent = msg;
		status.style.color  = isError ? '#a00' : '#008000';
	}

	function applyFixes( fixes, btn ) {
		if ( ! fixes.length ) {
			setStatus( 'No fixes to apply.', true );
			return;
		}

		if ( btn ) {
			btn.disabled = true;
		}
		setStatus( 'Applying...', false );

		fetch( restBase + '/fix-links', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': restNonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify( { fixes: fixes } )
		} )
		.then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			} );
		} )
		.then( function ( result ) {
			var data = result.data;
			if ( ! result.ok ) {
				setStatus( 'Error: ' + ( data.message || 'HTTP ' + result.status ), true );
				if ( btn ) {
					btn.disabled = false;
				}
				return;
			}

			// Annotate each row with its outcome: fade fixed rows,
			// show the server-provided reason on skipped rows so
			// "N skipped" is no longer a dead end.
			var rows = table.querySelectorAll( 'tbody tr' );
			var resultsArr = ( data.results && data.results.results ) ? data.results.results : [];
			resultsArr.forEach( function ( r ) {
				rows.forEach( function ( row ) {
					if ( row.getAttribute( 'data-source' ) !== r.source ) {
						return;
					}
					if ( r.status === 'fixed' || r.status === 'would_fix' ) {
						row.style.opacity = '0.35';
						var acceptBtn = row.querySelector( '.nvoos-dh-accept-fix' );
						if ( acceptBtn ) {
							acceptBtn.disabled = true;
						}
					} else {
						var cell = row.querySelector( 'td:last-child' );
						if ( cell && ! cell.querySelector( '.nvoos-dh-row-note' ) ) {
							var note = document.createElement( 'span' );
							note.className = 'nvoos-dh-row-note';
							note.style.cssText = 'display:block;color:#a00;font-style:italic;';
							note.textContent = r.reason || r.status || 'Skipped';
							cell.appendChild( note );
						}
					}
				} );
			} );

			var fixedCount = data.results ? ( data.results.fixed || 0 ) : 0;
			var skippedCount = data.results ? ( data.results.skipped || 0 ) : 0;
			var errorCount = ( data.results && data.results.errors ) ? data.results.errors.length : 0;
			setStatus(
				'Fixed ' + fixedCount + ' link(s).'
				+ ( skippedCount > 0 ? ' ' + skippedCount + ' skipped.' : '' )
				+ ( errorCount > 0 ? ' ' + errorCount + ' error(s).' : '' )
				+ ( data.dry_run ? ' (dry run)' : ' Rebuild to refresh.' ),
				( skippedCount > 0 || errorCount > 0 )
			);

			if ( btn ) {
				btn.disabled = false;
			}

			// Disable Accept All if no rows remain.
			if ( fixAll ) {
				var remaining = table.querySelectorAll( 'tbody tr .nvoos-dh-accept-fix:not([disabled])' );
				fixAll.disabled = remaining.length === 0;
			}
		} )
		.catch( function ( err ) {
			setStatus( 'Network error: ' + ( err.message || 'Request failed' ), true );
			if ( btn ) {
				btn.disabled = false;
			}
		} );
	}

	// Single-row accept.
	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.nvoos-dh-accept-fix' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var row = btn.closest( 'tr' );
		var fix = {
			source:     row.getAttribute( 'data-source' ),
			slug:       row.getAttribute( 'data-slug' ),
			old_target: row.getAttribute( 'data-old-target' ),
			new_target: row.getAttribute( 'data-new-target' )
		};
		applyFixes( [ fix ], btn );
	} );

	// Accept All.
	if ( fixAll ) {
		fixAll.addEventListener( 'click', function () {
			var rows  = table.querySelectorAll( 'tbody tr' );
			var fixes = [];
			rows.forEach( function ( row ) {
				var newTarget = row.getAttribute( 'data-new-target' );
				if ( ! newTarget || newTarget === '' ) {
					return;
				}
				var acceptBtn = row.querySelector( '.nvoos-dh-accept-fix' );
				if ( acceptBtn && acceptBtn.disabled ) {
					return;
				}
				fixes.push( {
					source:     row.getAttribute( 'data-source' ),
					slug:       row.getAttribute( 'data-slug' ),
					old_target: row.getAttribute( 'data-old-target' ),
					new_target: newTarget
				} );
			} );
			applyFixes( fixes, fixAll );
		} );
	}
}() );

// -------------------------------------------------------------------------
// Export / Import settings as JSON.
// -------------------------------------------------------------------------
( function () {
	var cfg      = window.NVOOS_DH_SETTINGS_PAGE || {};
	var i18n     = cfg.i18n || {};
	var settings = cfg.settings || {};
	var ajaxUrl  = cfg.ajaxUrl || ( typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '' );

	// --- Export ---
	var exportBtn = document.getElementById( 'nvoos-dh-export-settings' );
	if ( exportBtn ) {
		exportBtn.addEventListener( 'click', function () {
			// Strip sensitive token values from the export.
			if ( settings.remote_repos && Array.isArray( settings.remote_repos ) ) {
				settings.remote_repos = settings.remote_repos.map( function ( r ) {
					var copy = Object.assign( {}, r );
					delete copy.token;
					return copy;
				} );
			}
			var blob = new Blob(
				[ JSON.stringify( settings, null, 2 ) ],
				{ type: 'application/json' }
			);
			var a = document.createElement( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = 'nvoos-docs-hub-settings-' + new Date().toISOString().slice( 0, 10 ) + '.json';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( a.href );
		} );
	}

	// --- Import ---
	var importFile   = document.getElementById( 'nvoos-dh-import-file' );
	var importLabel  = document.querySelector( 'label[for="nvoos-dh-import-file"]' );
	var importStatus = document.getElementById( 'nvoos-dh-import-status' );

	if ( importLabel && importFile ) {
		importLabel.addEventListener( 'click', function () {
			importFile.click();
		} );
	}

	if ( importFile && importStatus ) {
		importFile.addEventListener( 'change', function () {
			var file = importFile.files && importFile.files[ 0 ];
			if ( ! file ) {
				return;
			}
			importStatus.textContent = i18n.importing;
			var reader = new FileReader();
			reader.onload = function ( e ) {
				try {
					var imported = JSON.parse( e.target.result );
					if ( ! imported || typeof imported !== 'object' || Array.isArray( imported ) ) {
						throw new Error( 'Invalid JSON structure' );
					}
					// POST via hidden form to trigger settings save.
					var formData = new FormData();
					formData.append( 'action', 'nvoos_docs_hub_import_settings' );
					formData.append( 'data', JSON.stringify( imported ) );
					formData.append( '_wpnonce', cfg.importNonce );
					fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
						.then( function ( r ) {
							return r.json();
						} )
						.then( function ( data ) {
							if ( data.success ) {
								importStatus.textContent = '\u2705 ' + ( data.data && data.data.message ? data.data.message : i18n.imported );
								setTimeout( function () {
									location.reload();
								}, 1500 );
							} else {
								importStatus.textContent = '\u274c ' + ( ( data.data && data.data.message ) ? data.data.message : i18n.importFailed );
							}
						} )
						.catch( function () {
							importStatus.textContent = '\u274c ' + i18n.importRequestFailed;
						} );
				} catch ( err ) {
					importStatus.textContent = '\u274c ' + ( err.message || i18n.invalidJson );
				}
			};
			reader.readAsText( file );
		} );
	}
}() );
