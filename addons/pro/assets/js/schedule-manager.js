/* global wpMcpAiScheduleManager, wp */
/**
 * Pro Schedule Manager — Admin UI
 *
 * Handles the full CRUD interface for pro-managed scheduled tasks,
 * workflows, and AI assistant runs in the NV oOS settings dashboard.
 *
 * @package WP_MCP_AI_Pro
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

			// Notify on failure toggle.
			$( document ).on( 'change', '#sm-notify-on-failure', function () {
				$( '#sm-notify-email-wrap' ).toggle( $( this ).is( ':checked' ) );
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

			$.post( wpMcpAiScheduleManager.ajaxUrl, data )
				.done( function ( response ) {
					if ( response.success ) {
						callback( null, response.data );
					} else {
						callback( ( response.data && response.data.message ) || wpMcpAiScheduleManager.strings.error );
					}
				} )
				.fail( function () {
					callback( wpMcpAiScheduleManager.strings.error );
				} );
		},

		/** ------------------------------------------------------------------ *
		 *  Schedule list
		 * ------------------------------------------------------------------ */
		loadSchedules: function () {
			const self   = this;
			const $tbody = $( '#wp-mcp-ai-sm-tbody' );

			$tbody.html(
				'<tr class="wp-mcp-ai-sm-loading-row"><td colspan="8"><span class="spinner is-active"></span> ' +
				wpMcpAiScheduleManager.strings.loading + '</td></tr>'
			);

			this.ajax( 'wp_mcp_ai_sm_get_schedules', {}, function ( err, data ) {
				if ( err ) {
					$tbody.html( '<tr><td colspan="8" class="wp-mcp-ai-sm-error">' + err + '</td></tr>' );
					return;
				}

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
			const typeLabel = s.schedule_type === 'workflow'
				? strings.typeWorkflow
				: ( s.schedule_type === 'assistant_run' ? strings.typeAssistant : strings.typeTask );

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
			row += '<strong>' + wp.escapeHtml( s.name ) + '</strong>';
			if ( s.description ) {
				row += '<p class="description">' + wp.escapeHtml( s.description ) + '</p>';
			}
			row += '</td>';

			// Type badge.
			row += '<td class="column-type">';
			row += '<span class="wp-mcp-ai-sm-badge wp-mcp-ai-sm-badge-' + s.schedule_type + '">';
			row += wp.escapeHtml( typeLabel );
			row += '</span></td>';

			// Interval.
			row += '<td class="column-schedule">' + wp.escapeHtml( s.schedule ) + '</td>';

			// Next run.
			row += '<td class="column-next-run">' + wp.escapeHtml( nextRun ) + '</td>';

			// Last status.
			row += '<td class="column-last-status">';
			row += '<span class="wp-mcp-ai-sm-status ' + statusClass + '">';
			row += wp.escapeHtml( statusLabel );
			if ( 'failure' === s.last_run_status && s.last_error ) {
				row += ' <abbr title="' + wp.escapeHtml( s.last_error ) + '">(?)</abbr>';
			}
			row += '</span>';
			if ( s.last_run_time ) {
				row += '<br><small>' + wp.escapeHtml( s.last_run_time ) + '</small>';
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

			// Actions.
			row += '<td class="column-actions">';
			row += '<button type="button" class="button button-small" data-sm-action="trigger" data-sm-id="' + s.id + '">' +
				'&#9654; Run</button> ';
			row += '<button type="button" class="button button-small" data-sm-action="edit" data-sm-id="' + s.id + '">' +
				'&#9998; Edit</button> ';
			row += '<button type="button" class="button button-small" data-sm-action="history" data-sm-id="' + s.id + '">' +
				'&#128203; History</button> ';
			row += '<button type="button" class="button button-small button-link-delete" data-sm-action="delete" data-sm-id="' + s.id + '">' +
				'&#10005; Delete</button>';
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

			$btn.prop( 'disabled', true ).text( strings.saving );
			$msg.text( '' ).removeClass( 'error success' );

			this.ajax(
				'wp_mcp_ai_sm_create_schedule',
				{ schedule: JSON.stringify( data ) },
				function ( err, result ) {
					$btn.prop( 'disabled', false ).text( strings.saved );
					setTimeout( function () { $btn.text( 'Create Schedule' ); }, 2000 );

					if ( err ) {
						$msg.text( err ).addClass( 'error' );
						return;
					}

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
			}

			return data;
		},

		resetCreateForm: function () {
			$( '#sm-name, #sm-description, #sm-hook, #sm-tags, #sm-assistant-message' ).val( '' );
			$( '#sm-priority' ).val( '5' );
			$( '#sm-max-retries' ).val( '0' );
			$( '#sm-retry-delay' ).val( '300' );
			$( '#sm-enabled' ).prop( 'checked', true );
			$( '#sm-notify-on-failure' ).prop( 'checked', false );
			$( '#sm-notify-email-wrap' ).hide();
			$( '#sm-workflow-steps' ).empty();
			$( '#sm-type' ).val( 'task' ).trigger( 'change' );
			$( '#sm-schedule' ).val( 'single' );
			$( '#sm-timestamp' ).val( '' );
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

			this.ajax(
				'wp_mcp_ai_sm_toggle_schedule',
				{ schedule_id: id, enabled: enabled ? '1' : '0' },
				function ( err ) {
					if ( err ) {
						alert( err );
						// Revert UI.
						$( '[data-sm-id="' + id + '"].wp-mcp-ai-sm-enable-toggle' ).prop( 'checked', ! enabled );
						return;
					}
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

			this.ajax(
				'wp_mcp_ai_sm_trigger_schedule',
				{ schedule_id: id },
				function ( err, _data ) {
					$row.removeClass( 'wp-mcp-ai-sm-running' );

					if ( err ) {
						alert( err );
						return;
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
		openHistoryModal: function ( id ) {
			this.activeId = id;

			const $modal = $( '#wp-mcp-ai-sm-history-modal' );
			const $body  = $( '#wp-mcp-ai-sm-history-body' );

			$body.html( '<span class="spinner is-active"></span>' );
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

					const rows = history.map( function ( entry ) {
						const cls = 'success' === entry.status ? 'wp-mcp-ai-sm-status-success' : 'wp-mcp-ai-sm-status-failure';
						const dur = entry.duration ? ' (' + parseFloat( entry.duration ).toFixed( 3 ) + 's)' : '';
						const errHtml = entry.error
							? '<br><small class="wp-mcp-ai-sm-error-text">' + wp.escapeHtml( entry.error ) + '</small>'
							: '';
						return '<tr><td class="' + cls + '">' +
							wp.escapeHtml( entry.status ) + '</td>' +
							'<td>' + wp.escapeHtml( entry.time ) + dur + errHtml + '</td></tr>';
					} );

					$body.html(
						'<table class="widefat striped"><thead><tr>' +
						'<th>Status</th><th>Time / Error</th></tr></thead><tbody>' +
						rows.join( '' ) + '</tbody></table>'
					);
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
