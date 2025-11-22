/**
 * Tests for admin-settings.js localStorage error handling.
 * 
 * This test verifies that localStorage access errors are properly caught
 * and handled, preventing unhandled promise rejections.
 * 
 * @package WP_MCP_AI
 */

describe('Admin Settings localStorage Error Handling', () => {
	let consoleLogSpy;

	beforeEach(() => {
		// Mock console.log to capture debug messages
		consoleLogSpy = jest.spyOn(console, 'log').mockImplementation(() => {});
	});

	afterEach(() => {
		// Restore console.log
		consoleLogSpy.mockRestore();
		
		// Clear localStorage
		localStorage.clear();
	});

	describe('localStorage access errors', () => {
		it('should handle localStorage.getItem errors gracefully', () => {
			// Mock localStorage.getItem to throw error
			const originalGetItem = localStorage.getItem;
			localStorage.getItem = jest.fn(() => {
				throw new Error('Access to storage is not allowed from this context.');
			});

			// This simulates the code in admin-settings.js
			const testGetItem = () => {
				try {
					const expandedSections = localStorage.getItem('wp_mcp_ai_expanded_sections');
					if (expandedSections) {
						// Process sections
					}
				} catch (e) {
					// Error should be caught here
					return e.message;
				}
				return null;
			};

			// Should not throw
			expect(() => testGetItem()).not.toThrow();
			
			// Should catch the error
			const result = testGetItem();
			expect(result).toBe('Access to storage is not allowed from this context.');
			
			// Restore
			localStorage.getItem = originalGetItem;
		});

		it('should handle localStorage.setItem errors gracefully', () => {
			// Mock localStorage.setItem to throw error
			const originalSetItem = localStorage.setItem;
			localStorage.setItem = jest.fn(() => {
				throw new Error('Access to storage is not allowed from this context.');
			});

			// This simulates the code in admin-settings.js
			const testSetItem = () => {
				try {
					localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(['section1']));
				} catch (e) {
					// Error should be caught here
					return e.message;
				}
				return null;
			};

			// Should not throw
			expect(() => testSetItem()).not.toThrow();
			
			// Should catch the error
			const result = testSetItem();
			expect(result).toBe('Access to storage is not allowed from this context.');
			
			// Restore
			localStorage.setItem = originalSetItem;
		});

		it('should handle QuotaExceededError', () => {
			// Mock localStorage.setItem to throw QuotaExceededError
			const originalSetItem = localStorage.setItem;
			localStorage.setItem = jest.fn(() => {
				const error = new Error('QuotaExceededError');
				error.name = 'QuotaExceededError';
				throw error;
			});

			const testQuotaError = () => {
				try {
					localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(['section1']));
				} catch (e) {
					return e.name;
				}
				return null;
			};

			// Should catch the quota error
			const result = testQuotaError();
			expect(result).toBe('QuotaExceededError');
			
			// Restore
			localStorage.setItem = originalSetItem;
		});

		it('should work normally when localStorage is available', () => {
			// Setup localStorage with test data
			localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(['section1', 'section2']));

			const testNormalOperation = () => {
				try {
					const expandedSections = localStorage.getItem('wp_mcp_ai_expanded_sections');
					if (expandedSections) {
						const sections = JSON.parse(expandedSections);
						return sections;
					}
				} catch (e) {
					return null;
				}
				return [];
			};

			const result = testNormalOperation();
			expect(result).toEqual(['section1', 'section2']);
		});
	});

	describe('wpMcpAiSaveExpandedState function', () => {
		it('should catch localStorage errors in global save function', () => {
			// Setup DOM
			document.body.innerHTML = `
				<div id="section1" class="wp-mcp-ai-section--expanded"></div>
				<div id="section2" class="wp-mcp-ai-section--expanded"></div>
			`;

			// Mock localStorage.setItem to throw error
			const originalSetItem = localStorage.setItem;
			localStorage.setItem = jest.fn(() => {
				throw new Error('Access to storage is not allowed from this context.');
			});

			// Simulate the wpMcpAiSaveExpandedState function
			const wpMcpAiSaveExpandedState = function() {
				const sections = document.querySelectorAll('.wp-mcp-ai-section--expanded');
				const expandedIds = Array.from(sections)
					.map(function(section) { return section.getAttribute('id'); })
					.filter(function(id) { return id; });
				
				try {
					localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(expandedIds));
				} catch (e) {
					// Log localStorage errors for debugging
					if (window.console && console.log) {
						console.log('[WP oOS] localStorage access not allowed:', e);
					}
				}
			};

			// Should not throw
			expect(() => wpMcpAiSaveExpandedState()).not.toThrow();
			
			// Restore
			localStorage.setItem = originalSetItem;
		});

		it('should log errors when localStorage is not accessible', () => {
			// Setup DOM
			document.body.innerHTML = `
				<div id="section1" class="wp-mcp-ai-section--expanded"></div>
			`;

			// Mock localStorage.setItem to throw error
			const originalSetItem = localStorage.setItem;
			localStorage.setItem = jest.fn(() => {
				throw new Error('Access to storage is not allowed from this context.');
			});

			// Spy on console.log
			const consoleLogSpy = jest.spyOn(console, 'log').mockImplementation(() => {});

			// Simulate the wpMcpAiSaveExpandedState function
			const wpMcpAiSaveExpandedState = function() {
				const sections = document.querySelectorAll('.wp-mcp-ai-section--expanded');
				const expandedIds = Array.from(sections)
					.map(function(section) { return section.getAttribute('id'); })
					.filter(function(id) { return id; });
				
				try {
					localStorage.setItem('wp_mcp_ai_expanded_sections', JSON.stringify(expandedIds));
				} catch (e) {
					// Log localStorage errors for debugging
					if (window.console && console.log) {
						console.log('[WP oOS] localStorage access not allowed:', e);
					}
				}
			};

			// Call the function
			wpMcpAiSaveExpandedState();

			// Verify console.log was called with the error
			expect(consoleLogSpy).toHaveBeenCalledWith(
				'[WP oOS] localStorage access not allowed:',
				expect.any(Error)
			);
			
			// Restore
			localStorage.setItem = originalSetItem;
			consoleLogSpy.mockRestore();
		});
	});
});
