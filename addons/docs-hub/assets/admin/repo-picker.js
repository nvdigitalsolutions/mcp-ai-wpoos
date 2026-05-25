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

		var url = restBase
			+ '?owner=' + encodeURIComponent( owner )
			+ '&repo='  + encodeURIComponent( repo )
			+ '&ref='   + encodeURIComponent( ref )
			+ '&path='  + encodeURIComponent( path )
			+ '&index=' + encodeURIComponent( idx )
			+ ( force ? '&force=1' : '' );

		fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': restNonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
			return r.json().then( function ( body ) {
				return { ok: r.ok, body: body };
			} );
		} ).then( function ( res ) {
			if ( ! res.ok || ! res.body ) {
				var msg = ( res.body && res.body.message ) ? res.body.message : 'Request failed';
				status.textContent = msg;
				return;
			}
			// Pass both the hierarchical tree and flat file list.
			// renderTree prefers tree when present, falls back to files.
			var treeNodes = res.body.tree || null;
			var flatFiles = res.body.files || [];
			var totalFiles = flatFiles.length;
			renderTree( row, tree, treeNodes, flatFiles );
			status.textContent = totalFiles + ' ' + ( i18n.filesFound || 'files found.' );
		} ).catch( function ( err ) {
			status.textContent = ( err && err.message ) ? err.message : 'Request failed';
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

	/**
	 * Collect all file paths under a tree node (recursive).
	 *
	 * @param {Object} node  Tree node.
	 * @return {string[]} Flat array of file paths.
	 */
	function collectFilePaths( node ) {
		if ( node.type === 'blob' ) {
			return [ node.path ];
		}
		if ( ! node.children || ! node.children.length ) {
			return [];
		}
		var paths = [];
		node.children.forEach( function ( child ) {
			paths = paths.concat( collectFilePaths( child ) );
		} );
		return paths;
	}

	/**
	 * Update a folder checkbox's indeterminate state based on children.
	 *
	 * @param {HTMLInputElement} cb       Folder checkbox element.
	 * @param {Object}           node     Folder tree node.
	 * @param {string[]}         selected Current selected paths.
	 */
	function updateFolderCheck( cb, node, selected ) {
		var allFiles = collectFilePaths( node );
		if ( allFiles.length === 0 ) {
			cb.checked = false;
			cb.indeterminate = false;
			return;
		}
		// Check if the folder itself is selected (trailing-slash entry).
		var folderEntry = node.path + '/';
		if ( selected.indexOf( folderEntry ) !== -1 ) {
			cb.checked = true;
			cb.indeterminate = false;
			return;
		}
		var selectedCount = 0;
		allFiles.forEach( function ( p ) {
			if ( selected.indexOf( p ) !== -1 ) {
				selectedCount++;
			}
		} );
		cb.checked = selectedCount === allFiles.length;
		cb.indeterminate = selectedCount > 0 && selectedCount < allFiles.length;
	}

	/**
	 * Build a DOM tree element from hierarchical tree data.
	 *
	 * @param {Object[]} nodes     Tree nodes (output of build_tree_from_flat).
	 * @param {Element}  row       The .nvoos-dh-remote-repo-row DOM element.
	 * @param {string[]} selected  Current selected paths.
	 * @param {number}   depth     Nesting depth for indentation.
	 * @return {HTMLUListElement}
	 */
	function buildTreeDOM( nodes, row, selected, depth ) {
		depth = depth || 0;
		var ul = document.createElement( 'ul' );
		ul.style.listStyle = 'none';
		ul.style.paddingLeft = ( depth === 0 ? '0' : '16px' );
		ul.style.margin = '0';

		nodes.forEach( function ( node ) {
			var li = document.createElement( 'li' );
			li.style.padding = '2px 0';
			li.style.fontFamily = 'Menlo, Consolas, monospace';
			li.style.fontSize = '12px';
			li.style.lineHeight = '1.6';

			if ( node.type === 'tree' ) {
				// --- Folder node ---
				var toggle = document.createElement( 'span' );
				toggle.textContent = '\u25B6';  // ▶ right-pointing triangle (collapsed)
				toggle.style.cursor = 'pointer';
				toggle.style.display = 'inline-block';
				toggle.style.width = '14px';
				toggle.style.fontSize = '10px';
				toggle.style.marginRight = '2px';
				toggle.style.textAlign = 'center';
				toggle.style.transition = 'transform 0.15s';
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.setAttribute( 'aria-label', 'Expand folder' );

				var cb = document.createElement( 'input' );
				cb.type = 'checkbox';
				cb.value = node.path;
				cb.style.marginRight = '4px';
				cb.style.verticalAlign = 'middle';
				updateFolderCheck( cb, node, selected );

				var icon = document.createElement( 'span' );
				icon.textContent = '\uD83D\uDCC1';  // 📁 folder icon
				icon.style.marginRight = '4px';

				var nameEl = document.createElement( 'span' );
				nameEl.textContent = node.name + '/';
				nameEl.style.cursor = 'pointer';
				nameEl.style.fontWeight = '500';

				var childContainer = document.createElement( 'div' );
				childContainer.style.display = 'none';
				childContainer.style.marginLeft = '0';

				if ( node.children && node.children.length ) {
					childContainer.appendChild( buildTreeDOM( node.children, row, selected, depth + 1 ) );
				}

				// Toggle expand/collapse.
				var isExpanded = false;
				toggle.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					isExpanded = ! isExpanded;
					toggle.textContent = isExpanded ? '\u25BC' : '\u25B6';  // ▼ or ▶
					toggle.setAttribute( 'aria-expanded', String( isExpanded ) );
					toggle.setAttribute( 'aria-label', isExpanded ? 'Collapse folder' : 'Expand folder' );
					childContainer.style.display = isExpanded ? 'block' : 'none';
				} );
				nameEl.addEventListener( 'click', function () {
					isExpanded = ! isExpanded;
					toggle.textContent = isExpanded ? '\u25BC' : '\u25B6';
					toggle.setAttribute( 'aria-expanded', String( isExpanded ) );
					toggle.setAttribute( 'aria-label', isExpanded ? 'Collapse folder' : 'Expand folder' );
					childContainer.style.display = isExpanded ? 'block' : 'none';
				} );

				// Folder checkbox cascade: check/uncheck all descendant files.
				cb.addEventListener( 'change', function () {
					var current = getSelectedPaths( row );
					// When a folder is checked, we store the folder path with
					// trailing slash (e.g. "docs/guides/"). The PHP side's
					// matches_path_list() already handles directory matching.
					// We also remove any individual child file entries.
					var folderEntry = node.path + '/';
					var filesInFolder = collectFilePaths( node );
					if ( cb.checked ) {
						// Remove any individual descendant paths, add the folder.
						current = current.filter( function ( p ) {
							return filesInFolder.indexOf( p ) === -1;
						} );
						if ( current.indexOf( folderEntry ) === -1 ) {
							current.push( folderEntry );
						}
					} else {
						// Remove the folder entry.
						current = current.filter( function ( p ) {
							return p !== folderEntry && filesInFolder.indexOf( p ) === -1;
						} );
					}
					setSelectedPaths( row, current );

					// Refresh all visible checkboxes in the tree.
					refreshTreeChecks( row );
				} );

				li.appendChild( toggle );
				li.appendChild( cb );
				li.appendChild( icon );
				li.appendChild( nameEl );
				li.appendChild( childContainer );
			} else {
				// --- File node ---
				var indent = document.createElement( 'span' );
				indent.style.display = 'inline-block';
				indent.style.width = '16px';  // align with folder toggle

				var cbFile = document.createElement( 'input' );
				cbFile.type = 'checkbox';
				cbFile.value = node.path;
				// A file is selected if its path is explicitly listed, OR if
				// a parent folder path (with trailing slash) is in the selection.
				cbFile.checked = pathIsSelected( node.path, selected );
				cbFile.disabled = selected.some( function ( s ) {
					return s.charAt( s.length - 1 ) === '/' && pathIsSelected( node.path, [ s ] );
				} );
				cbFile.style.marginRight = '4px';
				cbFile.style.verticalAlign = 'middle';

				var fileIcon = document.createElement( 'span' );
				fileIcon.textContent = '\uD83D\uDCC4';  // 📄 page icon
				fileIcon.style.marginRight = '4px';

				var fileName = document.createElement( 'span' );
				fileName.textContent = node.name;

				cbFile.addEventListener( 'change', function () {
					var current = getSelectedPaths( row );
					if ( cbFile.checked ) {
						if ( current.indexOf( node.path ) === -1 ) {
							current.push( node.path );
						}
					} else {
						current = current.filter( function ( p ) { return p !== node.path; } );
					}
					setSelectedPaths( row, current );

					// Refresh folder checkboxes so parents update their state.
					refreshTreeChecks( row );
				} );

				li.appendChild( indent );
				li.appendChild( cbFile );
				li.appendChild( fileIcon );
				li.appendChild( fileName );

				// Show file size.
				if ( typeof node.size === 'number' && node.size > 0 ) {
					var sizeEl = document.createElement( 'span' );
					sizeEl.style.color = '#646970';
					sizeEl.style.marginLeft = '6px';
					sizeEl.style.fontSize = '11px';
					sizeEl.textContent = '(' + Math.round( node.size / 1024 * 10 ) / 10 + ' KB)';
					li.appendChild( sizeEl );
				}
			}

			ul.appendChild( li );
		} );

		return ul;
	}

	/**
	 * Refresh the checked/indeterminate state of every folder checkbox in the tree.
	 *
	 * Call this after individual file checkboxes change so parent folders
	 * correctly reflect the selection state of their descendants.
	 *
	 * @param {Element} row The repo row element.
	 */
	function refreshTreeChecks( row ) {
		var treeDiv = row.querySelector( '.nvoos-dh-picker-tree' );
		if ( ! treeDiv ) {
			return;
		}
		var selected = getSelectedPaths( row );

		// Walk all folder <li> elements and update their checkboxes.
		treeDiv.querySelectorAll( 'ul > li' ).forEach( function ( li ) {
			// Only target folder nodes — they contain a toggle span.
			var toggle = li.querySelector( 'span[aria-expanded]' );
			if ( ! toggle ) {
				return; // This is a file node.
			}
			var cb = li.querySelector( 'input[type="checkbox"]' );
			if ( ! cb ) {
				return;
			}

			// Collect all file checkbox values inside this folder's subtree.
			var allFiles = [];
			var childDiv = li.querySelector( 'div' );
			if ( childDiv ) {
				childDiv.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( childCb ) {
					if ( childCb.value ) {
						allFiles.push( childCb.value );
					}
				} );
			}
			if ( allFiles.length === 0 ) {
				cb.checked = false;
				cb.indeterminate = false;
				return;
			}

			// Check if the folder itself is selected.
			// The folder path stored in selected_paths has trailing slash.
			var folderPath = cb.value ? cb.value + '/' : '';
			if ( folderPath && selected.indexOf( folderPath ) !== -1 ) {
				cb.checked = true;
				cb.indeterminate = false;
				return;
			}

			var selCount = 0;
			allFiles.forEach( function ( p ) {
				if ( selected.indexOf( p ) !== -1 ) {
					selCount++;
				}
			} );
			cb.checked = selCount === allFiles.length;
			cb.indeterminate = selCount > 0 && selCount < allFiles.length;
		} );
	}

	function renderTree( row, container, treeNodes, flatFiles ) {
		container.innerHTML = '';
		var selected = getSelectedPaths( row );

		// If hierarchical tree data is available, render the folder tree.
		if ( treeNodes && Array.isArray( treeNodes ) && treeNodes.length > 0 ) {
			container._treeData = treeNodes;
			container.appendChild( buildTreeDOM( treeNodes, row, selected, 0 ) );
			return;
		}

		// Fallback: render flat file list (back-compat with older API responses).
		var files = flatFiles || [];
		if ( files.length === 0 ) {
			var empty = document.createElement( 'p' );
			empty.style.color = '#646970';
			empty.textContent = i18n.noFilesFound || 'No Markdown files found in this ref / path.';
			container.appendChild( empty );
			return;
		}

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
			cb.checked = selected.indexOf( f.path ) !== -1;
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
				var sizeEl = document.createElement( 'span' );
				sizeEl.style.color = '#646970';
				sizeEl.textContent = '(' + Math.round( f.size / 1024 * 10 ) / 10 + ' KB)';
				label.appendChild( sizeEl );
			}

			li.appendChild( label );
			listEl.appendChild( li );
		} );

		container.appendChild( listEl );
	}
} )();
