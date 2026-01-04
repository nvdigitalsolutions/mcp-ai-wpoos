/**
 * AI Actions for Project Management Admin
 *
 * Handles AI-assisted features in the project management admin interface.
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// Handle AI action button clicks
		$('.wp-mcp-ai-pm-ai-btn').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var action = $button.data('action');
			var postId = $button.data('post-id');
			var $container = $button.closest('.wp-mcp-ai-pm-ai-actions');
			var $result = $container.find('.wp-mcp-ai-pm-ai-result');
			var $resultContent = $container.find('.wp-mcp-ai-pm-ai-result-content');
			var $loading = $container.find('.wp-mcp-ai-pm-ai-loading');
			
			// Get title from the editor
			var title = $('#title').val();
			if (!title) {
				alert(wpMcpAiPmAi.strings.noTitle);
				return;
			}
			
			// Hide previous results
			$result.hide();
			
			// Show loading
			$loading.show();
			$button.prop('disabled', true);
			
			// Prepare data
			var data = {
				action: 'wp_mcp_ai_pm_' + action,
				nonce: wpMcpAiPmAi.nonce,
				post_id: postId,
				title: title
			};
			
			// Add description for relevant actions
			if (action === 'suggest_tasks' || action === 'analyze_project') {
				var description = '';
				if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
					description = tinymce.get('content').getContent();
				} else {
					description = $('#content').val();
				}
				data.description = description;
			}
			
			// Make AJAX request
			$.post(wpMcpAiPmAi.ajaxUrl, data, function(response) {
				$loading.hide();
				$button.prop('disabled', false);
				
				if (response.success) {
					handleSuccess(action, response.data, $resultContent, $result);
				} else {
					showError(response.data.message, $resultContent, $result);
				}
			}).fail(function() {
				$loading.hide();
				$button.prop('disabled', false);
				showError(wpMcpAiPmAi.strings.error, $resultContent, $result);
			});
		});
		
		/**
		 * Handle successful AI response
		 */
		function handleSuccess(action, data, $resultContent, $result) {
			switch(action) {
				case 'generate_description':
					if (data.description) {
						insertIntoEditor(data.description);
						$resultContent.html('<strong>' + wpMcpAiPmAi.strings.applied + '</strong>');
						$result.find('.notice').removeClass('notice-error').addClass('notice-success');
						$result.show();
						
						// Hide after 3 seconds
						setTimeout(function() {
							$result.fadeOut();
						}, 3000);
					}
					break;
					
				case 'suggest_tasks':
					if (data.tasks && data.tasks.length > 0) {
						var html = '<strong>' + wpMcpAiPmAi.strings.viewTasks + '</strong><br>';
						html += '<ol style="margin: 10px 0; padding-left: 20px;">';
						data.tasks.forEach(function(task) {
							html += '<li style="margin: 5px 0;">' + escapeHtml(task) + '</li>';
						});
						html += '</ol>';
						html += '<button type="button" class="button button-small wp-mcp-ai-create-tasks" style="margin-top: 5px;">Create These Tasks</button>';
						
						$resultContent.html(html);
						$result.find('.notice').removeClass('notice-error').addClass('notice-info');
						$result.show();
						
						// Store tasks data for creation
						$result.data('tasks', data.tasks);
					}
					break;
					
				case 'analyze_project':
					if (data.analysis) {
						var html = '<strong>Project Analysis:</strong><br>' + escapeHtml(data.analysis).replace(/\n/g, '<br>');
						$resultContent.html(html);
						$result.find('.notice').removeClass('notice-error').addClass('notice-info');
						$result.show();
					}
					break;
					
				case 'estimate_time':
					if (data.estimate) {
						$resultContent.html('<strong>Estimated Duration:</strong> ' + escapeHtml(data.estimate));
						$result.find('.notice').removeClass('notice-error').addClass('notice-info');
						$result.show();
					}
					break;
					
				case 'suggest_agenda':
					if (data.agenda) {
						insertIntoEditor(data.agenda);
						$resultContent.html('<strong>' + wpMcpAiPmAi.strings.applied + '</strong>');
						$result.find('.notice').removeClass('notice-error').addClass('notice-success');
						$result.show();
						
						setTimeout(function() {
							$result.fadeOut();
						}, 3000);
					}
					break;
			}
		}
		
		/**
		 * Show error message
		 */
		function showError(message, $resultContent, $result) {
			$resultContent.html('<strong>Error:</strong> ' + escapeHtml(message));
			$result.find('.notice').removeClass('notice-success notice-info').addClass('notice-error');
			$result.show();
		}
		
		/**
		 * Insert content into the WordPress editor
		 */
		function insertIntoEditor(content) {
			if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
				tinymce.get('content').setContent(content);
			} else {
				$('#content').val(content);
			}
		}
		
		/**
		 * Escape HTML for safe display
		 */
		function escapeHtml(text) {
			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, function(m) { return map[m]; });
		}
		
		/**
		 * Handle create tasks button click
		 */
		$(document).on('click', '.wp-mcp-ai-create-tasks', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $result = $button.closest('.wp-mcp-ai-pm-ai-result');
			var tasks = $result.data('tasks');
			var projectId = $('.wp-mcp-ai-pm-ai-btn').first().data('post-id');
			
			if (!tasks || tasks.length === 0) {
				return;
			}
			
			$button.prop('disabled', true).text('Creating...');
			
			// Create tasks using the AI tool
			createTasksBatch(tasks, projectId, function(success) {
				if (success) {
					$button.text('Tasks Created!').css('background-color', '#00a32a');
					setTimeout(function() {
						$result.fadeOut();
					}, 2000);
				} else {
					$button.prop('disabled', false).text('Create These Tasks');
					alert('Failed to create some tasks. Please try again.');
				}
			});
		});
		
		/**
		 * Create multiple tasks via AJAX
		 */
		function createTasksBatch(tasks, projectId, callback) {
			var created = 0;
			var failed = 0;
			
			tasks.forEach(function(taskTitle, index) {
				setTimeout(function() {
					$.post(ajaxurl, {
						action: 'wp_mcp_ai_pm_create_task',
						nonce: wpMcpAiPmAi.nonce,
						title: taskTitle,
						project_id: projectId,
						status: 'todo',
						priority: 'medium'
					}, function(response) {
						if (response.success) {
							created++;
						} else {
							failed++;
						}
						
						if (created + failed === tasks.length) {
							callback(failed === 0);
						}
					});
				}, index * 500); // Stagger requests
			});
		}
	});
})(jQuery);
