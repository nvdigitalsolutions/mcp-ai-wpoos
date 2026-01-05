/**
 * AI Actions for Project Management Admin
 *
 * Handles AI-assisted features in the project management admin interface.
 * Uses modern WordPress data store subscription for block editor compatibility.
 */
(function($) {
	'use strict';

	// Unconditional debug output to verify script loads
	console.log('[PM AI Actions] Script file loaded at:', new Date().toISOString());

	/**
	 * Check if the block editor (Gutenberg) is active.
	 *
	 * @return {boolean} True if block editor is active.
	 */
	function isBlockEditorActive() {
		// Check if wp.data and editor store exist (block editor indicators).
		// Wrap in try-catch to handle potential exceptions from wp.data.select().
		try {
			return typeof wp !== 'undefined' && 
				   wp.data && 
				   typeof wp.data.select === 'function' &&
				   wp.data.select('core/editor') !== undefined;
		} catch (error) {
			console.log('[PM AI Actions] Block editor detection failed:', error);
			return false;
		}
	}

	/**
	 * Wait for metabox elements using WordPress data store subscription (modern approach).
	 * Falls back to polling for classic editor or when data store is not available.
	 *
	 * @param {Function} callback - Function to call when metabox is ready.
	 */
	function waitForMetabox(callback) {
		const $buttons = $('.wp-mcp-ai-pm-ai-btn');
		const $container = $('.wp-mcp-ai-pm-ai-actions');

		// If elements already exist, initialize immediately
		if ($buttons.length && $container.length) {
			console.log('[PM AI Actions] ✓ Elements found immediately, initializing');
			callback();
			return;
		}

		// For block editor, use data store subscription if available
		if (isBlockEditorActive() && typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
			console.log('[PM AI Actions] Using data store subscription for metabox initialization');
			
			let unsubscribe = null;
			let initialized = false;

			// Subscribe to data store changes
			unsubscribe = wp.data.subscribe(function() {
				// Prevent duplicate initialization
				if (initialized) {
					return;
				}

				const $buttons = $('.wp-mcp-ai-pm-ai-btn');
				const $container = $('.wp-mcp-ai-pm-ai-actions');

				if ($buttons.length && $container.length) {
					console.log('[PM AI Actions] ✓ Elements found via data store subscription');
					initialized = true;
					
					// Unsubscribe to prevent further checks
					if (unsubscribe) {
						unsubscribe();
					}
					
					callback();
				}
			});

			// Fallback timeout in case subscription doesn't work (10 seconds)
			setTimeout(function() {
				if (!initialized && unsubscribe) {
					console.warn('[PM AI Actions] Data store subscription timeout, unsubscribing');
					unsubscribe();
				}
			}, 10000);
		} else {
			// Fallback: Simple polling for classic editor
			console.log('[PM AI Actions] Using fallback polling for classic editor');
			
			let attempts = 0;
			const maxAttempts = 30;
			const pollInterval = 200;

			const pollTimer = setInterval(function() {
				attempts++;
				
				const $buttons = $('.wp-mcp-ai-pm-ai-btn');
				const $container = $('.wp-mcp-ai-pm-ai-actions');

				console.log('[PM AI Actions] Polling attempt ' + attempts + '/' + maxAttempts);

				if ($buttons.length && $container.length) {
					console.log('[PM AI Actions] ✓ Elements found after ' + attempts + ' attempts');
					clearInterval(pollTimer);
					callback();
				} else if (attempts >= maxAttempts) {
					console.error('[PM AI Actions] TIMEOUT: Elements not found after ' + maxAttempts + ' attempts');
					clearInterval(pollTimer);
				}
			}, pollInterval);
		}
	}

	/**
	 * Initialize AI action button handlers.
	 */
	function initPmAiActions() {
		console.log('[PM AI Actions] initPmAiActions() function called');

		const $buttons = $('.wp-mcp-ai-pm-ai-btn');
		const $container = $('.wp-mcp-ai-pm-ai-actions');

		console.log('[PM AI Actions] Element search results:', {
			buttons: $buttons.length,
			container: $container.length
		});

		if (!$buttons.length || !$container.length) {
			console.error('[PM AI Actions] CRITICAL: Required elements not found - initialization aborted');
			return;
		}

		// Log successful initialization
		console.log('[PM AI Actions] ✓ Initialization successful, all elements found');

		// Handle AI action button clicks
		$('.wp-mcp-ai-pm-ai-btn').on('click', function(e) {
			e.preventDefault();
			
			const $button = $(this);
			const action = $button.data('action');
			const postId = $button.data('post-id');
			const $container = $button.closest('.wp-mcp-ai-pm-ai-actions');
			const $result = $container.find('.wp-mcp-ai-pm-ai-result');
			const $resultContent = $container.find('.wp-mcp-ai-pm-ai-result-content');
			const $loading = $container.find('.wp-mcp-ai-pm-ai-loading');
			
			// Get title from the editor - try multiple selectors for compatibility
			let title = $('#title').val() || $('#post-title-0').val() || $('input[name="post_title"]').val() || '';
			title = $.trim(title);
			
			// Debug logging to help troubleshoot title detection
			if (window.console && console.log) {
				console.log('[PM AI Actions] Title detection:', {
					classic: $('#title').val(),
					block: $('#post-title-0').val(),
					generic: $('input[name="post_title"]').val(),
					final: title
				});
			}
			
			if (!title) {
				alert(wpMcpAiPmAi.strings.noTitle);
				$('#title, #post-title-0, input[name="post_title"]').first().focus();
				return;
			}
			
			// Hide previous results
			$result.hide();
			
			// Show loading
			$loading.show();
			$button.prop('disabled', true);
			
			// Prepare data
			const data = {
				action: 'wp_mcp_ai_pm_' + action,
				nonce: wpMcpAiPmAi.nonce,
				post_id: postId,
				title: title
			};
			
			// Add description for relevant actions
			if (action === 'suggest_tasks' || action === 'analyze_project') {
				let description = '';
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
						let html = '<strong>' + wpMcpAiPmAi.strings.viewTasks + '</strong><br>';
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
						const html = '<strong>Project Analysis:</strong><br>' + escapeHtml(data.analysis).replace(/\n/g, '<br>');
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
		const map = {
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
		
		const $button = $(this);
		const $result = $button.closest('.wp-mcp-ai-pm-ai-result');
		const tasks = $result.data('tasks');
		const projectId = $('.wp-mcp-ai-pm-ai-btn').first().data('post-id');
		
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
		let created = 0;
		let failed = 0;
		
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

	// Determine initialization approach based on editor context
	console.log('[PM AI Actions] Determining initialization approach...');
	console.log('[PM AI Actions] wp object available?', typeof wp !== 'undefined');
	console.log('[PM AI Actions] wp.domReady available?', typeof wp !== 'undefined' && typeof wp.domReady !== 'undefined');
	console.log('[PM AI Actions] wp.data available?', typeof wp !== 'undefined' && typeof wp.data !== 'undefined');

	// Modern approach: Use wp.domReady or document.ready, then wait for metabox
	// The waitForMetabox function will use data store subscription for block editor
	if (typeof wp !== 'undefined' && wp.domReady) {
		console.log('[PM AI Actions] Using wp.domReady hook');
		wp.domReady(function() {
			console.log('[PM AI Actions] ⚡ wp.domReady fired');
			waitForMetabox(function() {
				try {
					initPmAiActions();
					console.log('[PM AI Actions] ✓ Initialization complete');
				} catch (error) {
					console.error('[PM AI Actions] CRITICAL: Initialization failed:', error);
				}
			});
		});
	} else {
		// Fallback for classic editor or older WordPress
		console.log('[PM AI Actions] Using document.ready fallback');
		$(document).ready(function() {
			console.log('[PM AI Actions] ⚡ Document ready event fired');
			waitForMetabox(function() {
				try {
					initPmAiActions();
					console.log('[PM AI Actions] ✓ Initialization complete');
				} catch (error) {
					console.error('[PM AI Actions] CRITICAL: Initialization failed:', error);
				}
			});
		});
	}
})(jQuery);
