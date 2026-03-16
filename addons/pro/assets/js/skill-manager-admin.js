/* global wpMcpAiSkillManager */
/**
 * Skill Manager Admin – JavaScript entry point.
 *
 * All PHP-side data is injected via wp_localize_script() under the global
 * object `wpMcpAiSkillManager` with the following shape:
 *
 *   {
 *     nonce:   string,
 *     ajaxUrl: string,
 *     i18n: {
 *       confirmDelete, deleted, deleteFailed, networkError,
 *       selectFile, enterUrl, enterContent,
 *       titleRequired, nameRequired, nameInvalid,
 *       descRequired, instructionsRequired,
 *       copied, installed
 *     }
 *   }
 *
 * @since 1.9.0
 */
( function( cfg ) {
	'use strict';

	if ( ! cfg ) { return; }

	var nonce   = cfg.nonce;
	var ajaxUrl = cfg.ajaxUrl;
	var i18n    = cfg.i18n || {};

	/* ──────────────────────────────────────────────
	   Helpers
	   ────────────────────────────────────────────── */
	function showNotice( el, message, isError ) {
		el.textContent = message;
		el.classList.remove( 'error' );
		if ( isError ) {
			el.classList.add( 'error' );
		}
		el.style.display = 'block';
	}

	function hideNotice( el ) {
		el.style.display = 'none';
		el.textContent   = '';
	}

	/* ──────────────────────────────────────────────
	   Skill list search filter
	   ────────────────────────────────────────────── */
	var searchInput = document.getElementById( 'skill-list-search' );
	if ( searchInput ) {
		searchInput.addEventListener( 'input', function() {
			var q    = this.value.toLowerCase().trim();
			var rows = document.querySelectorAll( '#skill-list-table tbody tr' );
			rows.forEach( function( row ) {
				var name = row.getAttribute( 'data-name' ) || '';
				var desc = row.getAttribute( 'data-description' ) || '';
				row.style.display = ( ! q || name.indexOf( q ) !== -1 || desc.indexOf( q ) !== -1 )
					? ''
					: 'none';
			} );
		} );
	}

	/* ──────────────────────────────────────────────
	   Delete skill buttons
	   ────────────────────────────────────────────── */
	document.querySelectorAll( '.skill-delete-btn' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var skillName = this.getAttribute( 'data-skill' );
			if ( ! skillName ) {
				return;
			}

			var notice = document.getElementById( 'skill-manager-notice' );
			if ( ! window.confirm( i18n.confirmDelete ) ) {
				return;
			}

			var row = this.closest( 'tr' );

			var fd = new FormData();
			fd.append( 'action', 'wp_mcp_ai_skill_manager_delete' );
			fd.append( 'nonce',  nonce );
			fd.append( 'skill',  skillName );

			fetch( ajaxUrl, { method: 'POST', body: fd } )
				.then( function( r ) { return r.json(); } )
				.then( function( data ) {
					if ( data.success ) {
						if ( row ) {
							row.remove();
						}
						if ( notice ) {
							showNotice( notice, data.data || i18n.deleted, false );
						}
					} else {
						if ( notice ) {
							showNotice( notice, data.data || i18n.deleteFailed, true );
						}
					}
				} )
				.catch( function() {
					if ( notice ) {
						showNotice( notice, i18n.networkError, true );
					}
				} );
		} );
	} );

	/* ──────────────────────────────────────────────
	   File upload (SKILL.md or ZIP)
	   ────────────────────────────────────────────── */
	var uploadBtn    = document.getElementById( 'skill-upload-btn' );
	var uploadNotice = document.getElementById( 'upload-notice' );
	if ( uploadBtn ) {
		uploadBtn.addEventListener( 'click', function() {
			var fileInput = document.getElementById( 'skill-upload-file' );
			if ( ! fileInput || ! fileInput.files.length ) {
				showNotice( uploadNotice, i18n.selectFile, true );
				return;
			}

			var fd = new FormData();
			fd.append( 'action', 'wp_mcp_ai_skill_manager_upload' );
			fd.append( 'nonce',  nonce );
			fd.append( 'skill_file', fileInput.files[0] );

			uploadBtn.disabled = true;
			hideNotice( uploadNotice );

			fetch( ajaxUrl, { method: 'POST', body: fd } )
				.then( function( r ) { return r.json(); } )
				.then( function( data ) {
					uploadBtn.disabled = false;
					showNotice( uploadNotice, data.data || '', ! data.success );
				} )
				.catch( function() {
					uploadBtn.disabled = false;
					showNotice( uploadNotice, i18n.networkError, true );
				} );
		} );
	}

	/* ──────────────────────────────────────────────
	   Install from URL
	   ────────────────────────────────────────────── */
	var urlBtn    = document.getElementById( 'skill-url-install-btn' );
	var urlNotice = document.getElementById( 'url-install-notice' );
	if ( urlBtn ) {
		urlBtn.addEventListener( 'click', function() {
			var urlInput = document.getElementById( 'skill-url-input' );
			if ( ! urlInput || ! urlInput.value.trim() ) {
				showNotice( urlNotice, i18n.enterUrl, true );
				return;
			}

			var fd = new FormData();
			fd.append( 'action', 'wp_mcp_ai_skill_manager_install_url' );
			fd.append( 'nonce',  nonce );
			fd.append( 'url',    urlInput.value.trim() );

			urlBtn.disabled = true;
			hideNotice( urlNotice );

			fetch( ajaxUrl, { method: 'POST', body: fd } )
				.then( function( r ) { return r.json(); } )
				.then( function( data ) {
					urlBtn.disabled = false;
					showNotice( urlNotice, data.data || '', ! data.success );
				} )
				.catch( function() {
					urlBtn.disabled = false;
					showNotice( urlNotice, i18n.networkError, true );
				} );
		} );
	}

	/* ──────────────────────────────────────────────
	   Skill Editor save
	   ────────────────────────────────────────────── */
	var saveBtn      = document.getElementById( 'skill-save-btn' );
	var editorNotice = document.getElementById( 'editor-notice' );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function() {
			var textarea = document.getElementById( 'skill-editor-textarea' );
			/* If CodeMirror is active, sync content back to textarea */
			if ( window.wp && window.wp.codeEditor && window._skillCodeMirror ) {
				textarea.value = window._skillCodeMirror.getValue();
			}

			var content = textarea ? textarea.value : '';
			if ( ! content.trim() ) {
				showNotice( editorNotice, i18n.enterContent, true );
				return;
			}

			var fd = new FormData();
			fd.append( 'action',  'wp_mcp_ai_skill_manager_save' );
			fd.append( 'nonce',   nonce );
			fd.append( 'content', content );

			saveBtn.disabled = true;
			hideNotice( editorNotice );

			fetch( ajaxUrl, { method: 'POST', body: fd } )
				.then( function( r ) { return r.json(); } )
				.then( function( data ) {
					saveBtn.disabled = false;
					showNotice( editorNotice, data.data || '', ! data.success );
				} )
				.catch( function() {
					saveBtn.disabled = false;
					showNotice( editorNotice, i18n.networkError, true );
				} );
		} );
	}

	/* ──────────────────────────────────────────────
	   Research & Build wizard
	   ────────────────────────────────────────────── */
	( function() {

		/* ── Helpers ── */
		function wizardEl( id ) { return document.getElementById( id ); }

		var researchNotice = wizardEl( 'research-install-notice' );

		function showResearchNotice( msg, isErr ) {
			if ( ! researchNotice ) { return; }
			researchNotice.textContent = msg;
			researchNotice.classList.toggle( 'error', !! isErr );
			researchNotice.style.display = 'block';
		}
		function hideResearchNotice() {
			if ( researchNotice ) { researchNotice.style.display = 'none'; }
		}

		/* ── Step navigation ── */
		var currentStep = 1;
		var TOTAL_STEPS = 4;

		function goToStep( n ) {
			if ( n < 1 || n > TOTAL_STEPS ) { return; }

			// Validate current step before advancing.
			if ( n > currentStep && ! validateStep( currentStep ) ) { return; }

			// Hide old panel.
			var oldPanel = wizardEl( 'research-panel-' + currentStep );
			if ( oldPanel ) { oldPanel.classList.remove( 'active' ); }

			// Update progress bar indicators.
			for ( var i = 1; i <= TOTAL_STEPS; i++ ) {
				var ind  = wizardEl( 'wizard-step-ind-' + i );
				var conn = wizardEl( 'wizard-conn-' + i );
				if ( ! ind ) { continue; }
				ind.classList.remove( 'active', 'completed' );
				if ( i < n ) {
					ind.classList.add( 'completed' );
				} else if ( i === n ) {
					ind.classList.add( 'active' );
				}
				if ( conn ) {
					conn.classList.toggle( 'completed', i < n );
				}
			}

			// Show new panel.
			currentStep = n;
			var newPanel = wizardEl( 'research-panel-' + n );
			if ( newPanel ) { newPanel.classList.add( 'active' ); }

			// Auto-populate step 2 from step 1 when first entering step 2.
			if ( 2 === n ) { prefillStep2(); }

			// Auto-build SKILL.md preview when entering step 4.
			if ( 4 === n ) { buildPreview(); }

			// Scroll wizard into view.
			var wizard = document.querySelector( '.research-wizard' );
			if ( wizard ) { wizard.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
		}

		/* ── Per-step validation ── */
		function validateStep( step ) {
			if ( 1 === step ) {
				var title = ( wizardEl( 'research-title' ) || {} ).value || '';
				if ( ! title.trim() ) {
					alert( i18n.titleRequired );
					return false;
				}
			}
			if ( 2 === step ) {
				var nameEl  = wizardEl( 'research-name' );
				var descEl  = wizardEl( 'research-description' );
				var nameVal = ( nameEl || {} ).value || '';
				var descVal = ( descEl || {} ).value || '';

				if ( ! nameVal.trim() ) {
					alert( i18n.nameRequired );
					if ( nameEl ) { nameEl.focus(); }
					return false;
				}
				if ( ! /^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/.test( nameVal ) ) {
					alert( i18n.nameInvalid );
					if ( nameEl ) { nameEl.focus(); }
					return false;
				}
				if ( ! descVal.trim() ) {
					alert( i18n.descRequired );
					if ( descEl ) { descEl.focus(); }
					return false;
				}
			}
			if ( 3 === step ) {
				var instEl = wizardEl( 'research-instructions' );
				if ( ! instEl || ! instEl.value.trim() ) {
					alert( i18n.instructionsRequired );
					if ( instEl ) { instEl.focus(); }
					return false;
				}
			}
			return true;
		}

		/* ── Slug auto-generator ── */
		function titleToSlug( title ) {
			return title
				.toLowerCase()
				.replace( /[^a-z0-9\s-]/g, '' )
				.trim()
				.replace( /\s+/g, '-' )
				.replace( /-{2,}/g, '-' )
				.slice( 0, 64 );
		}

		/* ── Pre-fill step 2 from step 1 (runs once per forward navigation) ── */
		var step2Prefilled = false;
		function prefillStep2() {
			if ( step2Prefilled ) { return; }
			step2Prefilled = true;

			var titleEl   = wizardEl( 'research-title' );
			var purposeEl = wizardEl( 'research-purpose' );
			var triggerEl = wizardEl( 'research-triggers' );
			var nameEl    = wizardEl( 'research-name' );
			var descEl    = wizardEl( 'research-description' );

			if ( titleEl && nameEl && ! nameEl.value ) {
				nameEl.value = titleToSlug( titleEl.value );
			}

			// Combine purpose + triggers into a description (max 1024 chars).
			if ( descEl && ! descEl.value ) {
				var purpose  = ( purposeEl  || {} ).value || '';
				var triggers = ( triggerEl  || {} ).value || '';
				var combined = purpose.trim();
				if ( triggers.trim() ) {
					combined += ( combined ? ' Use when: ' : 'Use when: ' ) + triggers.trim();
				}
				descEl.value = combined.slice( 0, 1024 );
				updateCounter( 'desc-counter', descEl.value.length, 1024 );
			}
		}

		/* ── Char counters ── */
		function updateCounter( counterId, len, max ) {
			var el = wizardEl( counterId );
			if ( ! el ) { return; }
			el.textContent = len + ' / ' + max;
			el.classList.toggle( 'warn', len > max * 0.9 );
		}

		var descEl   = wizardEl( 'research-description' );
		var compatEl = wizardEl( 'research-compatibility' );
		if ( descEl ) {
			descEl.addEventListener( 'input', function() {
				updateCounter( 'desc-counter', this.value.length, 1024 );
			} );
		}
		if ( compatEl ) {
			compatEl.addEventListener( 'input', function() {
				updateCounter( 'compat-counter', this.value.length, 500 );
			} );
		}

		/* ── License picker ── */
		var licenseGrid = wizardEl( 'research-license-grid' );
		if ( licenseGrid ) {
			licenseGrid.addEventListener( 'click', function( e ) {
				var opt = e.target.closest( '.license-option' );
				if ( ! opt ) { return; }
				licenseGrid.querySelectorAll( '.license-option' ).forEach( function( o ) {
					o.classList.remove( 'selected' );
				} );
				opt.classList.add( 'selected' );
				var radio = opt.querySelector( 'input[type=radio]' );
				if ( radio ) { radio.checked = true; }
				var hidden = wizardEl( 'research-license' );
				if ( hidden ) { hidden.value = opt.getAttribute( 'data-value' ) || ''; }
			} );
		}

		/* ── Bundle type toggle ── */
		var bundleZipNote = wizardEl( 'research-bundle-zip-note' );
		document.querySelectorAll( 'input[name=research_bundle_type]' ).forEach( function( r ) {
			r.addEventListener( 'change', function() {
				if ( bundleZipNote ) {
					bundleZipNote.style.display = ( 'zip' === this.value ) ? 'block' : 'none';
				}
			} );
		} );

		/* ── Starter template generator ── */
		var genTemplateBtn = wizardEl( 'research-gen-template' );
		if ( genTemplateBtn ) {
			genTemplateBtn.addEventListener( 'click', function() {
				var instEl   = wizardEl( 'research-instructions' );
				if ( ! instEl ) { return; }

				var title    = ( wizardEl( 'research-title' )    || {} ).value || 'My Skill';
				var purpose  = ( wizardEl( 'research-purpose' )  || {} ).value || '';
				var triggers = ( wizardEl( 'research-triggers' ) || {} ).value || '';
				var nameSlug = ( wizardEl( 'research-name' )     || {} ).value || titleToSlug( title ); // eslint-disable-line no-unused-vars

				var tpl = '# ' + title + '\n\n';
				if ( purpose ) {
					tpl += '## Overview\n\n' + purpose.trim() + '\n\n';
				}
				if ( triggers ) {
					tpl += '## When to Invoke This Skill\n\n' + triggers.trim() + '\n\n';
				}
				tpl += '## Steps\n\n';
				tpl += '1. [Step one \u2014 describe what to do first]\n';
				tpl += '2. [Step two \u2014 describe validation or transformation]\n';
				tpl += '3. [Step three \u2014 describe output or follow-up action]\n\n';
				tpl += '## Branching Logic\n\n';
				tpl += '- If [condition A], then [action A].\n';
				tpl += '- If [condition B], then [action B]; otherwise [fallback].\n\n';
				tpl += '## Examples\n\n';
				tpl += '**Input:** [example input]\n\n';
				tpl += '**Expected Output:** [example output]\n\n';
				tpl += '## Notes\n\n';
				tpl += '- Reference any supporting files in `scripts/`, `references/`, or `assets/` sub-directories if packaging as a ZIP bundle.\n';

				instEl.value = tpl;
			} );
		}

		/* ── SKILL.md assembler (client-side) ── */
		function buildSkillMd() {
			var name          = ( wizardEl( 'research-name' )          || {} ).value || '';
			var version       = ( wizardEl( 'research-version' )       || {} ).value || '';
			var description   = ( wizardEl( 'research-description' )   || {} ).value || '';
			var license       = ( wizardEl( 'research-license' )       || {} ).value || 'MIT';
			var compatibility = ( wizardEl( 'research-compatibility' ) || {} ).value || '';
			var author        = ( wizardEl( 'research-author' )        || {} ).value || '';
			var homepage      = ( wizardEl( 'research-homepage' )      || {} ).value || '';
			var allowedTools  = ( wizardEl( 'research-allowed-tools' ) || {} ).value || '';
			var domain        = ( wizardEl( 'research-domain' )        || {} ).value || '';
			var instructions  = ( wizardEl( 'research-instructions' )  || {} ).value || '';

			var yaml = '---\n';
			yaml += 'name: ' + name + '\n';
			yaml += 'description: "' + description.replace( /"/g, '\\"' ) + '"\n';
			if ( license ) { yaml += 'license: ' + license + '\n'; }
			if ( compatibility ) { yaml += 'compatibility: "' + compatibility.replace( /"/g, '\\"' ) + '"\n'; }
			if ( allowedTools ) { yaml += 'allowed-tools: ' + allowedTools + '\n'; }

			// Metadata block.
			var metaLines = [];
			if ( version )  { metaLines.push( '  version: "' + version + '"' ); }
			if ( author )   { metaLines.push( '  author: "' + author.replace( /"/g, '\\"' ) + '"' ); }
			if ( homepage ) { metaLines.push( '  homepage: "' + homepage + '"' ); }
			if ( domain )   { metaLines.push( '  category: "' + domain.replace( /"/g, '\\"' ) + '"' ); }
			if ( metaLines.length ) {
				yaml += 'metadata:\n' + metaLines.join( '\n' ) + '\n';
			}

			yaml += '---\n\n';

			var body = instructions.trim() || '# ' + name + '\n\nDescribe the skill instructions here.';
			return yaml + body;
		}

		/* ── OpenAI tool schema generator ── */
		function buildToolSchema() {
			var name        = ( wizardEl( 'research-name' )        || {} ).value || '';
			var description = ( wizardEl( 'research-description' ) || {} ).value || '';

			var schema = {
				type: 'function',
				function: {
					name: name || 'my_skill',
					description: description || 'No description provided.',
					parameters: {
						type: 'object',
						properties: {
							input: {
								type: 'string',
								description: 'The primary input or query for the skill.',
							},
						},
						required: [ 'input' ],
						additionalProperties: false,
					},
					strict: true,
				},
			};

			try {
				return JSON.stringify( schema, null, 2 );
			} catch ( err ) {
				return '{}';
			}
		}

		/* ── Directory structure preview ── */
		function buildDirPreview() {
			var name       = ( wizardEl( 'research-name' )         || {} ).value || 'my-skill';
			var bundleType = document.querySelector( 'input[name=research_bundle_type]:checked' );
			var isZip      = bundleType && 'zip' === bundleType.value;

			var lines = [ name + '/', '\u251c\u2500\u2500 SKILL.md     \u2190 installed by this wizard' ];
			if ( isZip ) {
				lines.push( '\u251c\u2500\u2500 scripts/     \u2190 executable scripts (optional)' );
				lines.push( '\u251c\u2500\u2500 references/  \u2190 reference documents (optional)' );
				lines.push( '\u2514\u2500\u2500 assets/      \u2190 templates & resources (optional)' );
			}
			return lines.join( '\n' );
		}

		/* ── Build preview in step 4 ── */
		function buildPreview() {
			var previewEl = wizardEl( 'research-preview' );
			if ( previewEl ) { previewEl.textContent = buildSkillMd(); }

			var schemaEl = wizardEl( 'research-schema-preview' );
			if ( schemaEl ) { schemaEl.textContent = buildToolSchema(); }

			var dirEl = wizardEl( 'research-dir-preview' );
			if ( dirEl ) { dirEl.textContent = buildDirPreview(); }
		}

		/* ── Copy to clipboard ── */
		function copyText( text, btn ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function() {
					var orig = btn.textContent;
					btn.textContent = i18n.copied;
					setTimeout( function() { btn.textContent = orig; }, 2000 );
				} );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity  = '0';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
			}
		}

		var copyBtn = wizardEl( 'research-copy-btn' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function() {
				copyText( buildSkillMd(), this );
			} );
		}

		var copySchemaBtn = wizardEl( 'research-copy-schema-btn' );
		if ( copySchemaBtn ) {
			copySchemaBtn.addEventListener( 'click', function() {
				copyText( buildToolSchema(), this );
			} );
		}

		/* ── Install via existing save AJAX ── */
		var installBtn = wizardEl( 'research-install-btn' );
		if ( installBtn ) {
			installBtn.addEventListener( 'click', function() {
				hideResearchNotice();
				var content = buildSkillMd();

				var fd = new FormData();
				fd.append( 'action',  'wp_mcp_ai_skill_manager_save' );
				fd.append( 'nonce',   nonce );
				fd.append( 'content', content );

				installBtn.disabled = true;

				fetch( ajaxUrl, { method: 'POST', body: fd } )
					.then( function( r ) { return r.json(); } )
					.then( function( data ) {
						installBtn.disabled = false;
						showResearchNotice( data.data || '', ! data.success );
						if ( data.success ) {
							installBtn.textContent = i18n.installed;
						}
					} )
					.catch( function() {
						installBtn.disabled = false;
						showResearchNotice( i18n.networkError, true );
					} );
			} );
		}

		/* ── "Open in Skill Editor" ── */
		var openEditorBtn = wizardEl( 'research-open-editor-btn' );
		if ( openEditorBtn ) {
			openEditorBtn.addEventListener( 'click', function() {
				var content = buildSkillMd();

				// Push content to the editor textarea.
				var editorTa = document.getElementById( 'skill-editor-textarea' );
				if ( editorTa ) { editorTa.value = content; }
				if ( window._skillCodeMirror ) {
					window._skillCodeMirror.setValue( content );
				}

				// Switch to editor tab.
				var editorTab = document.getElementById( 'tab-editor' );
				var allTabs   = document.querySelectorAll( '.tab-content' );
				allTabs.forEach( function( t ) { t.classList.remove( 'active' ); } );
				if ( editorTab ) { editorTab.classList.add( 'active' ); }

				// Scroll to editor.
				if ( editorTa ) { editorTa.scrollIntoView( { behavior: 'smooth' } ); }
			} );
		}

		/* ── Wire nav buttons ── */
		var n1 = wizardEl( 'research-next-1' );
		var n2 = wizardEl( 'research-next-2' );
		var n3 = wizardEl( 'research-next-3' );
		var b2 = wizardEl( 'research-back-2' );
		var b3 = wizardEl( 'research-back-3' );
		var b4 = wizardEl( 'research-back-4' );

		if ( n1 ) { n1.addEventListener( 'click', function() { goToStep( 2 ); } ); }
		if ( n2 ) { n2.addEventListener( 'click', function() { goToStep( 3 ); } ); }
		if ( n3 ) { n3.addEventListener( 'click', function() { goToStep( 4 ); } ); }
		if ( b2 ) { b2.addEventListener( 'click', function() { goToStep( 1 ); } ); }
		if ( b3 ) { b3.addEventListener( 'click', function() { goToStep( 2 ); } ); }
		if ( b4 ) { b4.addEventListener( 'click', function() { goToStep( 3 ); } ); }

	} )();

	/* ──────────────────────────────────────────────
	   CodeMirror initialisation
	   ────────────────────────────────────────────── */
	if ( window.wp && window.wp.codeEditor ) {
		var ta = document.getElementById( 'skill-editor-textarea' );
		if ( ta ) {
			var editorSettings = window.wpCodeEditorL10n ? window.wpCodeEditorL10n.codemirror : {};
			var instance = wp.codeEditor.initialize( ta, editorSettings );
			if ( instance && instance.codemirror ) {
				window._skillCodeMirror = instance.codemirror;
			}
		}
	}
} )( window.wpMcpAiSkillManager );
