/* global wpMcpAiScheduleManager */
/**
 * Pro Schedule Manager — Admin UI
 *
 * Handles the full CRUD interface for pro-managed scheduled tasks,
 * workflows, and AI assistant runs in the NV oOS settings dashboard.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

( function ( $ ) {
	'use strict';

	const SM = {
		/** Current schedule data keyed by id */
		schedules: {},

		/** ID currently shown in history/edit modal */
		activeId: null,

		/** ------------------------------------------------------------------ *
		 *  Bootstrap
		 * ------------------------------------------------------------------ */
		init: function () {
			if ( ! $( '#wp-mcp-ai-schedule-manager' ).length ) {
				return;
			}

			this.populateIntervalSelect();
			this.bindGlobalEvents();
			this.loadSchedules();
			this.initPresetBrowser();
		},

		/** Populate the "Interval" select with options from PHP */
		populateIntervalSelect: function () {
			const $sel = $( '#sm-schedule' );
			$sel.empty();
			$.each( wpMcpAiScheduleManager.scheduleOptions, function ( key, label ) {
				$sel.append( $( '<option>' ).val( key ).text( label ) );
			} );
		},

		/** ------------------------------------------------------------------ *
		 *  Event bindings
		 * ------------------------------------------------------------------ */
		bindGlobalEvents: function () {
			const self = this;

			// Toggle create form.
			$( document ).on( 'click keypress', '.wp-mcp-ai-sm-create-toggle', function ( e ) {
				if ( 'click' === e.type || 13 === e.which ) {
					const $form = $( '#wp-mcp-ai-sm-create-form' );
					const expanded = 'true' === $( this ).attr( 'aria-expanded' );
					$form.slideToggle( 200 );
					$( this ).attr( 'aria-expanded', ! expanded );
				}
			} );

			// Schedule type switching.
			$( document ).on( 'change', '#sm-type', function () {
				self.switchTypePanel( $( this ).val() );
			} );

			// Notify on failure toggle — show email field and channel notification rows.
			$( document ).on( 'change', '#sm-notify-on-failure', function () {
				const checked = $( this ).is( ':checked' );
				$( '#sm-notify-email-wrap' ).toggle( checked );
				$( '#sm-notify-channels-row' ).toggle( checked );
			} );

			// Add workflow step.
			$( document ).on( 'click', '#sm-add-step', function () {
				self.addWorkflowStep();
			} );

			// Remove workflow step (delegated).
			$( document ).on( 'click', '.wp-mcp-ai-sm-remove-step', function () {
				$( this ).closest( '.wp-mcp-ai-sm-step-row' ).remove();
				self.renumberSteps();
			} );

			// Create button.
			$( document ).on( 'click', '#wp-mcp-ai-sm-create-btn', function () {
				self.createSchedule();
			} );

			// Refresh list.
			$( document ).on( 'click', '#wp-mcp-ai-sm-refresh', function () {
				self.loadSchedules();
			} );

			// Filter changes.
			$( document ).on( 'change', '.wp-mcp-ai-sm-filter', function () {
				self.applyFilters();
			} );

			// Table row actions (delegated).
			$( document ).on( 'click', '[data-sm-action]', function ( e ) {
				e.preventDefault();
				const action = $( this ).data( 'sm-action' );
				const id     = $( this ).data( 'sm-id' );
				self.handleAction( action, id, $( this ) );
			} );

			// Modal close.
			$( document ).on( 'click', '.wp-mcp-ai-sm-modal-close, .wp-mcp-ai-sm-modal-backdrop, #wp-mcp-ai-sm-history-modal-close-btn, #wp-mcp-ai-sm-edit-cancel-btn', function () {
				self.closeModals();
			} );

			// ESC key.
			$( document ).on( 'keydown', function ( e ) {
				if ( 27 === e.which ) {
					self.closeModals();
				}
			} );

			// Save edit.
			$( document ).on( 'click', '#wp-mcp-ai-sm-edit-save-btn', function () {
				self.saveEdit();
			} );

			// Clear history.
			$( document ).on( 'click', '#wp-mcp-ai-sm-clear-history-btn', function () {
				self.clearHistory( self.activeId );
			} );

			// Export run history as CSV.
			$( document ).on( 'click', '#wp-mcp-ai-sm-export-csv-btn', function () {
				self.exportHistoryCsv( self.activeId );
			} );

			// Export all schedules as iCal.
			$( document ).on( 'click', '#wp-mcp-ai-sm-export-ical', function ( e ) {
				e.preventDefault();
				self.exportIcal();
			} );

			// Toggle preset browser panel.
			$( document ).on( 'click keypress', '.wp-mcp-ai-sm-presets-toggle', function ( e ) {
				if ( 'click' === e.type || 13 === e.which ) {
					const $panel = $( '#wp-mcp-ai-sm-presets-panel' );
					const expanded = 'true' === $( this ).attr( 'aria-expanded' );
					$panel.slideToggle( 200 );
					$( this ).attr( 'aria-expanded', ! expanded );
					if ( ! expanded && ! self.presetsLoaded ) {
						self.loadPresets();
					}
				}
			} );

			// Preset filters.
			$( document ).on( 'change', '#wp-mcp-ai-sm-preset-category, #wp-mcp-ai-sm-preset-toolkit', function () {
				self.filterPresets();
			} );

			// Preset search.
			$( document ).on( 'input', '#wp-mcp-ai-sm-preset-search', function () {
				self.filterPresets();
			} );

			// Preset install (delegated).
			$( document ).on( 'click', '[data-preset-install]', function ( e ) {
				e.preventDefault();
				self.installPreset( $( this ).data( 'preset-install' ), $( this ), $( this ).data( 'preset-type' ) || 'task' );
			} );
		},

		/** ------------------------------------------------------------------ *
		 *  AJAX helpers
		 * ------------------------------------------------------------------ */
		ajax: function ( action, data, callback ) {
			data = $.extend(
				{
					action: action,
					nonce:  wpMcpAiScheduleManager.nonce,
				},
				data
			);

			// eslint-disable-next-line no-console
			console.log( '[NV oOS Schedule Manager] AJAX →', action, data );

			$.post( wpMcpAiScheduleManager.ajaxUrl, data )
				.done( function ( response ) {
					if ( response.success ) {
						// eslint-disable-next-line no-console
						console.log( '[NV oOS Schedule Manager] AJAX ← OK', action, response.data );
						callback( null, response.data );
					} else {
						// eslint-disable-next-line no-console
						console.warn( '[NV oOS Schedule Manager] AJAX ← FAIL', action, response.data );
						callback( ( response.data && response.data.message ) || wpMcpAiScheduleManager.strings.error );
					}
				} )
				.fail( function ( jqXHR, textStatus, errorThrown ) {
					// eslint-disable-next-line no-console
					console.error( '[NV oOS Schedule Manager] AJAX ← ERROR', action, textStatus, errorThrown );
					callback( wpMcpAiScheduleManager.strings.error );
				} );
		},

		/** ------------------------------------------------------------------ *
		 *  Schedule list
		 * ------------------------------------------------------------------ */
		loadSchedules: function () {
			const self   = this;
			const $tbody = $( '#wp-mcp-ai-sm-tbody' );

			// eslint-disable-next-line no-console
			console.log( '[NV oOS Schedule Manager] Loading schedules…' );

			$tbody.html(
				'<tr class="wp-mcp-ai-sm-loading-row"><td colspan="8"><span class="spinner is-active"></span> ' +
				wpMcpAiScheduleManager.strings.loading + '</td></tr>'
			);

			this.ajax( 'wp_mcp_ai_sm_get_schedules', {}, function ( err, data ) {
				if ( err ) {
					// eslint-disable-next-line no-console
					console.error( '[NV oOS Schedule Manager] Failed to load schedules:', err );
					$tbody.html( '<tr><td colspan="8" class="wp-mcp-ai-sm-error">' + err + '</td></tr>' );
					return;
				}

				// eslint-disable-next-line no-console
				console.log( '[NV oOS Schedule Manager] Loaded', ( data.schedules || [] ).length, 'schedules' );

				self.schedules = {};
				( data.schedules || [] ).forEach( function ( s ) {
					self.schedules[ s.id ] = s;
				} );

				self.renderTable( data.schedules || [] );
			} );
		},

		renderTable: function ( schedules ) {
			const self   = this;
			const $tbody = $( '#wp-mcp-ai-sm-tbody' );
			const s      = wpMcpAiScheduleManager.strings;

			if ( ! schedules.length ) {
				$tbody.html(
					'<tr><td colspan="8" class="wp-mcp-ai-sm-empty">' +
					s.noSchedules + '</td></tr>'
				);
				return;
			}

			const rows = schedules.map( function ( schedule ) {
				return self.buildRow( schedule );
			} );

			$tbody.html( rows.join( '' ) );
			this.applyFilters();
		},

		buildRow: function ( s ) {
			const strings   = wpMcpAiScheduleManager.strings;
			let typeLabel;
			if ( s.schedule_type === 'workflow' ) {
				typeLabel = strings.typeWorkflow;
			} else if ( s.schedule_type === 'assistant_run' ) {
				typeLabel = strings.typeAssistant;
			} else if ( s.schedule_type === 'channel_broadcast' ) {
				typeLabel = strings.typeBroadcast || 'Channel Broadcast';
			} else {
				typeLabel = strings.typeTask;
			}

			const statusClass = 'wp-mcp-ai-sm-status-' + ( s.last_run_status || 'never' );
			let statusLabel;
			if ( 'success' === s.last_run_status ) {
				statusLabel = strings.statusSuccess;
			} else if ( 'failure' === s.last_run_status ) {
				statusLabel = strings.statusFailure;
			} else if ( 'pending' === s.last_run_status ) {
				statusLabel = strings.statusPending;
			} else {
				statusLabel = strings.statusNever;
			}

			const enabledChecked = s.enabled ? 'checked' : '';
			const nextRun        = s.next_run || '—';

			let row = '<tr data-sm-id="' + s.id + '"' +
				' data-sm-type="' + s.schedule_type + '"' +
				' data-sm-enabled="' + ( s.enabled ? '1' : '0' ) + '">';

			// Name.
			row += '<td class="column-name">';
			row += '<strong>' + SM.esc( s.name ) + '</strong>';
			if ( s.description ) {
				row += '<p class="description">' + SM.esc( s.description ) + '</p>';
			}
			row += '</td>';

			// Type badge.
			row += '<td class="column-type">';
			row += '<span class="wp-mcp-ai-sm-badge wp-mcp-ai-sm-badge-' + s.schedule_type + '">';
			row += SM.esc( typeLabel );
			row += '</span></td>';

			// Interval.
			row += '<td class="column-schedule">' + SM.esc( s.schedule ) + '</td>';

			// Next run.
			row += '<td class="column-next-run">' + SM.esc( nextRun ) + '</td>';

			// Last status.
			row += '<td class="column-last-status">';
			row += '<span class="wp-mcp-ai-sm-status ' + statusClass + '">';
			row += SM.esc( statusLabel );
			if ( 'failure' === s.last_run_status && s.last_error ) {
				row += ' <abbr title="' + SM.esc( s.last_error ) + '">(?)</abbr>';
			}
			row += '</span>';
			if ( s.last_run_time ) {
				row += '<br><small>' + SM.esc( s.last_run_time ) + '</small>';
			}
			row += '</td>';

			// Run count.
			row += '<td class="column-runs">' + parseInt( s.run_count, 10 ) + '</td>';

			// Enabled toggle.
			row += '<td class="column-enabled">';
			row += '<label class="wp-mcp-ai-sm-toggle-switch" title="' +
				( s.enabled ? strings.enabled : strings.disabled ) + '">';
			row += '<input type="checkbox" class="wp-mcp-ai-sm-enable-toggle"' +
				' data-sm-action="toggle" data-sm-id="' + s.id + '"' +
				' ' + enabledChecked + '>';
			row += '<span class="wp-mcp-ai-sm-slider"></span>';
			row += '</label></td>';

			// Actions (icon-only buttons with title tooltips).
			row += '<td class="column-actions">';
			row += '<button type="button" class="button button-small" data-sm-action="trigger" data-sm-id="' + s.id + '" title="Run" aria-label="Run">' +
				'&#9654;</button> ';
			row += '<button type="button" class="button button-small" data-sm-action="edit" data-sm-id="' + s.id + '" title="Edit" aria-label="Edit">' +
				'&#9998;</button> ';
			row += '<button type="button" class="button button-small" data-sm-action="history" data-sm-id="' + s.id + '" title="History" aria-label="History">' +
				'&#128203;</button> ';
			row += '<button type="button" class="button button-small button-link-delete" data-sm-action="delete" data-sm-id="' + s.id + '" title="Delete" aria-label="Delete">' +
				'&#10005;</button>';
			row += '</td></tr>';

			return row;
		},

		applyFilters: function () {
			const typeFilter   = $( '#wp-mcp-ai-sm-filter-type' ).val();
			const statusFilter = $( '#wp-mcp-ai-sm-filter-status' ).val();

			$( '#wp-mcp-ai-sm-tbody tr[data-sm-id]' ).each( function () {
				const $row   = $( this );
				const type   = $row.data( 'sm-type' );
				const active = $row.data( 'sm-enabled' );

				const typeMatch   = ! typeFilter || type === typeFilter;
				const statusMatch = ! statusFilter ||
					( 'enabled' === statusFilter && '1' === String( active ) ) ||
					( 'disabled' === statusFilter && '0' === String( active ) );

				$row.toggle( typeMatch && statusMatch );
			} );
		},

		/** ------------------------------------------------------------------ *
		 *  Type panel switching
		 * ------------------------------------------------------------------ */
		switchTypePanel: function ( type ) {
			$( '.wp-mcp-ai-sm-type-panel' ).hide();
			$( '#sm-panel-' + type ).show();
		},

		/** ------------------------------------------------------------------ *
		 *  Workflow step builder
		 * ------------------------------------------------------------------ */
		addWorkflowStep: function ( step ) {
			step = step || { tool_slug: '', arguments: {}, label: '' };
			const $list  = $( '#sm-workflow-steps' );
			const index  = $list.children().length;
			const strings = wpMcpAiScheduleManager.strings;

			const $row = $( '<div>' ).addClass( 'wp-mcp-ai-sm-step-row' ).attr( 'data-step', index );
			$row.html(
				'<span class="wp-mcp-ai-sm-step-handle dashicons dashicons-move"></span>' +
				'<span class="wp-mcp-ai-sm-step-num">' + ( index + 1 ) + '</span>' +
				'<input type="text" class="regular-text sm-step-slug" placeholder="tool_slug" value="' + this.esc( step.tool_slug ) + '">' +
				'<input type="text" class="regular-text sm-step-args" placeholder=\'{"key":"value"}\' value=\'' + this.esc( JSON.stringify( step.arguments || {} ) ) + '\'>' +
				'<input type="text" class="regular-text sm-step-label" placeholder="Label (optional)" value="' + this.esc( step.label || '' ) + '">' +
				'<button type="button" class="button button-small button-link-delete wp-mcp-ai-sm-remove-step" title="' + strings.removeStep + '">&times;</button>'
			);

			$list.append( $row );
		},

		renumberSteps: function () {
			$( '.wp-mcp-ai-sm-step-row' ).each( function ( i ) {
				$( this ).find( '.wp-mcp-ai-sm-step-num' ).text( i + 1 );
				$( this ).attr( 'data-step', i );
			} );
		},

		collectWorkflowSteps: function () {
			const steps   = [];
			let hasError  = false;

			$( '#sm-workflow-steps .wp-mcp-ai-sm-step-row' ).each( function () {
				const $row    = $( this );
				const $argsEl = $row.find( '.sm-step-args' );
				const slug    = $row.find( '.sm-step-slug' ).val().trim();
				const label   = $row.find( '.sm-step-label' ).val().trim();
				const raw     = $argsEl.val().trim();
				let args      = {};

				if ( raw ) {
					try {
						args = JSON.parse( raw );
						$argsEl.removeClass( 'wp-mcp-ai-sm-field-error' );
					} catch ( e ) {
						$argsEl.addClass( 'wp-mcp-ai-sm-field-error' );
						$argsEl.attr( 'title', 'Invalid JSON: ' + e.message );
						hasError = true;
					}
				}

				if ( slug ) {
					steps.push( { tool_slug: slug, arguments: args, label: label } );
				}
			} );

			return hasError ? null : steps;
		},

		/**
		 * Collect failure-notification channel selections and credentials.
		 *
		 * @return {Object|null} { channels: string[], credentials: Object } or null if none selected.
		 */
		collectNotifyChannels: function () {
			const channels = $( '.sm-notify-channel:checked' ).map( function () { return $( this ).val(); } ).get();
			if ( ! channels.length ) {
				return null;
			}
			const raw         = $( '#sm-notify-channel-credentials' ).val().trim();
			let   credentials = {};
			if ( raw ) {
				try { credentials = JSON.parse( raw ); } catch ( e ) { /* ignore malformed creds */ }
			}
			return { channels: channels, credentials: credentials };
		},

		/** ------------------------------------------------------------------ *
		 *  Create schedule
		 * ------------------------------------------------------------------ */
		createSchedule: function () {
			const self    = this;
			const $btn    = $( '#wp-mcp-ai-sm-create-btn' );
			const $msg    = $( '#wp-mcp-ai-sm-create-msg' );
			const type    = $( '#sm-type' ).val();
			const strings = wpMcpAiScheduleManager.strings;

			const data = this.collectFormData( '#wp-mcp-ai-sm-create-form', type );
			if ( ! data ) {
				return;
			}

			// eslint-disable-next-line no-console
			console.log( '[NV oOS Schedule Manager] Creating schedule:', type, data );

			$btn.prop( 'disabled', true ).text( strings.saving );
			$msg.text( '' ).removeClass( 'error success' );

			this.ajax(
				'wp_mcp_ai_sm_create_schedule',
				{ schedule: JSON.stringify( data ) },
				function ( err, result ) {
					$btn.prop( 'disabled', false ).text( strings.saved );
					setTimeout( function () { $btn.text( 'Create Schedule' ); }, 2000 );

					if ( err ) {
						// eslint-disable-next-line no-console
						console.error( '[NV oOS Schedule Manager] Create failed:', err );
						$msg.text( err ).addClass( 'error' );
						return;
					}

					// eslint-disable-next-line no-console
					console.log( '[NV oOS Schedule Manager] Created schedule:', result );

					$msg.text( result.message ).addClass( 'success' );
					self.resetCreateForm();
					self.loadSchedules();
					$( '#wp-mcp-ai-sm-create-form' ).slideUp( 200 );
					$( '.wp-mcp-ai-sm-create-toggle' ).attr( 'aria-expanded', 'false' );
				}
			);
		},

		/** Collect and validate form fields for a given type */
		collectFormData: function ( formSelector, type ) {
			const data = {
				schedule_type:    type,
				name:             $( '#sm-name' ).val().trim(),
				description:      $( '#sm-description' ).val().trim(),
				schedule:         $( '#sm-schedule' ).val(),
				enabled:          $( '#sm-enabled' ).is( ':checked' ),
				priority:         parseInt( $( '#sm-priority' ).val(), 10 ) || 5,
				notify_on_failure: $( '#sm-notify-on-failure' ).is( ':checked' ),
				notify_email:     $( '#sm-notify-email' ).val().trim(),
				max_retries:      parseInt( $( '#sm-max-retries' ).val(), 10 ) || 0,
				retry_delay:      parseInt( $( '#sm-retry-delay' ).val(), 10 ) || 300,
				timeout:          parseInt( $( '#sm-timeout' ).val(), 10 ) || 0,
				callback_url:     $( '#sm-callback-url' ).val().trim(),
				tags:             $( '#sm-tags' ).val().split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean ),
			};

			// Timestamp.
			const tsRaw = $( '#sm-timestamp' ).val();
			if ( tsRaw ) {
				const ts = Math.floor( new Date( tsRaw ).getTime() / 1000 );
				if ( ts > Math.floor( Date.now() / 1000 ) ) {
					data.timestamp = ts;
				}
			}

			if ( ! data.name ) {
				$( '#wp-mcp-ai-sm-create-msg' ).text( 'Name is required.' ).addClass( 'error' );
				return null;
			}

			if ( 'task' === type ) {
				data.hook = $( '#sm-hook' ).val().trim();
				if ( ! data.hook ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'Action hook is required for Task type.' ).addClass( 'error' );
					return null;
				}
			} else if ( 'workflow' === type ) {
				data.workflow_steps = this.collectWorkflowSteps();
				if ( null === data.workflow_steps ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'One or more workflow step arguments contain invalid JSON. Please fix the highlighted fields.' ).addClass( 'error' );
					return null;
				}
				if ( ! data.workflow_steps.length ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'At least one workflow step is required.' ).addClass( 'error' );
					return null;
				}
			} else if ( 'assistant_run' === type ) {
				const assistantId  = parseInt( $( '#sm-assistant-id' ).val(), 10 );
				const assistantMsg = $( '#sm-assistant-message' ).val().trim();
				if ( ! assistantId || ! assistantMsg ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'Assistant and message are required.' ).addClass( 'error' );
					return null;
				}
				data.assistant_config = { assistant_id: assistantId, message: assistantMsg };
			} else if ( 'channel_broadcast' === type ) {
				const bcMsg      = $( '#sm-broadcast-message' ).val().trim();
				const bcChannels = $( '.sm-broadcast-channel:checked' ).map( function () { return $( this ).val(); } ).get();
				const bcCredsRaw = $( '#sm-broadcast-credentials' ).val().trim();
				let bcCreds    = {};

				if ( ! bcMsg ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'A broadcast message is required.' ).addClass( 'error' );
					return null;
				}
				if ( ! bcChannels.length ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( 'Select at least one broadcast channel.' ).addClass( 'error' );
					return null;
				}
				if ( bcCredsRaw ) {
					try {
						bcCreds = JSON.parse( bcCredsRaw );
						$( '#sm-broadcast-credentials' ).removeClass( 'wp-mcp-ai-sm-field-error' );
					} catch ( e ) {
						$( '#sm-broadcast-credentials' ).addClass( 'wp-mcp-ai-sm-field-error' ).attr( 'title', 'Invalid JSON: ' + e.message );
						$( '#wp-mcp-ai-sm-create-msg' ).text( 'Broadcast credentials contain invalid JSON.' ).addClass( 'error' );
						return null;
					}
				}
				data.broadcast_config = { message: bcMsg, channels: bcChannels, credentials: bcCreds };
			} else if ( 'workflow_builder' === type ) {
				const wbId = $( '#sm-workflow-builder-id' ).val();
				if ( ! wbId ) {
					$( '#wp-mcp-ai-sm-create-msg' ).text( wpMcpAiScheduleManager.strings.selectWorkflow || 'Please select a saved workflow.' ).addClass( 'error' );
					return null;
				}
				data.workflow_builder_id = wbId;
			}

			// Collect failure-notification channels (applies to all schedule types).
			const notifyChannelData = this.collectNotifyChannels();
			if ( notifyChannelData ) {
				data.notify_channels            = notifyChannelData.channels;
				data.notify_channel_credentials = notifyChannelData.credentials;
			}

			// Collect result-capture / display settings.
			data.display = {
				result_capture:   $( '#sm-result-capture' ).val() || 'summary',
				result_retention: parseInt( $( '#sm-result-retention' ).val(), 10 ) || 10,
				public_render:    $( '#sm-public-render' ).is( ':checked' ),
				// split(',').map(trim).filter(Boolean) cleanly handles empty input and extra commas.
				public_fields:    $( '#sm-public-fields' ).val().split( ',' ).map( function ( f ) { return f.trim(); } ).filter( Boolean ),
				widget_defaults: {
					render_mode:      $( '#sm-widget-render-mode' ).val() || 'summary-card',
					refresh_interval: parseInt( $( '#sm-widget-refresh-interval' ).val(), 10 ) || 0,
				},
			};

			return data;
		},

		resetCreateForm: function () {
			$( '#sm-name, #sm-description, #sm-hook, #sm-tags, #sm-assistant-message' ).val( '' );
			$( '#sm-broadcast-message, #sm-broadcast-credentials, #sm-notify-channel-credentials' ).val( '' );
			$( '.sm-broadcast-channel, .sm-notify-channel' ).prop( 'checked', false );
			$( '#sm-priority' ).val( '5' );
			$( '#sm-max-retries' ).val( '0' );
			$( '#sm-retry-delay' ).val( '300' );
			$( '#sm-enabled' ).prop( 'checked', true );
			$( '#sm-notify-on-failure' ).prop( 'checked', false );
			$( '#sm-notify-email-wrap, #sm-notify-channels-row' ).hide();
			$( '#sm-workflow-steps' ).empty();
			$( '#sm-workflow-builder-id' ).val( '' );
			$( '#sm-type' ).val( 'task' ).trigger( 'change' );
			$( '#sm-schedule' ).val( 'single' );
			$( '#sm-timestamp' ).val( '' );
			// Reset result-capture display fields.
			$( '#sm-result-capture' ).val( 'summary' );
			$( '#sm-result-retention' ).val( '10' );
			$( '#sm-public-render' ).prop( 'checked', false );
			$( '#sm-public-fields' ).val( '' );
			$( '#sm-widget-render-mode' ).val( 'summary-card' );
			$( '#sm-widget-refresh-interval' ).val( '0' );
		},

		/** ------------------------------------------------------------------ *
		 *  Row actions
		 * ------------------------------------------------------------------ */
		handleAction: function ( action, id, $el ) {
			switch ( action ) {
				case 'toggle':
					this.toggleSchedule( id, $el.is( ':checked' ) );
					break;
				case 'trigger':
					this.triggerSchedule( id );
					break;
				case 'edit':
					this.openEditModal( id );
					break;
				case 'history':
					this.openHistoryModal( id );
					break;
				case 'delete':
					if ( window.confirm( wpMcpAiScheduleManager.strings.confirmDelete ) ) {
						this.deleteSchedule( id );
					}
					break;
			}
		},

		toggleSchedule: function ( id, enabled ) {
			const self = this;

			// eslint-disable-next-line no-console
			console.log( '[NV oOS Schedule Manager] Toggle schedule', id, 'enabled=' + enabled );

			this.ajax(
				'wp_mcp_ai_sm_toggle_schedule',
				{ schedule_id: id, enabled: enabled ? '1' : '0' },
				function ( err ) {
					if ( err ) {
						// eslint-disable-next-line no-console
						console.error( '[NV oOS Schedule Manager] Toggle failed:', id, err );
						alert( err );
						// Revert UI.
						$( '[data-sm-id="' + id + '"].wp-mcp-ai-sm-enable-toggle' ).prop( 'checked', ! enabled );
						return;
					}
					// eslint-disable-next-line no-console
					console.log( '[NV oOS Schedule Manager] Toggle success:', id, 'enabled=' + enabled );
					$( 'tr[data-sm-id="' + id + '"]' ).attr( 'data-sm-enabled', enabled ? '1' : '0' );
					if ( self.schedules[ id ] ) {
						self.schedules[ id ].enabled = enabled;
					}
				}
			);
		},

		triggerSchedule: function ( id ) {
			if ( ! window.confirm( wpMcpAiScheduleManager.strings.confirmTrigger ) ) {
				return;
			}

			const self = this;
			const $row = $( 'tr[data-sm-id="' + id + '"]' );
			$row.addClass( 'wp-mcp-ai-sm-running' );

			// eslint-disable-next-line no-console
			console.log( '[NV oOS Schedule Manager] Triggering schedule:', id );

			this.ajax(
				'wp_mcp_ai_sm_trigger_schedule',
				{ schedule_id: id },
				function ( err, data ) {
					$row.removeClass( 'wp-mcp-ai-sm-running' );

					if ( err ) {
						// eslint-disable-next-line no-console
						console.error( '[NV oOS Schedule Manager] Trigger failed:', id, err );
						alert( err );
						return;
					}

					// eslint-disable-next-line no-console
					console.log( '[NV oOS Schedule Manager] Trigger completed:', id, data );

					// Surface debug output if present.
					if ( data && data.debug_output ) {
						// eslint-disable-next-line no-console
						console.warn( '[NV oOS Schedule Manager] Debug output from trigger:', data.debug_output );
					}

					// Reload to show updated status.
					self.loadSchedules();
				}
			);
		},

		deleteSchedule: function ( id ) {
			const self = this;
			const $row = $( 'tr[data-sm-id="' + id + '"]' );
			$row.addClass( 'wp-mcp-ai-sm-deleting' );

			this.ajax(
				'wp_mcp_ai_sm_delete_schedule',
				{ schedule_id: id },
				function ( err ) {
					if ( err ) {
						$row.removeClass( 'wp-mcp-ai-sm-deleting' );
						alert( err );
						return;
					}
					$row.fadeOut( 300, function () {
						$( this ).remove();
						if ( ! $( '#wp-mcp-ai-sm-tbody tr[data-sm-id]' ).length ) {
							$( '#wp-mcp-ai-sm-tbody' ).html(
								'<tr><td colspan="8" class="wp-mcp-ai-sm-empty">' +
								wpMcpAiScheduleManager.strings.noSchedules + '</td></tr>'
							);
						}
					} );
					delete self.schedules[ id ];
				}
			);
		},

		/** ------------------------------------------------------------------ *
		 *  History modal
		 * ------------------------------------------------------------------ */

		/** @type {Chart|null} Active Chart.js instance for the history sparkline. */
		_historyChart: null,

		openHistoryModal: function ( id ) {
			this.activeId = id;

			const $modal     = $( '#wp-mcp-ai-sm-history-modal' );
			const $body      = $( '#wp-mcp-ai-sm-history-body' );
			const $chartWrap = $modal.find( '.wp-mcp-ai-sm-history-chart-wrap' );

			$body.html( '<span class="spinner is-active"></span>' );
			$chartWrap.hide();
			$modal.fadeIn( 200 );

			this.ajax(
				'wp_mcp_ai_sm_get_history',
				{ schedule_id: id },
				function ( err, data ) {
					if ( err ) {
						$body.html( '<p class="error">' + err + '</p>' );
						return;
					}

					const history = data.history || [];
					if ( ! history.length ) {
						$body.html( '<p>' + wpMcpAiScheduleManager.strings.noHistory + '</p>' );
						return;
					}

					// ── chart.js sparkline ─────────────────────────────────
					if ( typeof window.Chart !== 'undefined' ) {
						const labels  = history.map( function ( e ) { return e.time ? e.time.slice( 0, 16 ) : ''; } ).reverse();
						const success = history.map( function ( e ) { return 'success' === e.status ? 1 : 0; } ).reverse();
						const failure = history.map( function ( e ) { return 'failure' === e.status || 'failed' === e.status ? 1 : 0; } ).reverse();

						// Destroy previous chart instance before re-creating.
						if ( SM._historyChart ) {
							SM._historyChart.destroy();
							SM._historyChart = null;
						}

						const canvas = document.getElementById( 'wp-mcp-ai-sm-history-chart' );
						if ( canvas ) {
							SM._historyChart = new window.Chart( canvas, {
								type: 'bar',
								data: {
									labels: labels,
									datasets: [
										{
											label: wpMcpAiScheduleManager.strings.chartSuccess,
											data: success,
											backgroundColor: 'rgba(70,185,100,0.75)',
											borderColor: 'rgba(70,185,100,1)',
											borderWidth: 1,
										},
										{
											label: wpMcpAiScheduleManager.strings.chartFailure,
											data: failure,
											backgroundColor: 'rgba(220,60,60,0.75)',
											borderColor: 'rgba(220,60,60,1)',
											borderWidth: 1,
										},
									],
								},
								options: {
									responsive: true,
									maintainAspectRatio: false,
									plugins: {
										legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
										tooltip: { mode: 'index', intersect: false },
									},
									scales: {
										x: { stacked: true, ticks: { font: { size: 10 }, maxRotation: 45, autoSkip: true, maxTicksLimit: 15 } },
										y: { stacked: true, min: 0, max: 1, ticks: { stepSize: 1 } },
									},
								},
							} );
						}

						$chartWrap.show();
					}

					// ── run history table ──────────────────────────────────
					const rows = history.map( function ( entry, idx ) {
						const cls = 'success' === entry.status ? 'wp-mcp-ai-sm-status-success' : 'wp-mcp-ai-sm-status-failure';
						const dur = entry.duration ? ' (' + parseFloat( entry.duration ).toFixed( 3 ) + 's)' : '';
						const errHtml = entry.error
							? '<br><small class="wp-mcp-ai-sm-error-text">' + SM.esc( entry.error ) + '</small>'
							: '';
						let logHtml = '';
						if ( entry.action_log && typeof entry.action_log === 'object' && Object.keys( entry.action_log ).length ) {
							const logId   = 'sm-log-' + idx;
							const logBody = SM.formatActionLog( entry.action_log );
							logHtml = '<br><button type="button" class="button button-small wp-mcp-ai-sm-log-toggle" data-target="' + logId + '">' +
								wpMcpAiScheduleManager.strings.viewLog + '</button>' +
								'<div id="' + logId + '" class="wp-mcp-ai-sm-action-log-wrap" style="display:none;">' +
								logBody + '</div>';
						}
						return '<tr><td class="' + cls + '">' +
							SM.esc( entry.status ) + '</td>' +
							'<td>' + SM.esc( entry.time ) + dur + errHtml + logHtml + '</td></tr>';
					} );

					const $table = $( '<table class="widefat striped"><thead><tr>' +
						'<th>Status</th><th>Time / Error</th></tr></thead><tbody>' +
						rows.join( '' ) + '</tbody></table>' );

					// Toggle log visibility on button click.
					$table.on( 'click', '.wp-mcp-ai-sm-log-toggle', function () {
						const targetId = $( this ).data( 'target' );
						const $pre = $( '#' + targetId );
						if ( $pre.is( ':visible' ) ) {
							$pre.hide();
							$( this ).text( wpMcpAiScheduleManager.strings.viewLog );
						} else {
							$pre.show();
							$( this ).text( wpMcpAiScheduleManager.strings.hideLog );
						}
					} );

					$body.html( $table );
				}
			);
		},

		clearHistory: function ( id ) {
			if ( ! window.confirm( wpMcpAiScheduleManager.strings.confirmClear ) ) {
				return;
			}

			const self = this;
			this.ajax(
				'wp_mcp_ai_sm_clear_history',
				{ schedule_id: id },
				function ( err ) {
					if ( err ) {
						alert( err );
						return;
					}
					self.openHistoryModal( id );
				}
			);
		},

		/**
		 * Export run history for the active schedule as a CSV file.
		 * Uses the csv-stringify-backed PHP endpoint; triggers a browser download.
		 *
		 * @param {string} id Schedule ID.
		 */
		exportHistoryCsv: function ( id ) {
			if ( ! id ) {
				return;
			}

			this.ajax(
				'wp_mcp_ai_sm_export_history_csv',
				{ schedule_id: id },
				function ( err, data ) {
					if ( err ) {
						alert( err );
						return;
					}

					try {
						const bytes    = atob( data.csv );
						const arr      = new Uint8Array( bytes.length );
						for ( let i = 0; i < bytes.length; i++ ) {
							arr[ i ] = bytes.charCodeAt( i );
						}
						const blob = new Blob( [ arr ], { type: 'text/csv;charset=utf-8;' } );
						const url  = URL.createObjectURL( blob );
						const a    = document.createElement( 'a' );
						a.href     = url;
						a.download = data.filename || ( 'schedule-history-' + id + '.csv' );
						document.body.appendChild( a );
						a.click();
						document.body.removeChild( a );
						URL.revokeObjectURL( url );
					} catch ( ex ) {
						alert( wpMcpAiScheduleManager.strings.error );
					}
				}
			);
		},

		/**
		 * Export all enabled schedules as an iCalendar (.ics) file.
		 * Uses the ical-generator-backed PHP endpoint; triggers a browser download.
		 */
		exportIcal: function () {
			this.ajax(
				'wp_mcp_ai_sm_export_ical',
				{},
				function ( err, data ) {
					if ( err ) {
						alert( err );
						return;
					}

					try {
						const bytes = atob( data.ics );
						const arr   = new Uint8Array( bytes.length );
						for ( let i = 0; i < bytes.length; i++ ) {
							arr[ i ] = bytes.charCodeAt( i );
						}
						const blob = new Blob( [ arr ], { type: 'text/calendar;charset=utf-8;' } );
						const url  = URL.createObjectURL( blob );
						const a    = document.createElement( 'a' );
						a.href     = url;
						a.download = data.filename || 'nvoos-schedules.ics';
						document.body.appendChild( a );
						a.click();
						document.body.removeChild( a );
						URL.revokeObjectURL( url );
					} catch ( ex ) {
						alert( wpMcpAiScheduleManager.strings.error );
					}
				}
			);
		},

		/** ------------------------------------------------------------------ *
		 *  Edit modal
		 * ------------------------------------------------------------------ */
		openEditModal: function ( id ) {
			const schedule = this.schedules[ id ];
			if ( ! schedule ) {
				return;
			}

			this.activeId = id;

			const $modal = $( '#wp-mcp-ai-sm-edit-modal' );
			const $body  = $( '#wp-mcp-ai-sm-edit-body' );
			const type   = schedule.schedule_type || 'task';
			const s      = wpMcpAiScheduleManager;

			// Build simple edit fields for metadata (non-type-specific).
			let html = '<table class="form-table wp-mcp-ai-sm-edit-table">';
			html += this.editRow( 'Name', '<input type="text" id="edit-name" class="regular-text" value="' + this.esc( schedule.name ) + '">' );
			html += this.editRow( 'Description', '<textarea id="edit-description" class="large-text" rows="2">' + this.esc( schedule.description ) + '</textarea>' );

			// Interval select.
			let intervalOpts = '';
			$.each( s.scheduleOptions, function ( key, label ) {
				const sel = key === schedule.schedule ? ' selected' : '';
				intervalOpts += '<option value="' + key + '"' + sel + '>' + label + '</option>';
			} );
			html += this.editRow( 'Interval', '<select id="edit-schedule">' + intervalOpts + '</select>' );

			html += this.editRow( 'Priority (1-10)', '<input type="number" id="edit-priority" class="small-text" min="1" max="10" value="' + parseInt( schedule.priority, 10 ) + '">' );
			html += this.editRow( 'Tags', '<input type="text" id="edit-tags" class="regular-text" value="' + this.esc( ( schedule.tags || [] ).join( ', ' ) ) + '">' );
			html += this.editRow( 'Max Retries', '<input type="number" id="edit-max-retries" class="small-text" min="0" max="5" value="' + parseInt( schedule.max_retries, 10 ) + '">' );
			html += this.editRow( 'Retry Delay (s)', '<input type="number" id="edit-retry-delay" class="small-text" min="60" value="' + parseInt( schedule.retry_delay, 10 ) + '">' );
			html += this.editRow( 'Timeout (s)', '<input type="number" id="edit-timeout" class="small-text" min="0" value="' + parseInt( schedule.timeout || 0, 10 ) + '"><br><span class="description">0 = no limit</span>' );
			html += this.editRow( 'Webhook Callback URL', '<input type="url" id="edit-callback-url" class="regular-text" value="' + this.esc( schedule.callback_url || '' ) + '" placeholder="https://example.com/webhook">' );
			html += this.editRow(
				'Failure Email',
				'<label><input type="checkbox" id="edit-notify" ' + ( schedule.notify_on_failure ? 'checked' : '' ) + '> Send email on failure</label>' +
				'<br><input type="email" id="edit-notify-email" class="regular-text" value="' + this.esc( schedule.notify_email ) + '">'
			);

			// Workflow steps (if workflow type).
			if ( 'workflow' === type ) {
				let stepsHtml = '';
				( schedule.workflow_steps || [] ).forEach( function ( step ) {
					stepsHtml += '<div class="wp-mcp-ai-sm-step-row">' +
						'<input type="text" class="sm-step-slug" value="' + this.esc( step.tool_slug ) + '" placeholder="tool_slug">' +
						'<input type="text" class="sm-step-args" value=\'' + this.esc( JSON.stringify( step.arguments || {} ) ) + '\' placeholder=\'{"key":"val"}\'>' +
						'<input type="text" class="sm-step-label" value="' + this.esc( step.label || '' ) + '" placeholder="label">' +
						'<button type="button" class="button button-small button-link-delete wp-mcp-ai-sm-remove-step">&times;</button>' +
					'</div>';
				}.bind( this ) );
				html += this.editRow(
					'Workflow Steps',
					'<div id="sm-workflow-steps">' + stepsHtml + '</div>' +
					'<button type="button" class="button" id="sm-add-step">+ Add Step</button>'
				);
			}

			// Result capture / display settings.
			const disp    = schedule.display || {};
			const dWd     = disp.widget_defaults || {};
			// v comes from a hardcoded allow-list; escape defensively so this pattern
			// remains safe if the array is ever made dynamic.
			const captureOpts = [ 'summary', 'full', 'disabled' ]
				.map( function ( v ) {
					const sel = v === ( disp.result_capture || 'summary' ) ? ' selected' : '';
					return '<option value="' + this.esc( v ) + '"' + sel + '>' + this.esc( v ) + '</option>';
				}.bind( this ) )
				.join( '' );
			const renderModeOpts = [ 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' ]
				.map( function ( v ) {
					const sel = v === ( dWd.render_mode || 'summary-card' ) ? ' selected' : '';
					return '<option value="' + this.esc( v ) + '"' + sel + '>' + this.esc( v ) + '</option>';
				}.bind( this ) )
				.join( '' );
			html += '<tr><td colspan="2"><hr><strong>Result Capture</strong></td></tr>';
			html += this.editRow( 'Capture Mode', '<select id="edit-result-capture">' + captureOpts + '</select>' );
			html += this.editRow( 'Retention (runs)', '<input type="number" id="edit-result-retention" class="small-text" min="1" max="100" value="' + ( parseInt( disp.result_retention, 10 ) || 10 ) + '"><br><span class="description">1–100</span>' );
			html += this.editRow(
				'Public rendering',
				'<label><input type="checkbox" id="edit-public-render" ' + ( disp.public_render ? 'checked' : '' ) + '> Allow unauthenticated access</label>'
			);
			// placeholder is a static string literal; value is escaped via this.esc().
			html += this.editRow( 'Public fields (allow-list)', '<input type="text" id="edit-public-fields" class="regular-text" value="' + this.esc( ( disp.public_fields || [] ).join( ', ' ) ) + '" placeholder="summary, data.items"><br><span class="description">Comma-separated dotted JSON paths</span>' );
			html += this.editRow( 'Widget render mode', '<select id="edit-widget-render-mode">' + renderModeOpts + '</select>' );
			html += this.editRow( 'Widget auto-refresh (s)', '<input type="number" id="edit-widget-refresh-interval" class="small-text" min="0" max="3600" value="' + ( parseInt( dWd.refresh_interval, 10 ) || 0 ) + '"><br><span class="description">0 = off</span>' );

			// Result Delivery section.
			var rd = schedule.result_delivery || {};
			var rdSuccess = ( rd.on_success || {} ).channels || {};
			var rdFailure = ( rd.on_failure || {} ).channels || {};
			html += '<tr><td colspan="2"><hr><strong>Result Delivery</strong></td></tr>';

			// On Success channels.
			html += '<tr><td colspan="2"><em>On Success</em></td></tr>';
			html += this.editRow(
				'Email',
				'<label><input type="checkbox" id="edit-rd-success-email" ' + ( ( rdSuccess.email || {} ).enabled ? 'checked' : '' ) + '> Send result email</label>' +
				'<br><input type="email" id="edit-rd-success-email-to" class="regular-text" value="' + this.esc( ( rdSuccess.email || {} ).to || '' ) + '" placeholder="team@example.com">' +
				'<br><select id="edit-rd-success-email-template"><option value="full">Full Report</option><option value="summary">Summary</option></select>'
			);
			html += this.editRow(
				'Slack',
				'<label><input type="checkbox" id="edit-rd-success-slack" ' + ( ( rdSuccess.slack || {} ).enabled ? 'checked' : '' ) + '> Post to Slack</label>' +
				'<br><input type="text" id="edit-rd-success-slack-channel" class="regular-text" value="' + this.esc( ( rdSuccess.slack || {} ).channel || '' ) + '" placeholder="#research">'
			);
			html += this.editRow(
				'Paper Store',
				'<label><input type="checkbox" id="edit-rd-success-paper" ' + ( ( rdSuccess.paper_store || {} ).enabled ? 'checked' : '' ) + '> Save to Paper Store</label>' +
				'<br><input type="text" id="edit-rd-success-paper-collection" class="regular-text" value="' + this.esc( ( rdSuccess.paper_store || {} ).collection || '' ) + '" placeholder="blog-research">' +
				'<br><select id="edit-rd-success-paper-driver"><option value="json">JSON</option><option value="markdown_yaml">Markdown + YAML</option></select>' +
				' <input type="number" id="edit-rd-success-paper-retention" class="small-text" value="' + ( parseInt( ( rdSuccess.paper_store || {} ).retention, 10 ) || 30 ) + '" min="0" max="100" style="width:60px"> runs'
			);

			// WordPress post auto-creation.
			var wpCfg = rdSuccess.wordpress || {};
			html += this.editRow(
				'WordPress Post',
				'<label><input type="checkbox" id="edit-rd-success-wordpress" ' + ( wpCfg.enabled ? 'checked' : '' ) + '> Auto-create post from result</label>' +
				'<br><span class="description">When the AI already calls create_post during the run, this channel is automatically skipped to avoid duplicate posts.</span>' +
				'<br><label style="margin-top:4px;display:inline-block"><input type="checkbox" id="edit-rd-success-wordpress-skip-if-ai" ' + ( false !== wpCfg.skip_if_ai_posted ? 'checked' : '' ) + '> Skip if AI already created posts</label>' +
				'<br><select id="edit-rd-success-wordpress-post-type" style="margin-top:4px"><option value="post" ' + ( 'post' === ( wpCfg.post_type || 'post' ) ? 'selected' : '' ) + '>Post</option><option value="page" ' + ( 'page' === ( wpCfg.post_type || '' ) ? 'selected' : '' ) + '>Page</option></select>' +
				' <select id="edit-rd-success-wordpress-post-status"><option value="draft" ' + ( 'draft' === ( wpCfg.post_status || 'draft' ) ? 'selected' : '' ) + '>Draft</option><option value="publish" ' + ( 'publish' === ( wpCfg.post_status || '' ) ? 'selected' : '' ) + '>Publish</option><option value="pending" ' + ( 'pending' === ( wpCfg.post_status || '' ) ? 'selected' : '' ) + '>Pending Review</option></select>' +
				' Category ID: <input type="number" id="edit-rd-success-wordpress-category" class="small-text" value="' + ( parseInt( wpCfg.category, 10 ) || 0 ) + '" min="0" style="width:80px">'
			);

			// On Failure channels.
			html += '<tr><td colspan="2"><hr><em>On Failure</em></td></tr>';
			html += this.editRow(
				'Failure Email',
				'<label><input type="checkbox" id="edit-rd-failure-email" ' + ( ( rdFailure.email || {} ).enabled ? 'checked' : '' ) + '> Send failure alert</label>' +
				'<br><input type="email" id="edit-rd-failure-email-to" class="regular-text" value="' + this.esc( ( rdFailure.email || {} ).to || '' ) + '" placeholder="admin@example.com">'
			);

			html += '</table>';

			$body.html( html );
			$modal.fadeIn( 200 );
		},

		editRow: function ( label, content ) {
			return '<tr><th>' + label + '</th><td>' + content + '</td></tr>';
		},

		saveEdit: function () {
			const self = this;
			const id   = this.activeId;

			if ( ! id ) {
				return;
			}

			const $btn = $( '#wp-mcp-ai-sm-edit-save-btn' );
			$btn.prop( 'disabled', true ).text( wpMcpAiScheduleManager.strings.saving );

			const data = {
				name:             $( '#edit-name' ).val().trim(),
				description:      $( '#edit-description' ).val().trim(),
				schedule:         $( '#edit-schedule' ).val(),
				priority:         parseInt( $( '#edit-priority' ).val(), 10 ) || 5,
				tags:             $( '#edit-tags' ).val().split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean ),
				max_retries:      parseInt( $( '#edit-max-retries' ).val(), 10 ) || 0,
				retry_delay:      parseInt( $( '#edit-retry-delay' ).val(), 10 ) || 300,
				timeout:          parseInt( $( '#edit-timeout' ).val(), 10 ) || 0,
				callback_url:     $( '#edit-callback-url' ).val().trim(),
				notify_on_failure: $( '#edit-notify' ).is( ':checked' ),
				notify_email:     $( '#edit-notify-email' ).val().trim(),
			};

			// Workflow steps.
			if ( $( '#sm-workflow-steps' ).length ) {
				const steps = self.collectWorkflowSteps();
				if ( null === steps ) {
					$btn.prop( 'disabled', false ).text( 'Save Changes' );
					alert( 'One or more workflow step arguments contain invalid JSON. Please fix the highlighted fields.' );
					return;
				}
				data.workflow_steps = steps;
			}

			// Result capture / display settings.
			data.display = {
				result_capture:   $( '#edit-result-capture' ).val() || 'summary',
				result_retention: parseInt( $( '#edit-result-retention' ).val(), 10 ) || 10,
				public_render:    $( '#edit-public-render' ).is( ':checked' ),
				public_fields:    $( '#edit-public-fields' ).val().split( ',' ).map( function ( f ) { return f.trim(); } ).filter( Boolean ),
				widget_defaults: {
					render_mode:      $( '#edit-widget-render-mode' ).val() || 'summary-card',
					refresh_interval: parseInt( $( '#edit-widget-refresh-interval' ).val(), 10 ) || 0,
				},
			};

			// Result delivery config.
			data.result_delivery = {
				on_success: {
					channels: {
						email: {
							enabled:  $( '#edit-rd-success-email' ).is( ':checked' ),
							to:       $( '#edit-rd-success-email-to' ).val().trim(),
							template: $( '#edit-rd-success-email-template' ).val() || 'full',
						},
						slack: {
							enabled: $( '#edit-rd-success-slack' ).is( ':checked' ),
							channel: $( '#edit-rd-success-slack-channel' ).val().trim(),
						},
						paper_store: {
							enabled:    $( '#edit-rd-success-paper' ).is( ':checked' ),
							collection: $( '#edit-rd-success-paper-collection' ).val().trim(),
							driver:     $( '#edit-rd-success-paper-driver' ).val() || 'json',
							retention:  parseInt( $( '#edit-rd-success-paper-retention' ).val(), 10 ) || 30,
						},
						wordpress: {
							enabled:           $( '#edit-rd-success-wordpress' ).is( ':checked' ),
							skip_if_ai_posted: $( '#edit-rd-success-wordpress-skip-if-ai' ).is( ':checked' ),
							post_type:         $( '#edit-rd-success-wordpress-post-type' ).val() || 'post',
							post_status:       $( '#edit-rd-success-wordpress-post-status' ).val() || 'draft',
							category:          parseInt( $( '#edit-rd-success-wordpress-category' ).val(), 10 ) || 0,
						},
					},
				},
				on_failure: {
					channels: {
						email: {
							enabled:  $( '#edit-rd-failure-email' ).is( ':checked' ),
							to:       $( '#edit-rd-failure-email-to' ).val().trim(),
							template: 'error',
						},
					},
				},
			};

			this.ajax(
				'wp_mcp_ai_sm_update_schedule',
				{ schedule_id: id, schedule: JSON.stringify( data ) },
				function ( err ) {
					$btn.prop( 'disabled', false ).text( 'Save Changes' );

					if ( err ) {
						alert( err );
						return;
					}

					self.closeModals();
					self.loadSchedules();
				}
			);
		},

		/** ------------------------------------------------------------------ *
		 *  Modal helpers
		 * ------------------------------------------------------------------ */
		closeModals: function () {
			$( '.wp-mcp-ai-sm-modal' ).fadeOut( 150 );
			this.activeId = null;

			// Destroy chart.js instance when the history modal closes.
			if ( SM._historyChart ) {
				SM._historyChart.destroy();
				SM._historyChart = null;
			}
		},

		/**
		 * Format an action_log object from a schedule run into human-readable HTML.
		 *
		 * The format depends on the schedule type stored in action_log.type:
		 * - task:             shows hook name and args.
		 * - workflow:         shows each step with tool slug, duration and result preview.
		 * - assistant_run:    shows assistant ID and message preview.
		 * - channel_broadcast: shows channels and message preview.
		 * - workflow_builder: shows the workflow builder ID.
		 * Falls back to a formatted JSON <pre> for unrecognised shapes.
		 *
		 * @param {Object} log action_log object from the history entry.
		 * @return {string} Safe HTML string.
		 */
		formatActionLog: function ( log ) {
			if ( ! log || typeof log !== 'object' ) {
				return '';
			}

			const type = log.type || '';
			let html   = '<div class="wp-mcp-ai-sm-action-log">';

			if ( 'task' === type ) {
				html += '<p><strong>Hook:</strong> ' + this.esc( log.hook || '' ) + '</p>';
				if ( log.args && Object.keys( log.args ).length ) {
					html += '<pre class="wp-mcp-ai-sm-log-pre">' + this.esc( JSON.stringify( log.args, null, 2 ) ) + '</pre>';
				}
			} else if ( 'workflow' === type ) {
				const steps              = log.steps;
				const RESULT_PREVIEW_MAX = 120;
				if ( steps && typeof steps === 'object' ) {
					html += '<table class="wp-mcp-ai-sm-log-steps"><thead><tr><th>#</th><th>Tool</th><th>Duration</th><th>Result Preview</th></tr></thead><tbody>';
					$.each( steps, function ( idx, step ) {
						const num    = parseInt( idx, 10 ) + 1;
						const tool   = step.tool_slug || step.label || ( '#' + num );
						const dur    = step.duration ? parseFloat( step.duration ).toFixed( 3 ) + 's' : '—';
						const result = step.result !== undefined
							? ( typeof step.result === 'object' ? JSON.stringify( step.result ).slice( 0, RESULT_PREVIEW_MAX ) : String( step.result ).slice( 0, RESULT_PREVIEW_MAX ) )
							: '';
						html += '<tr><td>' + SM.esc( num ) + '</td><td>' + SM.esc( tool ) + '</td><td>' + SM.esc( dur ) + '</td><td>' + SM.esc( result ) + '</td></tr>';
					} );
					html += '</tbody></table>';
				} else {
					html += '<p><em>No step data.</em></p>';
				}
			} else if ( 'assistant_run' === type ) {
				const ast = log.assistant || {};
				html += '<p><strong>Assistant ID:</strong> ' + this.esc( String( ast.assistant_id || '' ) ) + '</p>';
				if ( ast.message ) {
					html += '<p><strong>Message:</strong> ' + this.esc( ast.message ) + '</p>';
				}
			} else if ( 'channel_broadcast' === type ) {
				const bc = log.broadcast || {};
				if ( bc.channels ) {
					html += '<p><strong>Channels:</strong> ' + this.esc( [].concat( bc.channels ).join( ', ' ) ) + '</p>';
				}
				if ( bc.message ) {
					html += '<p><strong>Message:</strong> ' + this.esc( bc.message ) + '</p>';
				}
				if ( bc.summary ) {
					html += '<p><strong>Sent:</strong> ' + this.esc( String( bc.summary.successful_channels || 0 ) ) +
						' / ' + this.esc( String( bc.summary.total_channels || 0 ) ) + ' channels</p>';
				}
			} else if ( 'workflow_builder' === type ) {
				html += '<p><strong>Workflow Builder ID:</strong> ' + this.esc( String( log.workflow_builder_id || '' ) ) + '</p>';
			} else {
				// Fallback: formatted JSON.
				html += '<pre class="wp-mcp-ai-sm-log-pre">' + this.esc( JSON.stringify( log, null, 2 ) ) + '</pre>';
			}

			html += '</div>';
			return html;
		},

		/** ------------------------------------------------------------------ *
		 *  Schedule Preset Browser
		 * ------------------------------------------------------------------ */

		/** Cached presets data */
		presets: [],

		/** Whether presets have been loaded */
		presetsLoaded: false,

		/** Initialise the preset browser */
		initPresetBrowser: function () {
			// Populate toolkit filter from known presets if the panel exists.
			if ( ! $( '#wp-mcp-ai-sm-presets-panel' ).length ) {
				return;
			}
		},

		/** Load presets via AJAX */
		loadPresets: function () {
			const self = this;
			const $grid = $( '#wp-mcp-ai-sm-presets-grid' );

			$grid.html( '<p>' + this.esc( wpMcpAiScheduleManager.strings.loading || 'Loading…' ) + '</p>' );

			this.ajax( 'wp_mcp_ai_sm_get_presets', {}, function ( err, data ) {
				if ( err ) {
					$grid.html( '<p class="wp-mcp-ai-sm-presets-empty">' + self.esc( err ) + '</p>' );
					return;
				}
				self.presets = data.presets || [];
				self.presetsLoaded = true;
				self.populateToolkitFilter();
				self.renderPresetGrid( self.presets );
			} );
		},

		/** Populate the toolkit filter dropdown from loaded presets */
		populateToolkitFilter: function () {
			const $sel = $( '#wp-mcp-ai-sm-preset-toolkit' );
			if ( ! $sel.length || ! this.presets.length ) {
				return;
			}

			const toolkits = {};
			$.each( this.presets, function ( _i, preset ) {
				if ( preset.toolkit && ! toolkits[ preset.toolkit ] ) {
					toolkits[ preset.toolkit ] = preset.toolkit.replace( /_/g, ' ' ).replace( /\b\w/g, function ( c ) {
						return c.toUpperCase();
					} );
				}
			} );

			$sel.find( 'option:not(:first)' ).remove();
			$.each( toolkits, function ( key, label ) {
				$sel.append( $( '<option>' ).val( key ).text( label ) );
			} );
		},

		/** Filter displayed presets based on category, toolkit, and search */
		filterPresets: function () {
			const category = $( '#wp-mcp-ai-sm-preset-category' ).val();
			const toolkit  = $( '#wp-mcp-ai-sm-preset-toolkit' ).val();
			const search   = ( $( '#wp-mcp-ai-sm-preset-search' ).val() || '' ).toLowerCase();

			const filtered = $.grep( this.presets, function ( preset ) {
				if ( category && preset.category !== category ) {
					return false;
				}
				if ( toolkit && preset.toolkit !== toolkit ) {
					return false;
				}
				if ( search ) {
					const haystack = ( preset.name + ' ' + preset.description + ' ' + ( preset.tags || [] ).join( ' ' ) ).toLowerCase();
					if ( haystack.indexOf( search ) === -1 ) {
						return false;
					}
				}
				return true;
			} );

			this.renderPresetGrid( filtered );
		},

		/** Render preset cards into the grid */
		renderPresetGrid: function ( presets ) {
			const self  = this;
			const $grid = $( '#wp-mcp-ai-sm-presets-grid' );
			const str   = wpMcpAiScheduleManager.strings;

			if ( ! presets.length ) {
				$grid.html( '<p class="wp-mcp-ai-sm-presets-empty">' + self.esc( str.presetNoResults || 'No presets match your filters.' ) + '</p>' );
				return;
			}

			let html = '';
			$.each( presets, function ( _i, preset ) {
				const icon   = preset.icon || 'dashicons-clock';
				const type   = self.typeLabel( preset.schedule_type || 'task' );
				const tags   = ( preset.tags || [] ).map( function ( t ) {
					return '<span class="wp-mcp-ai-sm-preset-tag">' + self.esc( t ) + '</span>';
				} ).join( '' );

				html += '<div class="wp-mcp-ai-sm-preset-card" data-category="' + self.esc( preset.category || '' ) + '" data-toolkit="' + self.esc( preset.toolkit || '' ) + '">';
				html += '<div class="wp-mcp-ai-sm-preset-card-header">';
				html += '<span class="dashicons ' + self.esc( icon ) + '"></span>';
				html += '<strong>' + self.esc( preset.name ) + '</strong>';
				html += '</div>';
				html += '<p class="wp-mcp-ai-sm-preset-desc">' + self.esc( preset.description ) + '</p>';
				html += '<div class="wp-mcp-ai-sm-preset-meta">';
				html += '<span class="wp-mcp-ai-sm-preset-type">' + self.esc( type ) + '</span>';
				html += '<span class="wp-mcp-ai-sm-preset-interval">' + self.esc( preset.schedule || 'daily' ) + '</span>';
				html += '</div>';
				if ( tags ) {
					html += '<div class="wp-mcp-ai-sm-preset-tags">' + tags + '</div>';
				}
				html += '<button type="button" class="button button-primary button-small" data-preset-install="' + self.esc( preset.id ) + '" data-preset-type="' + self.esc( preset.schedule_type || 'task' ) + '">';
				html += self.esc( str.presetInstall || 'Install' );
				html += '</button>';
				html += '</div>';
			} );

			$grid.html( html );
		},

		/** Human-readable type label */
		typeLabel: function ( type ) {
			const str = wpMcpAiScheduleManager.strings;
			const map = {
				task:              str.typeTask || 'Task',
				workflow:          str.typeWorkflow || 'Workflow',
				assistant_run:     str.typeAssistant || 'Assistant Run',
				channel_broadcast: str.typeBroadcast || 'Channel Broadcast',
				workflow_builder:  str.typeBuilder || 'Workflow Builder',
			};
			return map[ type ] || type;
		},

		/**
		 * Install a preset.
		 *
		 * For assistant_run presets the user is prompted to select an
		 * assistant.  For channel_broadcast presets the user is prompted to
		 * provide channel credentials JSON.  The collected values are sent
		 * as overrides alongside the preset_id.
		 *
		 * @param {string}  presetId  Preset identifier.
		 * @param {jQuery}  $btn      The install button element.
		 * @param {string}  type      Schedule type of the preset.
		 */
		installPreset: function ( presetId, $btn, type ) {
			const self = this;
			const str  = wpMcpAiScheduleManager.strings;
			const data = { preset_id: presetId };

			// --- Assistant Run: prompt for assistant selection ---------------
			if ( 'assistant_run' === type ) {
				const assistants = wpMcpAiScheduleManager.assistants || [];
				if ( ! assistants.length ) {
					// eslint-disable-next-line no-alert
					alert( str.presetNoAssistants || 'No assistants found. Please create an assistant first.' );
					return;
				}
				let prompt = ( str.presetSelectAssistant || 'Select an assistant for this schedule:' ) + '\n\n';
				$.each( assistants, function ( i, ast ) {
					prompt += ( i + 1 ) + ') ' + ast.title + ' (ID: ' + ast.id + ')\n';
				} );
				prompt += '\nEnter the assistant ID:';

				// eslint-disable-next-line no-alert
				const input = window.prompt( prompt );
				if ( null === input ) {
					return; // User cancelled.
				}
				const assistantId = parseInt( input.trim(), 10 );
				const validIds    = assistants.map( function ( a ) { return a.id; } );
				if ( ! assistantId || assistantId <= 0 || -1 === $.inArray( assistantId, validIds ) ) {
					// eslint-disable-next-line no-alert
					alert( str.presetInvalidAssistant || 'Please enter a valid assistant ID from the list above.' );
					return;
				}
				data.assistant_id = assistantId;
			}

			// --- Channel Broadcast: prompt for credentials JSON -------------
			if ( 'channel_broadcast' === type ) {
				// eslint-disable-next-line no-alert
				const credsInput = window.prompt(
					str.presetEnterCredentials || 'Enter channel credentials JSON for this broadcast schedule:'
				);
				if ( null === credsInput ) {
					return; // User cancelled.
				}
				const trimmed = credsInput.trim();
				if ( trimmed ) {
					try {
						JSON.parse( trimmed );
					} catch ( e ) {
						// eslint-disable-next-line no-alert
						alert( str.presetInvalidJson || 'Invalid JSON. Please enter valid channel credentials.' );
						return;
					}
					data.credentials = trimmed;
				}
			}

			// --- Confirm and send ------------------------------------------
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( str.presetConfirmInstall || 'Install this schedule preset?' ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( str.presetInstalling || 'Installing…' );

			this.ajax( 'wp_mcp_ai_sm_install_preset', data, function ( err ) {
				if ( err ) {
					// eslint-disable-next-line no-alert
					alert( err );
					$btn.prop( 'disabled', false ).text( str.presetInstall || 'Install' );
					return;
				}
				$btn.text( '✓ ' + ( str.presetInstalled || 'Installed' ) );
				self.loadSchedules();

				// Re-enable button after a short delay.
				setTimeout( function () {
					$btn.prop( 'disabled', false ).text( str.presetInstall || 'Install' );
				}, 2000 );
			} );
		},

		/** Safe HTML escape */
		esc: function ( str ) {
			if ( 'string' !== typeof str ) {
				str = String( str );
			}
			return str
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		},
	};

	$( function () {
		SM.init();
	} );

} )( jQuery );
