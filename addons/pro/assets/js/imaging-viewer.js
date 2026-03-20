/**
 * NV oOS Medical Imaging Viewer
 *
 * Bootstraps a Cornerstone3D-based DICOM stack viewer inside the
 * WordPress admin panel under Health & Wellness → Imaging Viewer.
 *
 * Architecture:
 *  - All data fetched via WP REST API (signed nonce auth).
 *  - Cornerstone3D loaded from esm.sh CDN via dynamic ES module import.
 *  - Versions are pinned in this file and in class-wp-mcp-ai-imaging-admin-page.php.
 *  - No PHI is written to the DOM; study labels use de-identified IDs.
 *
 * CDN strategy:
 *  Three packages are loaded from esm.sh with `?external=` so that the CDN
 *  emits bare specifiers for shared dependencies.  An importmap (injected by
 *  PHP in admin_head) maps those bare specifiers back to the same pinned URLs,
 *  ensuring all three packages share a single @cornerstonejs/core instance.
 *
 * @package WP_MCP_AI_Pro
 */

/* global wpMcpAiImaging */
( function () {
	'use strict';

	// =========================================================================
	// DOM references
	// =========================================================================
	var cfg = wpMcpAiImaging || {}; // eslint-disable-line no-undef -- declared in /* global */ above; set by wp_localize_script.
	var restBase = cfg.restBase || '';
	var nonce = cfg.nonce || '';
	var i18n = cfg.i18n || {};
	var canManage = cfg.canManage === 'yes';

	// Panels.
	var uploadBtn = document.getElementById( 'nv-imaging-upload-btn' );
	var uploadPanel = document.getElementById( 'nv-imaging-upload-panel' );
	var uploadForm = document.getElementById( 'nv-imaging-upload-form' );
	var uploadStatus = document.getElementById( 'nv-imaging-upload-status' );
	var uploadCancelBtn = document.getElementById( 'nv-imaging-upload-cancel' );

	var loadingEl = document.getElementById( 'nv-imaging-loading' );
	var studyListEl = document.getElementById( 'nv-imaging-study-list' );

	var viewerPanel = document.getElementById( 'nv-imaging-viewer-panel' );
	var backBtn = document.getElementById( 'nv-imaging-back-btn' );
	var studyLabel = document.getElementById( 'nv-imaging-study-label' );
	var seriesList = document.getElementById( 'nv-imaging-series-list' );
	var metadataList = document.getElementById( 'nv-imaging-metadata-list' );
	var viewport = document.getElementById( 'nv-imaging-viewport' );

	// Audit log loaded flag.
	var auditLoaded = false;

	// State.
	var csInitialized = false;
	var renderingEngine = null;
	var toolGroup = null;
	var activeModality = '';

	// Filter state.
	var filterModality = '';
	var filterDateFrom = '';
	var filterDateTo   = '';
	var filterSearch   = '';

	// =========================================================================
	// Utility helpers
	// =========================================================================

	/**
	 * Authenticated REST fetch wrapper.
	 *
	 * @param {string} url     Full URL.
	 * @param {object} options Fetch options.
	 * @returns {Promise<Response>}
	 */
	function apiFetch( url, options ) {
		options = options || {};
		options.headers = Object.assign(
			{
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json',
			},
			options.headers || {}
		);
		return fetch( url, options );
	}

	/**
	 * Escape HTML to prevent XSS when setting innerHTML.
	 *
	 * @param {string} str Raw string.
	 * @returns {string} HTML-escaped string.
	 */
	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( str ) ) );
		return d.innerHTML;
	}

	/**
	 * Show a status message inside uploadStatus.
	 *
	 * @param {string}  msg     Message text.
	 * @param {boolean} isError If true, use error styling.
	 */
	function showUploadStatus( msg, isError ) {
		if ( ! uploadStatus ) {
			return;
		}
		uploadStatus.className = 'nv-imaging-upload-status' + ( isError ? ' nv-imaging-upload-error' : ' nv-imaging-upload-success' );
		uploadStatus.textContent = msg;
	}

	// =========================================================================
	// Stats bar
	// =========================================================================

	/**
	 * Format a byte count into a human-readable string.
	 *
	 * @param {number} bytes Raw byte count.
	 * @returns {string}
	 */
	function formatBytes( bytes ) {
		if ( bytes < 1024 ) { return bytes + ' B'; }
		if ( bytes < 1048576 ) { return ( bytes / 1024 ).toFixed( 1 ) + ' KB'; }
		if ( bytes < 1073741824 ) { return ( bytes / 1048576 ).toFixed( 1 ) + ' MB'; }
		return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
	}

	/**
	 * Render the stats bar from an API response.
	 *
	 * @param {object} stats Stats object from GET /imaging/stats.
	 */
	function renderStatsBar( stats ) {
		var bar = document.getElementById( 'nv-imaging-stats-bar' );
		if ( ! bar ) {
			return;
		}
		var byMod = ( stats.by_modality || [] ).map( function ( m ) {
			return escHtml( m.modality || '?' ) + ': <strong>' + escHtml( m.count ) + '</strong>';
		} ).join( ' &nbsp;·&nbsp; ' );

		var storageStr = stats.storage_bytes > 0 ? formatBytes( stats.storage_bytes ) : '—';

		bar.innerHTML =
			'<span class="nv-imaging-stat"><strong>' + escHtml( stats.total_studies ) + '</strong> Studies</span>' +
			( byMod ? '<span class="nv-imaging-stat-sep">|</span><span class="nv-imaging-stat">' + byMod + '</span>' : '' ) +
			'<span class="nv-imaging-stat-sep">|</span>' +
			'<span class="nv-imaging-stat">Storage: <strong>' + storageStr + '</strong></span>';
	}

	/**
	 * Fetch stats from the REST API and populate the stats bar.
	 */
	function loadStats() {
		if ( ! cfg.statsUrl ) {
			return;
		}
		apiFetch( cfg.statsUrl )
			.then( function ( res ) { return res.json(); } )
			.then( function ( stats ) { renderStatsBar( stats ); } )
			.catch( function () {} );
	}

	// =========================================================================
	// Tab navigation
	// =========================================================================

	/**
	 * Wire up the tab buttons and initialise the correct active panel.
	 *
	 * On click we prevent the default navigation, update the browser URL via
	 * history.replaceState (so the tab is bookmarkable / shareable without
	 * triggering a full page reload), and show only the matching panel.
	 *
	 * The initial active tab is supplied by PHP through wpMcpAiImaging.activeTab
	 * (already validated against the whitelist server-side) so that the JS view
	 * stays in sync with what PHP rendered on page load.
	 */
	function initTabs() {
		var tabLinks = document.querySelectorAll( '.nv-imaging-tab-nav .nav-tab' );
		var allTabPanels = [
			{ id: 'nv-imaging-tab-studies', key: 'studies' },
			{ id: 'nv-imaging-tab-tools',   key: 'tools' },
			{ id: 'nv-imaging-tab-audit',   key: 'audit' },
			{ id: 'nv-imaging-tab-docs',    key: 'docs' },
			{ id: 'nv-imaging-tab-debug',   key: 'debug' },
		];

		/**
		 * Activate a tab: update active class on links, show/hide panels,
		 * update the browser URL, and trigger lazy-loads where needed.
		 *
		 * @param {string} tab Tab key to activate (e.g. 'studies').
		 */
		function activateTab( tab ) {
			// Sync active class on all tab links.
			tabLinks.forEach( function ( l ) {
				if ( l.dataset.tab === tab ) {
					l.classList.add( 'nav-tab-active' );
				} else {
					l.classList.remove( 'nav-tab-active' );
				}
			} );

			// Show only the matching panel; hide all others.
			allTabPanels.forEach( function ( panel ) {
				var el = document.getElementById( panel.id );
				if ( el ) {
					el.style.display = ( panel.key === tab ) ? '' : 'none';
				}
			} );

			// Lazy-load the audit log the first time it becomes visible.
			if ( tab === 'audit' ) {
				loadAuditLog();
			}
		}

		// Wire up click handlers — intercept navigation, update URL instead.
		tabLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var tab = link.dataset.tab;

				// Update the browser URL without reloading the page so that
				// the tab link is bookmarkable and the browser back/forward
				// buttons work as expected.
				if ( typeof URL === 'function' ) {
					try {
						var url = new URL( window.location.href );
						url.searchParams.set( 'tab', tab );
						history.replaceState( null, '', url.toString() );
					} catch ( urlErr ) {
						// URL update failed — tab switching still works.
						// eslint-disable-next-line no-console
						console.warn( 'nv-imaging: could not update URL for tab "' + tab + '"', urlErr );
					}
				}

				activateTab( tab );
			} );
		} );

		// Activate the initial tab using the server-validated value supplied by
		// PHP.  This keeps JS in sync with what PHP already rendered (correct
		// panel visible, correct nav-tab-active class) without the browser
		// needing to re-parse the URL.
		var initialTab = ( cfg.activeTab && cfg.activeTab.length ) ? cfg.activeTab : 'studies';
		activateTab( initialTab );
	}

	// =========================================================================
	// Filter bar
	// =========================================================================

	/**
	 * Build the studies API URL with current filter state.
	 *
	 * @returns {string}
	 */
	function buildStudiesUrl() {
		var url = restBase + '/studies?per_page=100';
		if ( filterModality ) { url += '&modality='  + encodeURIComponent( filterModality ); }
		if ( filterDateFrom ) { url += '&date_from=' + encodeURIComponent( filterDateFrom ); }
		if ( filterDateTo )   { url += '&date_to='   + encodeURIComponent( filterDateTo ); }
		if ( filterSearch )   { url += '&search='    + encodeURIComponent( filterSearch ); }
		return url;
	}

	/**
	 * Wire up the filter bar controls.
	 */
	function initFilters() {
		var applyBtn = document.getElementById( 'nv-imaging-filter-apply' );
		var clearBtn = document.getElementById( 'nv-imaging-filter-clear' );

		if ( applyBtn ) {
			applyBtn.addEventListener( 'click', function () {
				filterModality = ( document.getElementById( 'nv-imaging-filter-modality' ) || {} ).value || '';
				filterDateFrom = ( document.getElementById( 'nv-imaging-date-from' ) || {} ).value || '';
				filterDateTo   = ( document.getElementById( 'nv-imaging-date-to' ) || {} ).value || '';
				filterSearch   = ( document.getElementById( 'nv-imaging-search' ) || {} ).value || '';
				loadStudyList();
			} );
		}

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				filterModality = filterDateFrom = filterDateTo = filterSearch = '';
				[ 'nv-imaging-filter-modality', 'nv-imaging-date-from', 'nv-imaging-date-to', 'nv-imaging-search' ]
					.forEach( function ( id ) {
						var el = document.getElementById( id );
						if ( el ) { el.value = ''; }
					} );
				loadStudyList();
			} );
		}

		// Allow pressing Enter in the search box to trigger the filter.
		var searchEl = document.getElementById( 'nv-imaging-search' );
		if ( searchEl ) {
			searchEl.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' && applyBtn ) { applyBtn.click(); }
			} );
		}
	}

	// =========================================================================
	// Study browser
	// =========================================================================

	/**
	 * Load and render the study list.
	 */
	function loadStudyList() {
		if ( loadingEl ) {
			loadingEl.style.display = '';
		}
		if ( studyListEl ) {
			studyListEl.style.display = 'none';
		}

		apiFetch( buildStudiesUrl() )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'Failed to load studies (HTTP ' + res.status + ')' );
				}
				return res.json();
			} )
			.then( function ( data ) {
				renderStudyList( data.studies || [] );
			} )
			.catch( function ( err ) {
				if ( loadingEl ) {
					loadingEl.textContent = err.message;
				}
			} );
	}

	// =========================================================================
	// Audit log
	// =========================================================================

	/**
	 * Fetch and render the audit log (lazy-loaded once).
	 */
	function loadAuditLog() {
		if ( auditLoaded ) {
			return;
		}
		var listEl = document.getElementById( 'nv-imaging-audit-list' );
		var loadEl = document.getElementById( 'nv-imaging-audit-loading' );
		if ( loadEl ) { loadEl.style.display = ''; }

		apiFetch( restBase + '/audit?limit=100' )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				auditLoaded = true;
				if ( loadEl ) { loadEl.style.display = 'none'; }
				renderAuditLog( data.entries || [], listEl );
			} )
			.catch( function ( err ) {
				if ( loadEl ) { loadEl.style.display = 'none'; }
				if ( listEl ) {
					listEl.innerHTML = '<p class="nv-imaging-error">' + escHtml( err.message ) + '</p>';
				}
			} );
	}

	/**
	 * Render the audit log entries into a table.
	 *
	 * @param {Array}       entries Audit log entries.
	 * @param {HTMLElement} listEl  Container element.
	 */
	function renderAuditLog( entries, listEl ) {
		if ( ! listEl ) {
			return;
		}
		if ( ! entries.length ) {
			listEl.innerHTML = '<p class="nv-imaging-empty">No audit events recorded.</p>';
			return;
		}
		var html = '<table class="wp-list-table widefat fixed striped nv-imaging-table">';
		html += '<thead><tr><th>Time</th><th>Action</th><th>Study</th><th>User</th></tr></thead><tbody>';
		entries.forEach( function ( e ) {
			html += '<tr>';
			html += '<td>' + escHtml( e.timestamp || '' ) + '</td>';
			html += '<td>' + escHtml( e.action || '' ) + '</td>';
			html += '<td class="nv-imaging-uid">' + escHtml( e.study_id || '' ) + '</td>';
			html += '<td>' + escHtml( e.user_id || '' ) + '</td>';
			html += '</tr>';
		} );
		html += '</tbody></table>';
		listEl.innerHTML = html;
	}

	// =========================================================================
	// AI Tools tab
	// =========================================================================

	/** Currently-viewed study UID (set when viewer opens, cleared on back). */
	var activeStudyUid = '';

	/**
	 * Populate (or refresh) the study select dropdown on the AI Tools tab.
	 */
	function refreshStudyDropdown() {
		var sel = document.getElementById( 'nv-imaging-interpret-study' );
		if ( ! sel ) {
			return;
		}

		// Remember the current value so we can restore it after refresh.
		var currentVal = sel.value;

		// Clear existing options except the placeholder.
		while ( sel.options.length > 1 ) {
			sel.remove( 1 );
		}

		apiFetch( restBase + '/studies?per_page=100' )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				var studies = ( data && data.studies ) ? data.studies : [];
				studies.forEach( function ( s ) {
					var opt = document.createElement( 'option' );
					opt.value = s.study_uid;
					// Label: "CT  20240101 — 1.2.840…(truncated to fit dropdown)"
					var parts = [];
					if ( s.modality ) { parts.push( s.modality ); }
					if ( s.study_date ) { parts.push( s.study_date ); }
					var prefix = parts.length ? parts.join( '\u2002' ) + ' \u2014 ' : '';
					var uid = s.study_uid || '';
					// 24 chars is long enough to uniquely identify a UID root while still fitting a dropdown.
					opt.textContent = prefix + ( uid.length > 24 ? uid.substring( 0, 24 ) + '\u2026' : uid );
					sel.appendChild( opt );
				} );
				// Restore previous selection if it still exists.
				if ( currentVal ) {
					sel.value = currentVal;
				}
			} )
			.catch( function () {} ); // Silent: user can still type UID manually.
	}

	/**
	 * Wire up the AI Interpretation form on the Tools tab.
	 */
	function initToolsTab() {
		var runBtn   = document.getElementById( 'nv-imaging-interpret-run' );
		var studySel = document.getElementById( 'nv-imaging-interpret-study' );
		var uidInput = document.getElementById( 'nv-imaging-interpret-uid' );
		var focusSel = document.getElementById( 'nv-imaging-interpret-focus' );
		var resultEl = document.getElementById( 'nv-imaging-interpret-result' );

		if ( ! runBtn ) {
			return;
		}

		// Populate the study dropdown on first load.
		refreshStudyDropdown();

		// When a study is chosen from the dropdown, fill the UID text input.
		if ( studySel ) {
			studySel.addEventListener( 'change', function () {
				if ( studySel.value && uidInput ) {
					uidInput.value = studySel.value;
				}
			} );
		}

		runBtn.addEventListener( 'click', function () {
			var studyUid = uidInput ? uidInput.value.trim() : '';
			var focus    = focusSel ? focusSel.value : 'full';

			if ( ! studyUid ) {
				if ( resultEl ) {
					resultEl.style.display = '';
					resultEl.className = 'nv-imaging-interpret-result nv-imaging-interpret-error';
					resultEl.textContent = i18n.noStudySelected || 'Enter a Study UID to analyse.';
				}
				return;
			}

			runBtn.disabled = true;
			runBtn.textContent = i18n.interpreting || 'Analysing…';
			if ( resultEl ) {
				resultEl.style.display = '';
				resultEl.className = 'nv-imaging-interpret-result nv-imaging-interpret-loading';
				resultEl.textContent = i18n.interpreting || 'Analysing…';
			}

			apiFetch(
				cfg.interpretUrl || ( restBase + '/interpret' ),
				{
					method: 'POST',
					body: JSON.stringify( { study_uid: studyUid, focus: focus } ),
				}
			)
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					runBtn.disabled = false;
					runBtn.textContent = i18n.interpretRun || 'Run AI Analysis';

					if ( result.ok && result.data && result.data.interpretation ) {
						if ( resultEl ) {
							resultEl.className = 'nv-imaging-interpret-result nv-imaging-interpret-output';
							// Render newlines as paragraphs for readability.
							var lines = String( result.data.interpretation ).split( '\n' );
							resultEl.innerHTML = lines.map( function ( l ) {
								return l.trim() ? '<p>' + escHtml( l ) + '</p>' : '';
							} ).join( '' );
						}
					} else {
						var errMsg = ( result.data && result.data.message )
							? result.data.message
							: ( i18n.interpretError || 'AI interpretation failed.' );
						if ( resultEl ) {
							resultEl.className = 'nv-imaging-interpret-result nv-imaging-interpret-error';
							resultEl.textContent = errMsg;
						}
					}
				} )
				.catch( function () {
					runBtn.disabled = false;
					runBtn.textContent = i18n.interpretRun || 'Run AI Analysis';
					if ( resultEl ) {
						resultEl.className = 'nv-imaging-interpret-result nv-imaging-interpret-error';
						resultEl.textContent = i18n.interpretError || 'AI interpretation failed.';
					}
				} );
		} );
	}

	/**
	 * Pre-fill the Tools tab UID input with the currently-viewed study.
	 *
	 * Called whenever the viewer opens a study.
	 *
	 * @param {string} uid DICOM StudyInstanceUID.
	 */
	function setActiveStudyUid( uid ) {
		activeStudyUid = uid || '';
		var uidInput = document.getElementById( 'nv-imaging-interpret-uid' );
		if ( uidInput && activeStudyUid ) {
			uidInput.value = activeStudyUid;
		}
		// Mirror selection in the study dropdown if the study is listed there.
		var studySel = document.getElementById( 'nv-imaging-interpret-study' );
		if ( studySel && activeStudyUid ) {
			studySel.value = activeStudyUid;
		}
	}

	/**
	 * Render the study list table.
	 *
	 * @param {Array} studies Study objects.
	 */
	function renderStudyList( studies ) {
		if ( loadingEl ) {
			loadingEl.style.display = 'none';
		}
		if ( ! studyListEl ) {
			return;
		}

		if ( ! studies.length ) {
			studyListEl.innerHTML = '<p class="nv-imaging-empty">' + escHtml( i18n.noStudies || 'No studies found.' ) + '</p>';
			studyListEl.style.display = '';
			return;
		}

		var html = '<table class="wp-list-table widefat fixed striped nv-imaging-table">';
		html += '<thead><tr>';
		html += '<th>' + escHtml( 'Study UID' ) + '</th>';
		html += '<th>' + escHtml( 'Modality' ) + '</th>';
		html += '<th>' + escHtml( 'Study Date' ) + '</th>';
		html += '<th>' + escHtml( 'Series' ) + '</th>';
		html += '<th>' + escHtml( 'Instances' ) + '</th>';
		html += '<th>' + escHtml( 'Status' ) + '</th>';
		html += '<th>' + escHtml( 'Actions' ) + '</th>';
		html += '</tr></thead><tbody>';

		studies.forEach( function ( s ) {
			html += '<tr data-study-uid="' + escHtml( s.study_uid ) + '">';
			html += '<td class="nv-imaging-uid">' + escHtml( s.study_uid ) + '</td>';
			html += '<td>' + escHtml( s.modality ) + '</td>';
			html += '<td>' + escHtml( s.study_date ) + '</td>';
			html += '<td>' + escHtml( s.series_count ) + '</td>';
			html += '<td>' + escHtml( s.instance_count ) + '</td>';
			html += '<td>' + escHtml( s.status ) + '</td>';
			html += '<td class="nv-imaging-actions-cell">';
			html += '<button type="button" class="button nv-imaging-view-btn" data-uid="' + escHtml( s.study_uid ) + '">View</button>';
			if ( canManage ) {
				html += ' <button type="button" class="button nv-imaging-delete-btn" data-uid="' + escHtml( s.study_uid ) + '">Delete</button>';
			}
			html += '</td>';
			html += '</tr>';
		} );

		html += '</tbody></table>';
		studyListEl.innerHTML = html;
		studyListEl.style.display = '';

		// Bind view buttons.
		studyListEl.querySelectorAll( '.nv-imaging-view-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openStudy( btn.dataset.uid );
			} );
		} );

		// Bind delete buttons.
		studyListEl.querySelectorAll( '.nv-imaging-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				deleteStudy( btn.dataset.uid, btn );
			} );
		} );
	}

	/**
	 * Delete a study with inline row-level confirmation (accessible alternative
	 * to window.confirm / window.alert).
	 *
	 * When the user clicks the Delete button a confirmation message is injected
	 * directly into the table row.  Confirming triggers the REST delete and
	 * refreshes the study list; cancelling removes the inline prompt.
	 *
	 * @param {string}      studyUid  DICOM StudyInstanceUID.
	 * @param {HTMLElement} sourceBtn The delete button that was clicked.
	 */
	function deleteStudy( studyUid, sourceBtn ) {
		// If a confirm row already exists for this study, remove it (toggle off).
		var existingConfirm = document.getElementById( 'nv-del-confirm-' + CSS.escape( studyUid ) );
		if ( existingConfirm ) {
			existingConfirm.remove();
			return;
		}

		// Build an accessible inline confirmation row beneath the study row.
		var sourceRow = sourceBtn ? sourceBtn.closest( 'tr' ) : null;
		var confirmRow = document.createElement( 'tr' );
		confirmRow.id = 'nv-del-confirm-' + CSS.escape( studyUid );
		confirmRow.className = 'nv-imaging-confirm-row';
		var colCount = sourceRow ? sourceRow.children.length : 7;
		confirmRow.innerHTML =
			'<td colspan="' + colCount + '" class="nv-imaging-confirm-cell" role="alert" aria-live="assertive">' +
			'<span class="nv-imaging-confirm-msg">' +
			escHtml( i18n.confirmDelete || 'Delete this study? All DICOM files will be permanently removed.' ) +
			'</span> ' +
			'<button type="button" class="button button-link-delete nv-imaging-confirm-yes">Yes, delete</button> ' +
			'<button type="button" class="button nv-imaging-confirm-no">Cancel</button>' +
			'<span class="nv-imaging-confirm-status" aria-live="polite"></span>' +
			'</td>';

		if ( sourceRow && sourceRow.parentNode ) {
			sourceRow.parentNode.insertBefore( confirmRow, sourceRow.nextSibling );
		} else {
			// Fallback: append to study list container.
			if ( studyListEl ) { studyListEl.appendChild( confirmRow ); }
		}

		// Cancel button.
		confirmRow.querySelector( '.nv-imaging-confirm-no' ).addEventListener( 'click', function () {
			confirmRow.remove();
		} );

		// Confirm button.
		confirmRow.querySelector( '.nv-imaging-confirm-yes' ).addEventListener( 'click', function () {
			var statusEl = confirmRow.querySelector( '.nv-imaging-confirm-status' );
			var yesBtn   = confirmRow.querySelector( '.nv-imaging-confirm-yes' );
			var noBtn    = confirmRow.querySelector( '.nv-imaging-confirm-no' );
			yesBtn.disabled = true;
			noBtn.disabled  = true;
			if ( statusEl ) { statusEl.textContent = 'Deleting…'; }

			apiFetch(
				restBase + '/studies/' + encodeURIComponent( studyUid ),
				{
					method: 'DELETE',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				}
			)
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'Delete failed (HTTP ' + res.status + ')' );
					}
					confirmRow.remove();
					loadStudyList();
					loadStats();
				} )
				.catch( function ( err ) {
					yesBtn.disabled = false;
					noBtn.disabled  = false;
					if ( statusEl ) {
						statusEl.className = 'nv-imaging-confirm-status nv-imaging-confirm-status--error';
						statusEl.textContent = err.message;
					}
				} );
		} );
	}

	// =========================================================================
	// Study viewer
	// =========================================================================

	/**
	 * Open a study in the viewer.
	 *
	 * @param {string} studyUid DICOM StudyInstanceUID.
	 */
	function openStudy( studyUid ) {
		var mainPanel = document.getElementById( 'nv-imaging-main-panel' );
		if ( mainPanel ) {
			mainPanel.style.display = 'none';
		}
		if ( viewerPanel ) {
			viewerPanel.style.display = '';
		}
		if ( studyLabel ) {
			studyLabel.textContent = studyUid;
		}

		// Pre-fill the AI Tools tab UID input.
		setActiveStudyUid( studyUid );

		// Fetch manifest then boot Cornerstone.
		apiFetch( restBase + '/studies/' + encodeURIComponent( studyUid ) + '/manifest' )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( i18n.viewerError || 'Unable to load imaging study.' );
				}
				return res.json();
			} )
			.then( function ( manifest ) {
				renderSeriesSidebar( manifest );
				if ( manifest.series && manifest.series.length ) {
					activeModality = manifest.modality || '';
					loadSeriesInViewer( manifest.series[ 0 ] );
				}
			} )
			.catch( function ( err ) {
				if ( viewport ) {
					var msg = err && err.message ? err.message : String( err );
					viewport.innerHTML =
						'<p class="nv-imaging-error" style="background:#fff;border-radius:3px;margin:12px;">' +
						escHtml( msg ) + '</p>';
				}
			} );
	}

	/**
	 * Render the series list in the sidebar.
	 *
	 * @param {object} manifest Study manifest.
	 */
	function renderSeriesSidebar( manifest ) {
		if ( ! seriesList ) {
			return;
		}
		seriesList.innerHTML = '';
		( manifest.series || [] ).forEach( function ( s, idx ) {
			var li = document.createElement( 'li' );
			li.className = 'nv-imaging-series-item' + ( 0 === idx ? ' nv-imaging-series-active' : '' );
			var count = s.instances ? s.instances.length : 0;
			li.textContent = ( s.modality || s.seriesDescription || 'Series ' + ( idx + 1 ) ) + ' — ' + count + ' image' + ( 1 === count ? '' : 's' );
			li.addEventListener( 'click', function () {
				document.querySelectorAll( '.nv-imaging-series-item' ).forEach( function ( el ) {
					el.classList.remove( 'nv-imaging-series-active' );
				} );
				li.classList.add( 'nv-imaging-series-active' );
				activeModality = s.modality || manifest.modality || '';
				loadSeriesInViewer( s );
			} );
			seriesList.appendChild( li );
		} );

		// Populate metadata sidebar.
		if ( metadataList ) {
			metadataList.innerHTML =
				'<dt>Study UID</dt><dd class="nv-imaging-uid">' + escHtml( manifest.studyId ) + '</dd>' +
				'<dt>Modality</dt><dd>' + escHtml( manifest.modality ) + '</dd>' +
				'<dt>Study Date</dt><dd>' + escHtml( manifest.studyDate ) + '</dd>' +
				'<dt>Series</dt><dd>' + escHtml( ( manifest.series || [] ).length ) + '</dd>';
		}
	}

	/**
	 * Load a series into the Cornerstone3D viewport.
	 *
	 * Cornerstone3D is loaded asynchronously from CDN on first use.
	 *
	 * @param {object} series Series object from manifest.
	 */
	function loadSeriesInViewer( series ) {
		if ( ! viewport ) {
			return;
		}

		// Sort instances by InstanceNumber (DICOM tag 0020,0013) before building
		// the imageId stack.  Correct slice ordering is required by the IHE
		// Radiology Technical Framework and prevents images from displaying in a
		// random sequence when multiple instances are in the series.
		var instances = ( series.instances || [] ).slice();
		instances.sort( function ( a, b ) {
			var na = typeof a.instanceNumber === 'number' ? a.instanceNumber : 0;
			var nb = typeof b.instanceNumber === 'number' ? b.instanceNumber : 0;
			return na - nb;
		} );

		var imageIds = instances.map( function ( inst ) {
			return inst.imageId;
		} );

		if ( ! imageIds.length ) {
			viewport.innerHTML = '<p class="nv-imaging-error">' + escHtml( i18n.noInstances || 'No instances.' ) + '</p>';
			return;
		}

		viewport.innerHTML = '<div class="nv-imaging-cs3d-el" id="nv-cs3d-el" style="width:100%;height:70vh;background:#000;"></div>';

		// Show instance count overlay.
		if ( imageIds.length > 1 ) {
			var overlay = document.createElement( 'div' );
			overlay.id = 'nv-imaging-instance-overlay';
			overlay.className = 'nv-imaging-instance-overlay';
			overlay.textContent = '1 / ' + imageIds.length;
			viewport.appendChild( overlay );
		}

		// Prefer the locally-bundled Cornerstone3D packages (window.nvCs) set
		// by imaging-viewer-bundle.js.  This avoids CDN dependency, importmaps,
		// and type="module" requirements entirely — exactly the same approach used
		// by the TMA Template Builder for its React bundle.
		//
		// The CDN dynamic-import path below is kept as a fallback for deployments
		// that do not have the compiled bundle (e.g. development without a build).
		if ( window.nvCs ) {
			bootCornerstone(
				window.nvCs.csCore,
				window.nvCs.csTools,
				window.nvCs.csDicomImageLoader,
				imageIds
			).catch( function ( err ) {
				var msg = err && err.message ? err.message : String( err );
				viewport.innerHTML =
					'<p class="nv-imaging-error" style="background:#fff;border-radius:3px;margin:12px;">' +
					escHtml( 'Viewer error: ' + msg ) + '</p>';
			} );
			return;
		}

		// Fallback: load Cornerstone3D from CDN using dynamic ES module imports.
		// Requires the importmap injected by the PHP admin page to be in place,
		// and the script tag must be type="module".
		//
		// NOTE: Versions are pinned here and in class-wp-mcp-ai-imaging-admin-page.php.
		// Both must be updated together when upgrading Cornerstone3D.
		Promise.all( [
			import( 'https://esm.sh/@cornerstonejs/core@1.86.1' ),
			import( 'https://esm.sh/@cornerstonejs/tools@1.86.1?external=@cornerstonejs/core' ),
			import( 'https://esm.sh/@cornerstonejs/dicom-image-loader@1.86.0?external=@cornerstonejs/core,dicom-parser' ),
		] )
			.then( function ( modules ) {
				return bootCornerstone( modules[ 0 ], modules[ 1 ], modules[ 2 ], imageIds );
			} )
			.catch( function ( err ) {
				// Show the error with a light background so it is readable even
				// when the viewport-wrap has a dark background.
				var msg = err && err.message ? err.message : String( err );
				viewport.innerHTML =
					'<p class="nv-imaging-error" style="background:#fff;border-radius:3px;margin:12px;">' +
					escHtml( 'Viewer error: ' + msg ) + '</p>';
			} );
	}

	/**
	 * Return W/L preset values keyed by modality (industry-standard clinical presets).
	 *
	 * @returns {object}
	 */
	function getWLPresets() {
		return {
			CT: [
				{ label: 'Soft Tissue', ww: 350, wl: 40 },
				{ label: 'Lung',        ww: 1500, wl: -600 },
				{ label: 'Brain',       ww: 80,   wl: 40 },
				{ label: 'Bone',        ww: 2000, wl: 400 },
				{ label: 'Abdomen',     ww: 400,  wl: 50 },
				{ label: 'Liver',       ww: 150,  wl: 80 },
				{ label: 'Mediastinum', ww: 350,  wl: 50 },
			],
			MR: [
				{ label: 'Brain',       ww: 1000, wl: 500 },
				{ label: 'Spine',       ww: 1200, wl: 600 },
				{ label: 'Soft Tissue', ww: 500,  wl: 250 },
			],
			PT: [
				{ label: 'SUV Max',     ww: 5,    wl: 2.5 },
			],
		};
	}

	/**
	 * Render the W/L toolbar inside the viewer panel.
	 *
	 * @param {object} vp Cornerstone3D Stack Viewport instance.
	 */
	function initWLToolbar( vp ) {
		var toolbar = document.getElementById( 'nv-imaging-wl-toolbar' );
		if ( ! toolbar ) {
			return;
		}

		toolbar.innerHTML = '';

		// Reset W/L button.
		var resetBtn = document.createElement( 'button' );
		resetBtn.type = 'button';
		resetBtn.className = 'button nv-imaging-wl-btn';
		resetBtn.textContent = 'Reset W/L';
		resetBtn.addEventListener( 'click', function () {
			try {
				vp.resetProperties();
				vp.render();
			} catch ( _e ) {}
		} );
		toolbar.appendChild( resetBtn );

		// Invert button.
		var invertBtn = document.createElement( 'button' );
		invertBtn.type = 'button';
		invertBtn.className = 'button nv-imaging-wl-btn';
		invertBtn.textContent = 'Invert';
		invertBtn.addEventListener( 'click', function () {
			try {
				var props = vp.getProperties();
				var inverted = ! ( props && props.invert );
				vp.setProperties( { invert: inverted } );
				vp.render();
			} catch ( _e ) {}
		} );
		toolbar.appendChild( invertBtn );

		// Modality-specific presets.
		var modality = ( activeModality || '' ).toUpperCase();
		var presets = getWLPresets()[ modality ] || [];
		if ( presets.length ) {
			var sep = document.createElement( 'span' );
			sep.className = 'nv-imaging-wl-sep';
			sep.textContent = '|';
			toolbar.appendChild( sep );

			var label = document.createElement( 'span' );
			label.className = 'nv-imaging-wl-label';
			label.textContent = modality + ' presets:';
			toolbar.appendChild( label );

			presets.forEach( function ( preset ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'button nv-imaging-wl-btn nv-imaging-wl-preset';
				btn.textContent = preset.label;
				btn.title = 'WW ' + preset.ww + ' / WL ' + preset.wl;
				btn.addEventListener( 'click', function () {
					try {
						vp.setProperties( {
							voiRange: {
								lower: preset.wl - preset.ww / 2,
								upper: preset.wl + preset.ww / 2,
							},
						} );
						vp.render();
					} catch ( _e ) {}
				} );
				toolbar.appendChild( btn );
			} );
		}
	}

	/**
	 * Initialise Cornerstone3D and render the image stack.
	 *
	 * Key fixes applied here (industry best practices):
	 *  1. `csDicomImageLoader.external.cornerstone = csCore` — connects the loader
	 *     to Cornerstone so it can register the wadouri image-loader and decode
	 *     pixel data.  Without this the canvas renders as solid black.
	 *  2. `configure({ beforeSend })` — injects the WP nonce header so the REST
	 *     endpoint can authenticate the per-instance file fetch.
	 *  3. After `setStack`, listen for IMAGE_RENDERED and auto-compute VOI range
	 *     from pixel data when DICOM metadata does not carry WindowCenter/Width.
	 *
	 * @param {object} csCore             @cornerstonejs/core module.
	 * @param {object} csTools            @cornerstonejs/tools module.
	 * @param {object} csDicomImageLoader @cornerstonejs/dicom-image-loader module.
	 * @param {string[]} imageIds         Array of wadouri: image IDs.
	 * @returns {Promise<void>}
	 */
	async function bootCornerstone( csCore, csTools, csDicomImageLoader, imageIds ) {
		var element = document.getElementById( 'nv-cs3d-el' );
		if ( ! element ) {
			return;
		}

		// One-time global init.
		if ( ! csInitialized ) {
			// CRITICAL (fix for black images): Connect the DICOM image loader to
			// Cornerstone core BEFORE calling init().  Without this link the wadouri
			// image-loader is never registered and every canvas stays black.
			// (No-op for newer loader versions that import cornerstone directly, but
			// harmless and required by some 1.x CDN builds.)
			if ( csDicomImageLoader.external ) {
				csDicomImageLoader.external.cornerstone = csCore;
			}

			// Configure authentication headers sent with every XHR-based DICOM fetch.
			// `beforeSend` is the standard hook in @cornerstonejs/dicom-image-loader v1.
			// NOTE: beforeSend only fires in the main thread.  We therefore set
			// maxWebWorkers: 0 below so that all loading stays on the main thread
			// and the X-WP-Nonce header is always injected correctly.
			if ( csDicomImageLoader.configure ) {
				csDicomImageLoader.configure( {
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', nonce );
					},
				} );
			}

			await csCore.init();
			await csTools.init();

			// Call the loader's own init AFTER external.cornerstone is set.
			// maxWebWorkers: 0 keeps decoding on the main thread so that the
			// beforeSend authentication hook above is always applied (web workers
			// cannot inherit the main-thread beforeSend callback).
			if ( csDicomImageLoader.init ) {
				csDicomImageLoader.init( { maxWebWorkers: 0 } );
			}

			csInitialized = true;
		}

		var viewportId = 'nvViewport';
		var renderingEngineId = 'nvRenderingEngine';

		// Destroy previous rendering engine if it exists.
		if ( renderingEngine ) {
			try {
				renderingEngine.destroy();
			} catch ( _e ) {
				// Ignore.
			}
		}

		renderingEngine = new csCore.RenderingEngine( renderingEngineId );
		renderingEngine.enableElement( {
			viewportId: viewportId,
			element: element,
			type: csCore.Enums.ViewportType.STACK,
		} );

		var vp = renderingEngine.getViewport( viewportId );

		await vp.setStack( imageIds, 0 );

		// Auto-compute window/level on first render.
		// DICOM files without WindowCenter/Width metadata tags display as black
		// without this step.  We listen for the IMAGE_RENDERED event once and
		// compute the VOI range from the actual pixel value range if none was
		// set by the metadata.
		if ( csCore.Enums && csCore.Enums.Events && csCore.Enums.Events.IMAGE_RENDERED ) {
			element.addEventListener(
				csCore.Enums.Events.IMAGE_RENDERED,
				function onFirstRender( evt ) {
					element.removeEventListener( csCore.Enums.Events.IMAGE_RENDERED, onFirstRender );
					try {
						var imgEvt = evt.detail && evt.detail.image;
						if ( imgEvt &&
							typeof imgEvt.minPixelValue !== 'undefined' &&
							typeof imgEvt.maxPixelValue !== 'undefined' ) {
							var lo = imgEvt.minPixelValue;
							var hi = imgEvt.maxPixelValue;
							if ( hi > lo ) {
								vp.setProperties( { voiRange: { lower: lo, upper: hi } } );
								vp.render();
							}
						} else {
							// No pixel range in event detail (varies by Cornerstone version).
							// Fall back to resetProperties() which auto-computes VOI from
							// the actual pixel data when no DICOM Window/Level tags exist.
							vp.resetProperties();
							vp.render();
						}
					} catch ( _e ) {}
				},
				{ once: true }
			);
		}

		// Surface DICOM image-load failures so the user sees a message instead of
		// a silent black canvas.  The event name varies slightly across versions.
		var loadFailEvent = csCore.Enums && csCore.Enums.Events && (
			csCore.Enums.Events.IMAGE_LOAD_ERROR ||
			csCore.Enums.Events.IMAGELOADFAILED
		);
		if ( loadFailEvent ) {
			element.addEventListener( loadFailEvent, function ( evt ) {
				var msg = ( evt.detail && evt.detail.error && evt.detail.error.message )
					? evt.detail.error.message
					: 'DICOM load failed';
				if ( viewport ) {
					viewport.innerHTML = '<p class="nv-imaging-error">' + escHtml( i18n.viewerError + ': ' + msg ) + '</p>';
				}
			} );
		}

		vp.render();

		// Update instance overlay when the stack image index changes.
		if ( csCore.Enums && csCore.Enums.Events && csCore.Enums.Events.STACK_NEW_IMAGE ) {
			element.addEventListener( csCore.Enums.Events.STACK_NEW_IMAGE, function ( evt ) {
				var overlay = document.getElementById( 'nv-imaging-instance-overlay' );
				if ( overlay ) {
					var idx = ( evt.detail && typeof evt.detail.imageIdIndex !== 'undefined' )
						? evt.detail.imageIdIndex + 1
						: '';
					overlay.textContent = idx + ' / ' + imageIds.length;
				}
			} );
		}

		// Populate W/L toolbar now that we have the viewport instance.
		initWLToolbar( vp );

		// Extra toolbar actions (flip, rotate, screenshot).
		initExtraTools( vp );

		// Keyboard shortcuts for the viewer.
		initKeyboardShortcuts( vp );

		// Set up tools.
		var toolGroupId = 'nvToolGroup';

		// Destroy old tool group if present.
		if ( toolGroup ) {
			csTools.ToolGroupManager.destroyToolGroup( toolGroupId );
		}
		toolGroup = csTools.ToolGroupManager.createToolGroup( toolGroupId );

		csTools.addTool( csTools.StackScrollMouseWheelTool );
		csTools.addTool( csTools.PanTool );
		csTools.addTool( csTools.ZoomTool );
		csTools.addTool( csTools.LengthTool );
		csTools.addTool( csTools.WindowLevelTool );

		toolGroup.addTool( csTools.StackScrollMouseWheelTool.toolName );
		toolGroup.addTool( csTools.PanTool.toolName );
		toolGroup.addTool( csTools.ZoomTool.toolName );
		toolGroup.addTool( csTools.LengthTool.toolName );
		toolGroup.addTool( csTools.WindowLevelTool.toolName );

		toolGroup.addViewport( viewportId, renderingEngineId );

		// Active tools.
		toolGroup.setToolActive( csTools.StackScrollMouseWheelTool.toolName );
		toolGroup.setToolActive( csTools.WindowLevelTool.toolName, {
			bindings: [ { mouseButton: 1 } ],
		} );
		toolGroup.setToolActive( csTools.PanTool.toolName, {
			bindings: [ { mouseButton: 2 } ],
		} );
		toolGroup.setToolActive( csTools.ZoomTool.toolName, {
			bindings: [ { mouseButton: 3 } ],
		} );
	}

	// =========================================================================
	// Extra viewer tools
	// =========================================================================

	/**
	 * Normalize a rotation angle to [0, 360) degrees.
	 *
	 * @param {number} current Current rotation in degrees.
	 * @param {number} delta   Degrees to add (positive = CW, negative = CCW).
	 * @returns {number}
	 */
	function normalizeRotation( current, delta ) {
		return ( ( current + delta ) % 360 + 360 ) % 360;
	}

	/**
	 * Wire up the extra toolbar buttons (flip H/V, rotate, screenshot).
	 *
	 * @param {object} vp Cornerstone3D Stack Viewport instance.
	 */
	function initExtraTools( vp ) {
		var btnFlipH = document.getElementById( 'nv-imaging-btn-fliph' );
		var btnFlipV = document.getElementById( 'nv-imaging-btn-flipv' );
		var btnRotCW = document.getElementById( 'nv-imaging-btn-rotate-cw' );
		var btnRotCCW = document.getElementById( 'nv-imaging-btn-rotate-ccw' );
		var btnShot  = document.getElementById( 'nv-imaging-btn-screenshot' );

		if ( btnFlipH ) {
			btnFlipH.onclick = function () {
				try {
					var p = vp.getCamera();
					vp.setCamera( Object.assign( {}, p, { flipHorizontal: ! p.flipHorizontal } ) );
					vp.render();
				} catch ( _e ) {}
			};
		}
		if ( btnFlipV ) {
			btnFlipV.onclick = function () {
				try {
					var p = vp.getCamera();
					vp.setCamera( Object.assign( {}, p, { flipVertical: ! p.flipVertical } ) );
					vp.render();
				} catch ( _e ) {}
			};
		}
		if ( btnRotCW ) {
			btnRotCW.onclick = function () {
				try {
					vp.setProperties( { rotation: normalizeRotation( vp.getProperties().rotation || 0, 90 ) } );
					vp.render();
				} catch ( _e ) {}
			};
		}
		if ( btnRotCCW ) {
			btnRotCCW.onclick = function () {
				try {
					vp.setProperties( { rotation: normalizeRotation( vp.getProperties().rotation || 0, -90 ) } );
					vp.render();
				} catch ( _e ) {}
			};
		}
		if ( btnShot ) {
			btnShot.onclick = function () {
				try {
					var canvas = document.querySelector( '#nv-cs3d-el canvas' );
					if ( canvas ) {
						var link = document.createElement( 'a' );
						link.download = 'dicom-view.png';
						link.href = canvas.toDataURL( 'image/png' );
						link.click();
					}
				} catch ( _e ) {}
			};
		}
	}

	/**
	 * Register keyboard shortcuts for the viewer.
	 *
	 * Arrow keys scroll through stack frames; R resets; I inverts.
	 *
	 * @param {object} vp Cornerstone3D Stack Viewport instance.
	 */
	function initKeyboardShortcuts( vp ) {
		document.addEventListener( 'keydown', function ( e ) {
			if ( ! viewerPanel || viewerPanel.style.display === 'none' ) { return; }
			if ( e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' ) { return; }
			switch ( e.key ) {
				case 'ArrowLeft':
				case 'ArrowUp':
					try { vp.scroll( -1 ); vp.render(); } catch ( _e ) {}
					break;
				case 'ArrowRight':
				case 'ArrowDown':
					try { vp.scroll( 1 ); vp.render(); } catch ( _e ) {}
					break;
				case 'r':
				case 'R':
					try { vp.resetProperties(); vp.resetCamera(); vp.render(); } catch ( _e ) {}
					break;
				case 'i':
				case 'I':
					try {
						var p = vp.getProperties();
						vp.setProperties( { invert: ! p.invert } );
						vp.render();
					} catch ( _e ) {}
					break;
			}
		} );
	}

	// =========================================================================
	// Upload flow
	// =========================================================================

	function initUpload() {
		if ( uploadBtn && uploadPanel ) {
			uploadBtn.addEventListener( 'click', function () {
				uploadPanel.style.display = uploadPanel.style.display === 'none' ? '' : 'none';
			} );
		}

		if ( uploadCancelBtn && uploadPanel ) {
			uploadCancelBtn.addEventListener( 'click', function () {
				uploadPanel.style.display = 'none';
			} );
		}

		// Folder-mode toggle: radio buttons switch the file input between
		// individual-file mode (default) and folder-selection mode.
		var modeFiles  = document.getElementById( 'nv-imaging-mode-files' );
		var modeFolder = document.getElementById( 'nv-imaging-mode-folder' );
		var fileInput  = document.getElementById( 'nv-imaging-file-input' );

		function applyUploadMode( mode ) {
			if ( ! fileInput ) {
				return;
			}
			if ( 'folder' === mode ) {
				fileInput.setAttribute( 'webkitdirectory', '' );
				fileInput.removeAttribute( 'accept' ); // webkitdirectory conflicts with accept in some browsers.
			} else {
				fileInput.removeAttribute( 'webkitdirectory' );
				fileInput.setAttribute( 'accept', '.dcm' );
			}
		}

		if ( modeFiles ) {
			modeFiles.addEventListener( 'change', function () {
				if ( modeFiles.checked ) { applyUploadMode( 'files' ); }
			} );
		}
		if ( modeFolder ) {
			modeFolder.addEventListener( 'change', function () {
				if ( modeFolder.checked ) { applyUploadMode( 'folder' ); }
			} );
		}

		// Show file count badge after user selects files.
		if ( fileInput ) {
			fileInput.addEventListener( 'change', function () {
				updateFileCountBadge( fileInput );
			} );
		}

		// Drag-and-drop support on the upload panel.
		// Users can drop .dcm files (or a DICOM folder) directly onto the panel.
		if ( uploadPanel ) {
			uploadPanel.addEventListener( 'dragover', function ( e ) {
				e.preventDefault();
				uploadPanel.classList.add( 'nv-imaging-drag-over' );
			} );
			uploadPanel.addEventListener( 'dragleave', function ( e ) {
				if ( ! uploadPanel.contains( e.relatedTarget ) ) {
					uploadPanel.classList.remove( 'nv-imaging-drag-over' );
				}
			} );
			uploadPanel.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				uploadPanel.classList.remove( 'nv-imaging-drag-over' );
				if ( fileInput && e.dataTransfer && e.dataTransfer.files.length ) {
					// Assign dropped files to the file input via DataTransfer.
					try {
						var dt = new DataTransfer();
						var dropped = e.dataTransfer.files;
						for ( var di = 0; di < dropped.length; di++ ) {
							dt.items.add( dropped[ di ] );
						}
						fileInput.files = dt.files;
						updateFileCountBadge( fileInput );
					} catch ( _dtErr ) {
						// DataTransfer assignment not supported — silently skip.
					}
				}
			} );
		}

		/**
		 * Update (or create) a small badge below the file input showing how
		 * many .dcm files are queued for upload.
		 *
		 * @param {HTMLInputElement} fi File input element.
		 */
		function updateFileCountBadge( fi ) {
			var badgeId = 'nv-imaging-file-count';
			var badge   = document.getElementById( badgeId );
			if ( ! badge ) {
				badge    = document.createElement( 'span' );
				badge.id = badgeId;
				badge.className = 'nv-imaging-file-count';
				if ( fi.parentNode ) {
					fi.parentNode.insertBefore( badge, fi.nextSibling );
				}
			}
			var isFolderMode = modeFolder && modeFolder.checked;
			var count = 0;
			if ( fi.files ) {
				for ( var k = 0; k < fi.files.length; k++ ) {
					if ( ! isFolderMode || fi.files[ k ].name.toLowerCase().endsWith( '.dcm' ) ) {
						count++;
					}
				}
			}
			badge.textContent = count > 0
				? count + ' file' + ( 1 === count ? '' : 's' ) + ' selected'
				: '';
		}

		if ( uploadForm ) {
			uploadForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fi = document.getElementById( 'nv-imaging-file-input' );
				if ( ! fi || ! fi.files.length ) {
					showUploadStatus( 'Please select at least one .dcm file.', true );
					return;
				}

				var isFolderMode = modeFolder && modeFolder.checked;
				var formData = new FormData();
				var addedCount = 0;
				for ( var i = 0; i < fi.files.length; i++ ) {
					var f = fi.files[ i ];
					// In folder mode, only submit .dcm files (skip non-DICOM files
					// present in the folder such as DICOMDIR, README, images, etc.).
					if ( isFolderMode && ! f.name.toLowerCase().endsWith( '.dcm' ) ) {
						continue;
					}
					formData.append( 'dicom_files[]', f );
					addedCount++;
				}

				if ( 0 === addedCount ) {
					showUploadStatus( i18n.noFilesInFolder || 'No .dcm files found in the selected folder.', true );
					return;
				}

				showUploadStatus( 'Uploading… 0%', false );

				// Use XMLHttpRequest instead of fetch to enable upload progress events.
				// The progress callback runs on the main thread so the nonce header
				// remains in scope (fetch does not expose upload progress natively).
				var xhr = new XMLHttpRequest();

				xhr.upload.addEventListener( 'progress', function ( pe ) {
					if ( pe.lengthComputable && pe.total > 0 ) {
						var pct = Math.round( ( pe.loaded / pe.total ) * 100 );
						showUploadStatus( 'Uploading… ' + pct + '%', false );
					}
				} );

				xhr.addEventListener( 'load', function () {
					var result;
					try {
						result = JSON.parse( xhr.responseText );
					} catch ( _parseErr ) {
						result = null;
					}

					if ( xhr.status >= 200 && xhr.status < 300 && result && result.study_id ) {
						// Check for per-file errors (partial success).
						var files = Array.isArray( result.files ) ? result.files : [];
						var failedFiles = files.filter( function ( file ) { return file.error; } );

						if ( failedFiles.length > 0 ) {
							var partialMsg = ( i18n.uploadPartialSuccess || '%1$d of %2$d file(s) could not be processed.' )
								.replace( '%1$d', String( failedFiles.length ) )
								.replace( '%2$d', String( files.length ) );
							showUploadStatus( partialMsg, false );
						} else {
							showUploadStatus( i18n.uploadSuccess || 'Uploaded.', false );
						}
						uploadPanel.style.display = 'none';
						loadStudyList();
						loadStats();
						// Refresh the study dropdown on the AI Tools tab.
						refreshStudyDropdown();
					} else {
						var errMsg = ( result && result.message )
							? result.message
							: ( i18n.uploadError || 'Upload failed.' );
						showUploadStatus( errMsg, true );
					}
				} );

				xhr.addEventListener( 'error', function () {
					showUploadStatus( i18n.uploadError || 'Upload failed.', true );
				} );

				xhr.open( 'POST', restBase + '/upload' );
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				xhr.send( formData );
			} );
		}
	}

	// =========================================================================
	// Navigation
	// =========================================================================

	function initNavigation() {
		if ( backBtn ) {
			backBtn.addEventListener( 'click', function () {
				if ( viewerPanel ) {
					viewerPanel.style.display = 'none';
				}
				var mainPanel = document.getElementById( 'nv-imaging-main-panel' );
				if ( mainPanel ) {
					mainPanel.style.display = '';
				}
			} );
		}
	}

	// =========================================================================
	// Bootstrap
	// =========================================================================

	function initAll() {
		initNavigation();
		initUpload();
		initTabs();
		initFilters();
		initToolsTab();
		loadStudyList();
		loadStats();
	}

	// Module scripts (type="module") are deferred: they execute after the HTML
	// is parsed, at which point readyState is no longer 'loading'.  Regular
	// footer scripts also run after parsing in most cases, but guard for both.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
