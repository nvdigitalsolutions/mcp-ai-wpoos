/**
 * NV oOS Medical Imaging Viewer
 *
 * Bootstraps a Cornerstone3D-based DICOM stack viewer inside the
 * WordPress admin panel under Health & Wellness → Imaging Viewer.
 *
 * Architecture:
 *  - All data fetched via WP REST API (signed nonce auth).
 *  - Cornerstone3D loaded from CDN via ES module import (see importmap below).
 *  - No PHI is written to the DOM; study labels use de-identified IDs.
 *
 * Build note:
 *  This file is intentionally written as a plain ES2017+ module so it can be
 *  included directly (browsers supporting importmaps) or bundled with esbuild.
 *  To build the production bundle:
 *    npx esbuild assets/js/imaging-viewer.js --bundle --outfile=assets/js/imaging-viewer.min.js
 *
 * CDN strategy:
 *  Cornerstone3D packages are loaded from esm.sh (a production-grade CDN for
 *  ES modules).  This avoids needing to commit large vendor bundles to the repo.
 *  For air-gapped deployments, run `npm install @cornerstonejs/core @cornerstonejs/tools
 *  @cornerstonejs/dicom-image-loader` and update the importmap to local paths.
 *
 * @package WP_MCP_AI_Pro
 */

/* global wpMcpAiImaging */
( function () {
	'use strict';

	// =========================================================================
	// DOM references
	// =========================================================================
	var cfg = window.wpMcpAiImaging || {};
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
	 * Wire up the Studies / Audit Log tab buttons.
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

		tabLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				tabLinks.forEach( function ( l ) { l.classList.remove( 'nav-tab-active' ); } );
				link.classList.add( 'nav-tab-active' );
				var tab = link.dataset.tab;

				// Show only the matching panel.
				allTabPanels.forEach( function ( panel ) {
					var el = document.getElementById( panel.id );
					if ( el ) { el.style.display = ( panel.key === tab ) ? '' : 'none'; }
				} );

				// Lazy-load on demand.
				if ( tab === 'audit' ) { loadAuditLog(); }
			} );
		} );
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
	 * Wire up the AI Interpretation form on the Tools tab.
	 */
	function initToolsTab() {
		var runBtn   = document.getElementById( 'nv-imaging-interpret-run' );
		var uidInput = document.getElementById( 'nv-imaging-interpret-uid' );
		var focusSel = document.getElementById( 'nv-imaging-interpret-focus' );
		var resultEl = document.getElementById( 'nv-imaging-interpret-result' );

		if ( ! runBtn ) {
			return;
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
					viewport.innerHTML = '<p class="nv-imaging-error">' + escHtml( err.message ) + '</p>';
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

		var imageIds = ( series.instances || [] ).map( function ( inst ) {
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

		// Dynamically import Cornerstone3D packages.
		// Production deployments should bundle these locally via:
		//   npm install @cornerstonejs/core @cornerstonejs/tools @cornerstonejs/dicom-image-loader
		//   npx esbuild assets/js/imaging-viewer.js --bundle --outfile=assets/js/imaging-viewer.min.js
		// The CDN fallback (esm.sh) is used when a local bundle is not available.
		// For HIPAA-compliant air-gapped deployments, always use the local bundle.
		Promise.all( [
			import( 'https://esm.sh/@cornerstonejs/core@1' ),
			import( 'https://esm.sh/@cornerstonejs/tools@1' ),
			import( 'https://esm.sh/@cornerstonejs/dicom-image-loader@1' ),
		] )
			.then( function ( modules ) {
				return bootCornerstone( modules[ 0 ], modules[ 1 ], modules[ 2 ], imageIds );
			} )
			.catch( function ( err ) {
				viewport.innerHTML = '<p class="nv-imaging-error">' + escHtml( 'Viewer error: ' + err.message ) + '</p>';
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
			if ( csDicomImageLoader.external ) {
				csDicomImageLoader.external.cornerstone = csCore;
			}

			// Configure authentication headers sent with every XHR-based DICOM fetch.
			// `beforeSend` is the standard hook in @cornerstonejs/dicom-image-loader v1.
			if ( csDicomImageLoader.configure ) {
				csDicomImageLoader.configure( {
					useWebWorkers: false,
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', nonce );
					},
				} );
			}

			await csCore.init();
			await csTools.init();

			// Call the loader's own init AFTER external.cornerstone is set.
			if ( csDicomImageLoader.init ) {
				csDicomImageLoader.init( { maxWebWorkers: 1 } );
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
						}
					} catch ( _e ) {}
				},
				{ once: true }
			);
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

		if ( uploadForm ) {
			uploadForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fileInput = document.getElementById( 'nv-imaging-file-input' );
				if ( ! fileInput || ! fileInput.files.length ) {
					showUploadStatus( 'Please select at least one .dcm file.', true );
					return;
				}

				var formData = new FormData();
				for ( var i = 0; i < fileInput.files.length; i++ ) {
					formData.append( 'dicom_files[]', fileInput.files[ i ] );
				}

				showUploadStatus( 'Uploading…', false );

				fetch( restBase + '/upload', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce },
					body: formData,
				} )
					.then( function ( res ) {
						return res.json().then( function ( data ) {
							return { ok: res.ok, data: data };
						} );
					} )
					.then( function ( result ) {
						if ( result.ok && result.data && result.data.study_id ) {
							// Check for per-file errors (partial success).
							var files = Array.isArray( result.data.files ) ? result.data.files : [];
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
						} else {
							var errMsg = ( result.data && result.data.message )
								? result.data.message
								: ( i18n.uploadError || 'Upload failed.' );
							showUploadStatus( errMsg, true );
						}
					} )
					.catch( function () {
						showUploadStatus( i18n.uploadError || 'Upload failed.', true );
					} );
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

	document.addEventListener( 'DOMContentLoaded', function () {
		initNavigation();
		initUpload();
		initTabs();
		initFilters();
		initToolsTab();
		loadStudyList();
		loadStats();
	} );
} )();
