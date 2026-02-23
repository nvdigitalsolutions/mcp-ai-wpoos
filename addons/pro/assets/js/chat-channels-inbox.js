/**
 * Chat Channels Inbox JavaScript
 *
 * Powers the unified multi-channel inbox admin page, contacts table, and
 * shared interaction logic (send reply, tag contact, human takeover toggle,
 * resolve conversation, pagination).
 *
 * Reads configuration from `window.wpMcpAiChatChannels` which is set via
 * wp_localize_script() in WP_MCP_AI_Chat_Channels_Menu::enqueue_assets().
 *
 * @package WP_MCP_AI_Pro
 */
/* global wpMcpAiChatChannels, jQuery */

(function( $, cfg ) {
	'use strict';

	if ( ! cfg ) { return; }

	var REST    = cfg.restUrl;
	var NONCE   = cfg.nonce;
	var LABELS  = cfg.channelLabels || {};
	var I18N    = cfg.i18n || {};
	var PAGE    = cfg.currentPage || '';

	// Shared fetch helper.
	function apiFetch( path, method, body ) {
		var opts = {
			method  : method || 'GET',
			headers : {
				'Content-Type' : 'application/json',
				'X-WP-Nonce'   : NONCE,
			},
		};
		if ( body ) { opts.body = JSON.stringify( body ); }
		return fetch( REST + path, opts ).then( function( r ) { return r.json(); } );
	}

	// Format Unix timestamp as a short locale string.
	function fmtTime( ts ) {
		if ( ! ts ) { return ''; }
		var d = new Date( ts * 1000 );
		return d.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
	}

	// Build a channel badge element.
	function channelBadge( slug ) {
		var label = LABELS[ slug ] || slug;
		return '<span class="cc-badge cc-badge--' + slug + '">' + escHtml( label ) + '</span>';
	}

	// Build a CRM status dot.
	function statusDot( status ) {
		return '<span class="cc-status-dot cc-status-dot--' + status + '" title="' + escHtml( status ) + '"></span>';
	}

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	// =========================================================================
	// Inbox page
	// =========================================================================
	if ( PAGE === 'inbox' ) {
		var state = {
			page          : 1,
			perPage       : 25,
			channel       : ( document.getElementById( 'cc-active-channel' ) || {} ).value || '',
			status        : '',
			search        : '',
			activeContactId : null,
			activeContact   : null,
			msgPage       : 1,
			msgPerPage    : 50,
		};

		// Load initial conversations.
		loadConversations();

		// Toolbar events.
		$( '#cc-filter-status' ).on( 'change', function() {
			state.status = this.value;
			state.page   = 1;
			loadConversations();
		} );

		var searchTimer;
		$( '#cc-search' ).on( 'input', function() {
			clearTimeout( searchTimer );
			var val = this.value;
			searchTimer = setTimeout( function() {
				state.search = val;
				state.page   = 1;
				loadConversations();
			}, 350 );
		} );

		$( '#cc-refresh' ).on( 'click', function() { loadConversations(); } );

		// Send reply.
		$( '#cc-send-reply' ).on( 'click', sendReply );
		$( '#cc-reply-text' ).on( 'keydown', function( e ) {
			if ( e.ctrlKey && e.key === 'Enter' ) { sendReply(); }
		} );

		// Human takeover.
		$( '#cc-human-takeover-btn' ).on( 'click', toggleHumanTakeover );

		// Resolve.
		$( '#cc-resolve-btn' ).on( 'click', function() {
			if ( ! state.activeContactId ) { return; }
			if ( ! window.confirm( I18N.confirmResolve || 'Resolve this conversation?' ) ) { return; }
			apiFetch( '/contacts/' + state.activeContactId + '/status', 'POST', { status: 'resolved' } )
				.then( function() { loadConversations(); } );
		} );

		function loadConversations() {
			var $list = $( '#cc-conversations-list' );
			$list.html( '<div class="cc-placeholder">' + escHtml( I18N.loading || 'Loading…' ) + '</div>' );

			var qs = '?page=' + state.page + '&per_page=' + state.perPage;
			if ( state.channel ) { qs += '&channel=' + encodeURIComponent( state.channel ); }
			if ( state.status )  { qs += '&status=' + encodeURIComponent( state.status ); }
			if ( state.search )  { qs += '&search=' + encodeURIComponent( state.search ); }

			apiFetch( '/conversations' + qs ).then( function( data ) {
				if ( ! data || ! data.items || ! data.items.length ) {
					$list.html( '<div class="cc-placeholder">' + escHtml( I18N.noConversations || 'No conversations found.' ) + '</div>' );
					renderPagination( 0, state.page, state.perPage );
					return;
				}
				renderConversations( data.items );
				renderPagination( data.total, state.page, state.perPage );
			} ).catch( function() {
				$list.html( '<div class="cc-placeholder">Error loading conversations.</div>' );
			} );
		}

		function renderConversations( items ) {
			var html = '';
			items.forEach( function( c ) {
				var takenOver = c.human_takeover ? '<span class="cc-takeover-indicator">👤 Human</span>' : '';
				html += '<div class="cc-conversation-item' + ( state.activeContactId === c.id ? ' cc-conversation-item--active' : '' ) + '"'
					+ ' data-id="' + c.id + '" data-contact=\'' + JSON.stringify( c ).replace( /'/g, '&#39;' ) + '\'>'
					+ '<div class="cc-conv-header">'
					+ '<span class="cc-conv-name">' + escHtml( c.display_name || c.channel_contact_id ) + '</span>'
					+ '<span class="cc-conv-time">' + fmtTime( c.last_message_at ) + '</span>'
					+ '</div>'
					+ '<div class="cc-conv-meta">'
					+ channelBadge( c.channel )
					+ statusDot( c.crm_status )
					+ takenOver
					+ '</div>'
					+ '</div>';
			} );
			$( '#cc-conversations-list' ).html( html );

			$( '.cc-conversation-item' ).on( 'click', function() {
				var contactData = JSON.parse( $( this ).attr( 'data-contact' ).replace( /&#39;/g, "'" ) );
				openConversation( contactData );
			} );
		}

		function openConversation( contact ) {
			state.activeContactId = contact.id;
			state.activeContact   = contact;
			state.msgPage         = 1;

			// Highlight selected.
			$( '.cc-conversation-item' ).removeClass( 'cc-conversation-item--active' );
			$( '.cc-conversation-item[data-id="' + contact.id + '"]' ).addClass( 'cc-conversation-item--active' );

			$( '.cc-thread-placeholder' ).hide();
			$( '#cc-thread-content' ).show();

			renderThreadHeader( contact );
			loadMessages();
		}

		function renderThreadHeader( contact ) {
			var takenOverClass = contact.human_takeover ? 'button-primary' : '';
			var takenOverText  = contact.human_takeover ? ( I18N.resumeAI || 'Resume AI' ) : ( I18N.humanTakeover || 'Human Takeover' );

			$( '#cc-thread-header' ).html(
				'<span class="cc-thread-contact-name">' + escHtml( contact.display_name || contact.channel_contact_id ) + '</span>'
				+ channelBadge( contact.channel )
				+ statusDot( contact.crm_status )
				+ '<div class="cc-thread-actions">'
				+ '<button id="cc-human-takeover-btn" class="button ' + takenOverClass + '">' + escHtml( takenOverText ) + '</button>'
				+ '<button id="cc-resolve-btn" class="button">' + escHtml( I18N.resolve || 'Resolve' ) + '</button>'
				+ '</div>'
			);

			// Re-bind buttons (they were replaced by innerHTML).
			$( '#cc-human-takeover-btn' ).off( 'click' ).on( 'click', toggleHumanTakeover );
			$( '#cc-resolve-btn' ).off( 'click' ).on( 'click', function() {
				if ( ! state.activeContactId ) { return; }
				if ( ! window.confirm( I18N.confirmResolve || 'Resolve?' ) ) { return; }
				apiFetch( '/contacts/' + state.activeContactId + '/status', 'POST', { status: 'resolved' } )
					.then( function() { loadConversations(); } );
			} );
		}

		function loadMessages() {
			var $msgs = $( '#cc-messages' );
			$msgs.html( '<div class="cc-placeholder">' + escHtml( I18N.loading || 'Loading…' ) + '</div>' );

			apiFetch( '/conversations/' + state.activeContactId + '/messages?page=' + state.msgPage + '&per_page=' + state.msgPerPage )
				.then( function( data ) {
					if ( ! data || ! data.items || ! data.items.length ) {
						$msgs.html( '<div class="cc-placeholder">No messages yet.</div>' );
						return;
					}
					renderMessages( data.items );
				} );
		}

		function renderMessages( items ) {
			var html = '';
			items.forEach( function( msg ) {
				var cls      = 'inbound' === msg.direction ? 'cc-message--inbound' : 'cc-message--outbound';
				var content  = msg.content || ( '[' + msg.message_type + ']' );
				html += '<div class="cc-message ' + cls + '">'
					+ escHtml( content )
					+ '<span class="cc-message-time">' + fmtTime( msg.timestamp ) + '</span>'
					+ '</div>';
			} );
			var $msgs = $( '#cc-messages' );
			$msgs.html( html );
			$msgs.scrollTop( $msgs.prop( 'scrollHeight' ) );
		}

		function sendReply() {
			if ( ! state.activeContactId ) { return; }
			var text = $( '#cc-reply-text' ).val().trim();
			if ( ! text ) { return; }

			var $btn = $( '#cc-send-reply' ).prop( 'disabled', true );
			apiFetch( '/reply', 'POST', { contact_id: state.activeContactId, message: text } )
				.then( function( data ) {
					if ( data && data.success ) {
						$( '#cc-reply-text' ).val( '' );
						loadMessages();
					} else {
						window.alert( ( data && data.message ) || I18N.errorSending || 'Error sending reply.' );
					}
				} )
				.catch( function() {
					window.alert( I18N.errorSending || 'Error sending reply.' );
				} )
				.finally( function() {
					$btn.prop( 'disabled', false );
				} );
		}

		function toggleHumanTakeover() {
			if ( ! state.activeContact ) { return; }
			var current = state.activeContact.human_takeover;
			apiFetch( '/contacts/' + state.activeContactId + '/takeover', 'POST', { enable: ! current } )
				.then( function( data ) {
					if ( data && data.success ) {
						state.activeContact.human_takeover = ! current;
						renderThreadHeader( state.activeContact );
					}
				} );
		}

		function renderPagination( total, page, perPage ) {
			var $pag     = $( '#cc-pagination' );
			var totalPgs = Math.ceil( total / perPage ) || 1;
			var prev     = page > 1 ? '<button class="cc-page-btn" id="cc-prev">&#8249; Prev</button>' : '<button class="cc-page-btn" disabled>&#8249; Prev</button>';
			var next     = page < totalPgs ? '<button class="cc-page-btn" id="cc-next">Next &#8250;</button>' : '<button class="cc-page-btn" disabled>Next &#8250;</button>';
			var info     = '<span class="cc-page-info">Page ' + page + ' / ' + totalPgs + '</span>';
			$pag.html( prev + info + next );

			$( '#cc-prev' ).on( 'click', function() { state.page--; loadConversations(); } );
			$( '#cc-next' ).on( 'click', function() { state.page++; loadConversations(); } );
		}
	}

	// =========================================================================
	// Contacts page
	// =========================================================================
	if ( PAGE === 'contacts' ) {
		var contactState = {
			page    : 1,
			perPage : 25,
			channel : '',
			status  : '',
			search  : '',
			tag     : '',
			editId  : null,
		};

		loadContacts();

		$( '#cc-contacts-filter-channel' ).on( 'change', function() {
			contactState.channel = this.value;
			contactState.page    = 1;
			loadContacts();
		} );
		$( '#cc-contacts-filter-status' ).on( 'change', function() {
			contactState.status = this.value;
			contactState.page   = 1;
			loadContacts();
		} );

		var cSearchTimer;
		$( '#cc-contacts-search' ).on( 'input', function() {
			clearTimeout( cSearchTimer );
			var val = this.value;
			cSearchTimer = setTimeout( function() {
				contactState.search = val;
				contactState.page   = 1;
				loadContacts();
			}, 350 );
		} );

		var cTagTimer;
		$( '#cc-contacts-filter-tag' ).on( 'input', function() {
			clearTimeout( cTagTimer );
			var val = this.value;
			cTagTimer = setTimeout( function() {
				contactState.tag  = val;
				contactState.page = 1;
				loadContacts();
			}, 350 );
		} );

		$( '#cc-contacts-refresh' ).on( 'click', function() { loadContacts(); } );

		// Tag modal.
		$( '#cc-tag-cancel' ).on( 'click', function() { $( '#cc-tag-modal' ).hide(); } );
		$( '#cc-tag-save' ).on( 'click', function() {
			var tag = $( '#cc-tag-input' ).val().trim();
			if ( ! tag || ! contactState.editId ) { return; }
			apiFetch( '/contacts/' + contactState.editId + '/tag', 'POST', { tag: tag } )
				.then( function() {
					$( '#cc-tag-modal' ).hide();
					$( '#cc-tag-input' ).val( '' );
					loadContacts();
				} );
		} );

		function loadContacts() {
			var $tbody = $( '#cc-contacts-tbody' );
			$tbody.html( '<tr><td colspan="7">' + escHtml( I18N.loading || 'Loading…' ) + '</td></tr>' );

			var qs = '?page=' + contactState.page + '&per_page=' + contactState.perPage;
			if ( contactState.channel ) { qs += '&channel=' + encodeURIComponent( contactState.channel ); }
			if ( contactState.status )  { qs += '&crm_status=' + encodeURIComponent( contactState.status ); }
			if ( contactState.search )  { qs += '&search=' + encodeURIComponent( contactState.search ); }
			if ( contactState.tag )     { qs += '&tag=' + encodeURIComponent( contactState.tag ); }

			apiFetch( '/contacts' + qs ).then( function( data ) {
				if ( ! data || ! data.items || ! data.items.length ) {
					$tbody.html( '<tr><td colspan="7">No contacts found.</td></tr>' );
					renderContactPagination( 0, contactState.page, contactState.perPage );
					return;
				}
				renderContactsTable( data.items );
				renderContactPagination( data.total, contactState.page, contactState.perPage );
			} );
		}

		function renderContactsTable( items ) {
			var html = '';
			items.forEach( function( c ) {
				var tags = ( c.tags || [] ).map( function( t ) {
					return '<span class="cc-tag-pill">' + escHtml( t ) + '</span>';
				} ).join( '' );

				var statusOptions = [ 'new', 'active', 'resolved', 'blocked' ].map( function( s ) {
					return '<option value="' + s + '"' + ( c.crm_status === s ? ' selected' : '' ) + '>' + s + '</option>';
				} ).join( '' );

				html += '<tr>'
					+ '<td>' + escHtml( c.display_name || c.channel_contact_id ) + '</td>'
					+ '<td>' + channelBadge( c.channel ) + '</td>'
					+ '<td><code>' + escHtml( c.channel_contact_id ) + '</code></td>'
					+ '<td>' + ( tags || '<em>—</em>' ) + '</td>'
					+ '<td><select class="cc-status-select" data-id="' + c.id + '">' + statusOptions + '</select></td>'
					+ '<td>' + fmtTime( c.last_message_at ) + '</td>'
					+ '<td>'
					+ '<button class="button button-small cc-add-tag-btn" data-id="' + c.id + '">+ Tag</button> '
					+ '<button class="button button-small cc-takeover-btn" data-id="' + c.id + '" data-active="' + ( c.human_takeover ? '1' : '0' ) + '">'
					+   ( c.human_takeover ? escHtml( I18N.resumeAI || 'Resume AI' ) : escHtml( I18N.humanTakeover || 'Human Takeover' ) )
					+ '</button>'
					+ '</td>'
					+ '</tr>';
			} );
			var $tbody = $( '#cc-contacts-tbody' );
			$tbody.html( html );

			$tbody.find( '.cc-status-select' ).on( 'change', function() {
				var id     = $( this ).data( 'id' );
				var status = this.value;
				apiFetch( '/contacts/' + id + '/status', 'POST', { status: status } );
			} );

			$tbody.find( '.cc-add-tag-btn' ).on( 'click', function() {
				contactState.editId = $( this ).data( 'id' );
				$( '#cc-tag-input' ).val( '' );
				$( '#cc-tag-modal' ).show();
			} );

			$tbody.find( '.cc-takeover-btn' ).on( 'click', function() {
				var id     = $( this ).data( 'id' );
				var active = $( this ).data( 'active' ) === '1' || $( this ).data( 'active' ) === 1;
				apiFetch( '/contacts/' + id + '/takeover', 'POST', { enable: ! active } )
					.then( function() { loadContacts(); } );
			} );
		}

		function renderContactPagination( total, page, perPage ) {
			var $pag     = $( '#cc-contacts-pagination' );
			var totalPgs = Math.ceil( total / perPage ) || 1;
			var prev     = page > 1 ? '<button class="cc-page-btn" id="cc-c-prev">&#8249; Prev</button>' : '<button class="cc-page-btn" disabled>&#8249; Prev</button>';
			var next     = page < totalPgs ? '<button class="cc-page-btn" id="cc-c-next">Next &#8250;</button>' : '<button class="cc-page-btn" disabled>Next &#8250;</button>';
			var info     = '<span class="cc-page-info">Page ' + page + ' / ' + totalPgs + '</span>';
			$pag.html( prev + info + next );
			$( '#cc-c-prev' ).on( 'click', function() { contactState.page--; loadContacts(); } );
			$( '#cc-c-next' ).on( 'click', function() { contactState.page++; loadContacts(); } );
		}
	}

})( jQuery, window.wpMcpAiChatChannels );
