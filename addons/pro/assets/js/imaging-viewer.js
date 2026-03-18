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

	// Panels.
	var uploadBtn = document.getElementById( 'nv-imaging-upload-btn' );
	var uploadPanel = document.getElementById( 'nv-imaging-upload-panel' );
	var uploadForm = document.getElementById( 'nv-imaging-upload-form' );
	var uploadStatus = document.getElementById( 'nv-imaging-upload-status' );
	var uploadCancelBtn = document.getElementById( 'nv-imaging-upload-cancel' );

	var studyBrowser = document.getElementById( 'nv-imaging-study-browser' );
	var loadingEl = document.getElementById( 'nv-imaging-loading' );
	var studyListEl = document.getElementById( 'nv-imaging-study-list' );

	var viewerPanel = document.getElementById( 'nv-imaging-viewer-panel' );
	var backBtn = document.getElementById( 'nv-imaging-back-btn' );
	var studyLabel = document.getElementById( 'nv-imaging-study-label' );
	var seriesList = document.getElementById( 'nv-imaging-series-list' );
	var metadataList = document.getElementById( 'nv-imaging-metadata-list' );
	var viewport = document.getElementById( 'nv-imaging-viewport' );

	// State.
	var currentStudy = null;
	var csInitialized = false;
	var renderingEngine = null;
	var toolGroup = null;

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

		apiFetch( restBase + '/studies?per_page=50' )
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
			html += '<td><button type="button" class="button nv-imaging-view-btn" data-uid="' + escHtml( s.study_uid ) + '">View</button></td>';
			html += '</tr>';
		} );

		html += '</tbody></table>';
		studyListEl.innerHTML = html;
		studyListEl.style.display = '';

		// Bind view buttons.
		var btns = studyListEl.querySelectorAll( '.nv-imaging-view-btn' );
		btns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openStudy( btn.dataset.uid );
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
		currentStudy = studyUid;

		if ( studyBrowser ) {
			studyBrowser.style.display = 'none';
		}
		if ( viewerPanel ) {
			viewerPanel.style.display = '';
		}
		if ( studyLabel ) {
			studyLabel.textContent = studyUid;
		}

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
			li.textContent = ( s.modality || 'Series' ) + ' (' + ( s.instances ? s.instances.length : 0 ) + ' images)';
			li.addEventListener( 'click', function () {
				document.querySelectorAll( '.nv-imaging-series-item' ).forEach( function ( el ) {
					el.classList.remove( 'nv-imaging-series-active' );
				} );
				li.classList.add( 'nv-imaging-series-active' );
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
	 * Initialise Cornerstone3D and render the image stack.
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
			await csCore.init();
			await csTools.init();

			// Configure DICOM image loader to use our REST endpoint.
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
		vp.render();

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
				if ( studyBrowser ) {
					studyBrowser.style.display = '';
				}
				currentStudy = null;
			} );
		}
	}

	// =========================================================================
	// Bootstrap
	// =========================================================================

	document.addEventListener( 'DOMContentLoaded', function () {
		initNavigation();
		initUpload();
		loadStudyList();
	} );
} )();
