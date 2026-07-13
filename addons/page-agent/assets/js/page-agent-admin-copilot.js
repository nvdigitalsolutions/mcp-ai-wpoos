/**
 * NV oOS Page Agent — Admin Copilot
 *
 * Admin dashboard copilot script (Pro feature, Phase 2).
 * Adds a floating Page Agent panel to the WordPress admin.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.2.0
 */

( function ( config ) {
	'use strict';

	if ( ! config || ! config.restUrl ) {
		return;
	}

	var agent = null;
	var panel = null;

	/**
	 * Initialize the admin copilot.
	 */
	function init() {
		if ( ! window.wpMcpAiPageAgentInstance ) {
			console.warn( '[NV oOS Page Agent Admin] Page Agent bridge not loaded.' );
			return;
		}

		agent = window.wpMcpAiPageAgentInstance;

		// Create the floating panel UI.
		createPanel();

		// Hook the admin bar button.
		var copilotBtn = document.querySelector( '#wp-admin-bar-nvoos-page-agent-copilot' );
		if ( copilotBtn ) {
			copilotBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				togglePanel();
			} );
		}
	}

	/**
	 * Create the floating copilot panel.
	 */
	function createPanel() {
		panel = document.createElement( 'div' );
		panel.id = 'nvoos-page-agent-admin-panel';
		panel.className = 'nvoos-page-agent-admin-panel';
		panel.style.display = 'none';
		panel.innerHTML = '<div class="nvoos-page-agent-admin-panel-header">'
			+ '<span>' + ( config.pageTitle || 'Page Agent' ) + '</span>'
			+ '<button type="button" class="nvoos-page-agent-admin-panel-close">&times;</button>'
			+ '</div>'
			+ '<div class="nvoos-page-agent-admin-panel-content"></div>';

		document.body.appendChild( panel );

		panel.querySelector( '.nvoos-page-agent-admin-panel-close' )
			.addEventListener( 'click', function () {
				panel.style.display = 'none';
			} );
	}

	/**
	 * Toggle the panel visibility.
	 */
	function togglePanel() {
		if ( ! panel ) {
			return;
		}
		panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
	}

	// Initialize when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )( window.wpMcpAiPageAgentAdmin || {} );
