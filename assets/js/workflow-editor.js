/**
 * Workflow Editor JavaScript
 *
 * @package WP_MCP_AI
 * @since 1.3.0
 */

(function($) {
	'use strict';

	var WorkflowEditor = {
		currentWorkflow: null,
		steps: [],

		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			// New workflow button
			$('#mcp-ai-new-workflow').on('click', function() {
				self.showNewWorkflowForm();
			});

			// Workflow item click
			$(document).on('click', '.mcp-ai-workflow-item', function() {
				var workflowSlug = $(this).data('workflow');
				$('.mcp-ai-workflow-item').removeClass('active');
				$(this).addClass('active');
				self.loadWorkflow(workflowSlug);
			});

			// Edit workflow
			$(document).on('click', '.workflow-edit', function(e) {
				e.stopPropagation();
				var workflowSlug = $(this).data('workflow');
				self.loadWorkflow(workflowSlug);
			});

			// Test workflow
			$(document).on('click', '.workflow-test', function(e) {
				e.stopPropagation();
				var workflowSlug = $(this).data('workflow');
				self.testWorkflow(workflowSlug);
			});

			// Delete workflow
			$(document).on('click', '.workflow-delete', function(e) {
				e.stopPropagation();
				var workflowSlug = $(this).data('workflow');
				self.deleteWorkflow(workflowSlug);
			});

			// Add step
			$(document).on('click', '.mcp-ai-add-step-button', function() {
				self.addStep();
			});

			// Remove step
			$(document).on('click', '.mcp-ai-workflow-step-remove', function() {
				var stepIndex = $(this).closest('.mcp-ai-workflow-step').data('index');
				self.removeStep(stepIndex);
			});

			// Save workflow
			$(document).on('click', '#mcp-ai-save-workflow', function() {
				self.saveWorkflow();
			});

			// Cancel edit
			$(document).on('click', '#mcp-ai-cancel-workflow', function() {
				self.showWelcomeMessage();
			});
		},

		showWelcomeMessage: function() {
			$('#mcp-ai-workflow-editor-content').html(
				'<div class="mcp-ai-welcome-message">' +
				'<h2>' + mcpAiWorkflowEditor.strings.welcome + '</h2>' +
				'<p>' + mcpAiWorkflowEditor.strings.welcomeDesc + '</p>' +
				'</div>'
			);
			this.currentWorkflow = null;
			this.steps = [];
		},

		showNewWorkflowForm: function() {
			this.currentWorkflow = null;
			this.steps = [];
			this.renderWorkflowForm();
		},

		loadWorkflow: function(workflowSlug) {
			var workflow = mcpAiWorkflowEditor.workflows[workflowSlug];
			if (!workflow) {
				alert('Workflow not found');
				return;
			}

			this.currentWorkflow = workflowSlug;
			this.steps = workflow.steps || [];
			this.renderWorkflowForm(workflow);
		},

		renderWorkflowForm: function(workflow) {
			var self = this;
			var html = '<div class="mcp-ai-workflow-form">';
			
			// Workflow name
			html += '<div class="form-row">';
			html += '<label for="workflow-name">Workflow Name</label>';
			html += '<input type="text" id="workflow-name" class="regular-text" value="' + (workflow ? workflow.name : '') + '" />';
			html += '</div>';

			// Workflow description
			html += '<div class="form-row">';
			html += '<label for="workflow-description">Description</label>';
			html += '<textarea id="workflow-description" class="large-text">' + (workflow ? workflow.description : '') + '</textarea>';
			html += '</div>';

			// Workflow steps
			html += '<div class="form-row">';
			html += '<label>Workflow Steps</label>';
			html += '<div class="mcp-ai-workflow-steps" id="workflow-steps">';
			html += this.renderSteps();
			html += '</div>';
			html += '<button type="button" class="button mcp-ai-add-step-button">Add Step</button>';
			html += '</div>';

			// Actions
			html += '<div class="mcp-ai-workflow-actions">';
			html += '<button type="button" class="button button-primary" id="mcp-ai-save-workflow">Save Workflow</button>';
			html += '<button type="button" class="button" id="mcp-ai-cancel-workflow">Cancel</button>';
			html += '</div>';

			html += '</div>';

			$('#mcp-ai-workflow-editor-content').html(html);
		},

		renderSteps: function() {
			var html = '';
			for (var i = 0; i < this.steps.length; i++) {
				html += this.renderStep(i, this.steps[i]);
			}
			return html;
		},

		renderStep: function(index, step) {
			var html = '<div class="mcp-ai-workflow-step" data-index="' + index + '">';
			html += '<div class="mcp-ai-workflow-step-header">';
			html += '<span class="mcp-ai-workflow-step-number">' + (index + 1) + '</span>';
			html += '<span class="mcp-ai-workflow-step-title">Step ' + (index + 1) + '</span>';
			html += '<span class="mcp-ai-workflow-step-remove dashicons dashicons-no-alt"></span>';
			html += '</div>';
			
			html += '<div class="mcp-ai-workflow-step-content">';
			html += '<label>Command</label>';
			html += '<input type="text" class="regular-text step-command" value="' + (step.command || '') + '" placeholder="/command-name" />';
			html += '<label style="margin-top: 10px;">Parameters (JSON)</label>';
			html += '<textarea class="large-text step-params" rows="3">' + (step.params ? JSON.stringify(step.params) : '{}') + '</textarea>';
			html += '</div>';
			
			html += '</div>';
			return html;
		},

		addStep: function() {
			this.steps.push({
				command: '',
				params: {}
			});
			
			var html = this.renderStep(this.steps.length - 1, this.steps[this.steps.length - 1]);
			$('#workflow-steps').append(html);
		},

		removeStep: function(index) {
			this.steps.splice(index, 1);
			this.renderWorkflowForm({
				name: $('#workflow-name').val(),
				description: $('#workflow-description').val()
			});
		},

		saveWorkflow: function() {
			var self = this;
			var name = $('#workflow-name').val().trim();
			var description = $('#workflow-description').val().trim();

			if (!name) {
				alert('Please enter a workflow name');
				return;
			}

			// Collect steps
			var steps = [];
			$('.mcp-ai-workflow-step').each(function() {
				var command = $(this).find('.step-command').val().trim();
				var paramsText = $(this).find('.step-params').val().trim();
				var params = {};
				
				try {
					params = paramsText ? JSON.parse(paramsText) : {};
				} catch (e) {
					alert('Invalid JSON in step ' + ($(this).data('index') + 1));
					return false;
				}

				if (command) {
					steps.push({
						command: command.replace(/^\//, ''),
						params: params
					});
				}
			});

			if (steps.length === 0) {
				alert('Please add at least one step');
				return;
			}

			// Show loading
			$('#mcp-ai-save-workflow').addClass('mcp-ai-loading').prop('disabled', true);

			// Save workflow
			$.ajax({
				url: mcpAiWorkflowEditor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_save_workflow',
					nonce: mcpAiWorkflowEditor.nonce,
					name: name,
					description: description,
					steps: JSON.stringify(steps)
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert(response.data.message || 'Error saving workflow');
					}
				},
				error: function() {
					alert('Network error. Please try again.');
				},
				complete: function() {
					$('#mcp-ai-save-workflow').removeClass('mcp-ai-loading').prop('disabled', false);
				}
			});
		},

		deleteWorkflow: function(workflowSlug) {
			if (!confirm('Are you sure you want to delete this workflow?')) {
				return;
			}

			$.ajax({
				url: mcpAiWorkflowEditor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_delete_workflow',
					nonce: mcpAiWorkflowEditor.nonce,
					workflow: workflowSlug
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert(response.data.message || 'Error deleting workflow');
					}
				},
				error: function() {
					alert('Network error. Please try again.');
				}
			});
		},

		testWorkflow: function(workflowSlug) {
			var paramsStr = prompt('Enter test parameters (JSON):', '{}');
			if (paramsStr === null) {
				return;
			}

			var params = {};
			try {
				params = JSON.parse(paramsStr);
			} catch (e) {
				alert('Invalid JSON');
				return;
			}

			$.ajax({
				url: mcpAiWorkflowEditor.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_mcp_ai_test_workflow',
					nonce: mcpAiWorkflowEditor.nonce,
					workflow: workflowSlug,
					params: JSON.stringify(params)
				},
				success: function(response) {
					if (response.success) {
						console.log('Workflow test result:', response.data.result);
						alert(response.data.message + '\n\nCheck console for details.');
					} else {
						console.error('Workflow test failed:', response.data.result);
						alert(response.data.message);
					}
				},
				error: function() {
					alert('Network error. Please try again.');
				}
			});
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		WorkflowEditor.init();
	});

})(jQuery);
