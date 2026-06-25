/**
 * NV oOS Docs Hub — Admin Repo Picker
 *
 * Handles the add/remove repo row UI and the "Browse" tree picker on the
 * Settings → NV oOS Docs Hub page.
 *
 * Expects window.NVOOS_DH_REPO_PICKER to be set via wp_localize_script:
 *   {
 *     restBase:  string,   // REST tree endpoint URL
 *     restNonce: string,   // wp_rest nonce
 *     i18n: {
 *       enterOwnerRepo:  string,
 *       loading:         string,
 *       filesFound:      string,
 *       noFilesFound:    string
 *     }
 *   }
 *
 * @since 0.3.8
 */
( function () {
	var cfg = window.NVOOS_DH_REPO_PICKER || {};
	var i18n = cfg.i18n || {};

	var restBase  = cfg.restBase  || '';
	var restNonce = cfg.restNonce || '';

	var wrap   = document.getElementById( 'nvoos-dh-remote-repos-wrap' );
	var addBtn = document.getElementById( 'nvoos-dh-add-repo' );

	if ( ! wrap || ! addBtn ) {
		return;
	}

	function reindexFields( row, idx ) {
		row.querySelectorAll( '[name]' ).forEach( function ( el ) {
			var n = el.getAttribute( 'name' );
			if ( n ) {
				el.setAttribute( 'name', n.replace( /\[\d+\]/, '[' + idx + ']' ) );
			}
		} );
		// data-row-index on picker buttons.
		row.querySelectorAll( '[data-row-index]' ).forEach( function ( el ) {
			el.setAttribute( 'data-row-index', String( idx ) );
		} );
	}

	addBtn.addEventListener( 'click', function () {
		var rows = wrap.querySelectorAll( '.nvoos-dh-remote-repo-row' );
		var idx  = rows.length;
		var tpl  = rows[ 0 ].cloneNode( true );

		// Clear input/textarea values on the clone.
		tpl.querySelectorAll( 'input' ).forEach( function ( el ) {
			if ( el.type === 'radio' || el.type === 'checkbox' ) {
				// Reset radios to "all" mode for selection_mode group.
				if ( el.name && /selection_mode/.test( el.name ) ) {
					el.checked = ( el.value === 'all' );
				} else {
					el.checked = false;
				}
			} else {
				el.value = '';
			}
		} );
		tpl.querySelectorAll( 'textarea' ).forEach( function ( el ) {
			el.value = '';
		} );

		// Hide any open picker tree on the clone.
		var clonedTree = tpl.querySelector( '.nvoos-dh-picker-tree' );
		if ( clonedTree ) {
			clonedTree.style.display = 'none';
			clonedTree.innerHTML = '';
		}
		var clonedStatus = tpl.querySelector( '.nvoos-dh-picker-status' );
		if ( clonedStatus ) {
			clonedStatus.textContent = '';
		}

		reindexFields( tpl, idx );
		wrap.appendChild( tpl );
	} );

	wrap.addEventListener( 'click', function ( e ) {
		var t = e.target;

		if ( t && t.classList.contains( 'nvoos-dh-remove-repo' ) ) {
			var row = t.closest( '.nvoos-dh-remote-repo-row' );
			if ( wrap.querySelectorAll( '.nvoos-dh-remote-repo-row' ).length > 1 ) {
				row.remove();
				wrap.querySelectorAll( '.nvoos-dh-remote-repo-row' ).forEach( function ( r, i ) {
					reindexFields( r, i );
				} );
			} else {
				row.querySelectorAll( 'input' ).forEach( function ( el ) {
					if ( el.type === 'radio' ) {
						el.checked = ( el.value === 'all' && /selection_mode/.test( el.name || '' ) );
					} else if ( el.type !== 'checkbox' ) {
						el.value = '';
					}
				} );
				row.querySelectorAll( 'textarea' ).forEach( function ( el ) {
					el.value = '';
				} );
			}
			return;
		}

		if ( t && ( t.classList.contains( 'nvoos-dh-browse-btn' ) || t.classList.contains( 'nvoos-dh-refresh-btn' ) ) ) {
			e.preventDefault();
			openPicker( t.closest( '.nvoos-dh-remote-repo-row' ), t.classList.contains( 'nvoos-dh-refresh-btn' ) );
		}

		// "Test Connection" — quick probe without opening the file tree.
		if ( t && t.classList.contains( 'nvoos-dh-test-btn' ) ) {
			e.preventDefault();
			testConnection( t.closest( '.nvoos-dh-remote-repo-row' ) );
		}
	} );

	function fieldVal( row, suffix ) {
		var el = row.querySelector( '[name$="[' + suffix + ']"]' );
		return el ? String( el.value || '' ).trim() : '';
	}

	function openPicker( row, force ) {
		if ( ! row ) {
			return;
		}
		var tree   = row.querySelector( '.nvoos-dh-picker-tree' );
		var status = row.querySelector( '.nvoos-dh-picker-status' );
		if ( ! tree || ! status ) {
			return;
		}

		var owner = fieldVal( row, 'owner' );
		var repo  = fieldVal( row, 'repo' );
		var ref   = fieldVal( row, 'ref' ) || 'HEAD';
		var path  = fieldVal( row, 'path' );

		if ( ! owner || ! repo ) {
			status.textContent = i18n.enterOwnerRepo || 'Enter owner and repo first.';
			return;
		}

		// Resolve persisted-row index from the browse button itself.
		var btn = row.querySelector( '.nvoos-dh-browse-btn' );
		var idx = btn ? parseInt( btn.getAttribute( 'data-row-index' ) || '-1', 10 ) : -1;

		status.textContent = i18n.loading || 'Loading\u2026';
		tree.style.display = 'block';
		tree.innerHTML = '';

		var sep = restBase.indexOf( '?' ) === -1 ? '?' : '&';
		var url = restBase
			+ sep + 'owner=' + encodeURIComponent( owner )
			+ '&repo='  + encodeURIComponent( repo )
			+ '&ref='   + encodeURIComponent( ref )
			+ '&path='  + encodeURIComponent( path )
			+ '&index=' + encodeURIComponent( idx )
			+ ( force ? '&force=1' : '' );

		fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
						if ( ! r.ok ) {
							// Try to read the error body — might be JSON or might
							// be a PHP fatal-error HTML page.
							var ct = r.headers.get( 'content-type' ) || '';
							if ( ct.indexOf( 'application/json' ) !== -1 ) {
								return r.json().then( function ( body ) {
									return { ok: false, body: body };
								} );
							}
							return r.text().then( function ( text ) {
								if ( text.indexOf( 'critical error' ) !== -1 ) {
									return { ok: false, body: { message: 'Server returned a fatal error (HTTP ' + r.status + '). Check the WordPress debug log.' } };
								}
								return { ok: false, body: { message: 'Request failed (HTTP ' + r.status + ').' } };
							} );
						}
						return r.json().then( function ( body ) {
							return { ok: true, body: body };
						} );
					} ).then( function ( res ) {
						if ( ! res.ok || ! res.body || ! Array.isArray( res.body.files ) ) {
							var msg = ( res.body && res.body.message ) ? res.body.message : 'Request failed';
							status.textContent = msg;
							return;
						}
						renderTree( row, tree, res.body.files );
						status.textContent = res.body.files.length + ' ' + ( i18n.filesFound || 'files found.' );
					} ).catch( function ( err ) {
						status.textContent = ( err && err.message ) ? err.message : 'Request failed';
					} );
	}

		/**
		 * Quick connectivity test: resolves owner/repo/ref and returns the
		 * count of discoverable Markdown files without rendering the tree.
		 */
		function testConnection( row ) {
		if ( ! row ) { return; }
		var status = row.querySelector( '.nvoos-dh-picker-status' );
		if ( ! status ) { return; }

		var owner = fieldVal( row, 'owner' );
		var repo  = fieldVal( row, 'repo' );
		var ref   = fieldVal( row, 'ref' ) || 'HEAD';
		var path  = fieldVal( row, 'path' );

		if ( ! owner || ! repo ) {
			status.textContent = i18n.enterOwnerRepo || 'Enter owner and repo first.';
			return;
		}

		var btn = row.querySelector( '.nvoos-dh-test-btn' );
		var idx = btn ? parseInt( btn.getAttribute( 'data-row-index' ) || '-1', 10 ) : -1;

		status.textContent = i18n.loading || 'Loading\u2026';

		var sep = restBase.indexOf( '?' ) === -1 ? '?' : '&';
		var url = restBase
			+ sep + 'owner=' + encodeURIComponent( owner )
			+ '&repo='  + encodeURIComponent( repo )
			+ '&ref='   + encodeURIComponent( ref )
			+ '&path='  + encodeURIComponent( path )
			+ '&index=' + encodeURIComponent( idx )
			+ '&force=1';

		fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
			return r.json().then( function ( body ) { return { ok: r.ok, body: body }; } );
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				var msg = ( res.body && res.body.message ) ? res.body.message : 'HTTP ' + ( res.body && res.body.code ? res.body.code : 'error' );
				status.textContent = '\u274c ' + msg;
				return;
			}
			if ( res.body && Array.isArray( res.body.files ) ) {
				status.textContent = '\u2705 ' + res.body.files.length + ' ' + ( i18n.filesFound || 'files found.' );
			} else if ( res.body && res.body.message ) {
				status.textContent = '\u274c ' + res.body.message;
			} else {
				status.textContent = '\u274c Request failed';
			}
		} ).catch( function ( err ) {
			status.textContent = '\u274c ' + ( ( err && err.message ) ? err.message : 'Request failed' );
		} );
	}

	/**
	 * Quick connectivity test: resolves owner/repo/ref and returns the
	 * count of discoverable Markdown files without rendering the tree.
	 */
	function testConnection( row ) {
		if ( ! row ) { return; }
		var status = row.querySelector( '.nvoos-dh-picker-status' );
		if ( ! status ) { return; }

		var owner = fieldVal( row, 'owner' );
		var repo  = fieldVal( row, 'repo' );
		var ref   = fieldVal( row, 'ref' ) || 'HEAD';
		var path  = fieldVal( row, 'path' );

		if ( ! owner || ! repo ) {
			status.textContent = i18n.enterOwnerRepo || 'Enter owner and repo first.';
			return;
		}

		var btn = row.querySelector( '.nvoos-dh-test-btn' );
		var idx = btn ? parseInt( btn.getAttribute( 'data-row-index' ) || '-1', 10 ) : -1;

		status.textContent = i18n.loading || 'Loading\u2026';

		var url = restBase
			+ '?owner=' + encodeURIComponent( owner )
			+ '&repo='  + encodeURIComponent( repo )
			+ '&ref='   + encodeURIComponent( ref )
			+ '&path='  + encodeURIComponent( path )
			+ '&index=' + encodeURIComponent( idx )
			+ '&force=1';

		fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
			return r.json().then( function ( body ) { return { ok: r.ok, body: body }; } );
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				var msg = ( res.body && res.body.message ) ? res.body.message : 'HTTP ' + ( res.body && res.body.code ? res.body.code : 'error' );
				status.textContent = '\u274c ' + msg;
				return;
			}
			if ( res.body && Array.isArray( res.body.files ) ) {
				status.textContent = '\u2705 ' + res.body.files.length + ' ' + ( i18n.filesFound || 'files found.' );
			} else if ( res.body && res.body.message ) {
				status.textContent = '\u274c ' + res.body.message;
			} else {
				status.textContent = '\u274c Request failed';
			}
		} ).catch( function ( err ) {
			status.textContent = '\u274c ' + ( ( err && err.message ) ? err.message : 'Request failed' );
		} );
	}

	function getSelectedPaths( row ) {
		var ta = row.querySelector( '.nvoos-dh-selected-paths' );
		if ( ! ta ) {
			return [];
		}
		return String( ta.value || '' ).split( /\r\n|\r|\n/ )
			.map( function ( s ) { return s.trim(); } )
			.filter( function ( s ) { return s.length > 0; } );
	}

	function setSelectedPaths( row, paths ) {
		var ta = row.querySelector( '.nvoos-dh-selected-paths' );
		if ( ta ) {
			ta.value = paths.join( '\n' );
		}
	}

	function pathIsSelected( filePath, selected ) {
		for ( var i = 0; i < selected.length; i++ ) {
			var s = selected[ i ];
			if ( s.charAt( s.length - 1 ) === '/' ) {
				var dir = s.replace( /\/+$/, '' );
				if ( filePath === dir || filePath.indexOf( dir + '/' ) === 0 ) {
					return true;
				}
			} else if ( filePath === s ) {
				return true;
			}
		}
		return false;
	}

	function renderTree( row, container, files ) {
		container.innerHTML = '';
		var selected = getSelectedPaths( row );

		var listEl = document.createElement( 'ul' );
		listEl.style.listStyle = 'none';
		listEl.style.paddingLeft = '0';
		listEl.style.margin = '0';

		files.forEach( function ( f ) {
			var li = document.createElement( 'li' );
			li.style.padding = '2px 0';
			li.style.fontFamily = 'Menlo, Consolas, monospace';
			li.style.fontSize = '12px';

			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.value = f.path;
			cb.checked = pathIsSelected( f.path, selected );
			cb.style.marginRight = '6px';

			cb.addEventListener( 'change', function () {
				var current = getSelectedPaths( row );
				if ( cb.checked ) {
					if ( current.indexOf( f.path ) === -1 ) {
						current.push( f.path );
					}
				} else {
					current = current.filter( function ( p ) { return p !== f.path; } );
				}
				setSelectedPaths( row, current );
			} );

			var label = document.createElement( 'label' );
			label.style.cursor = 'pointer';
			label.appendChild( cb );
			label.appendChild( document.createTextNode( f.path + '  ' ) );

			if ( typeof f.size === 'number' && f.size > 0 ) {
				var size = document.createElement( 'span' );
				size.style.color = '#646970';
				size.textContent = '(' + Math.round( f.size / 1024 * 10 ) / 10 + ' KB)';
				label.appendChild( size );
			}

			li.appendChild( label );
			listEl.appendChild( li );
		} );

		if ( files.length === 0 ) {
			var empty = document.createElement( 'p' );
			empty.style.color = '#646970';
			empty.textContent = i18n.noFilesFound || 'No Markdown files found in this ref / path.';
			container.appendChild( empty );
			return;
		}

		container.appendChild( listEl );
	}
} )();
