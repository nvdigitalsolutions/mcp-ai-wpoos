/**
 * Command Autocomplete for Slash Commands
 *
 * Provides autocomplete dropdown with fuzzy search and keyboard navigation.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
	'use strict';

	/**
	 * Command Autocomplete Class
	 */
	class CommandAutocomplete {
		constructor(inputElement) {
			this.input = inputElement;
			this.dropdown = null;
			this.commands = [];
			this.filteredCommands = [];
			this.selectedIndex = -1;
			this.visible = false;
		}

		/**
		 * Initialize autocomplete
		 */
		init() {
			this.createDropdown();
			this.loadCommands();
			console.log('[Autocomplete] Initialized');
		}

		/**
		 * Create dropdown element
		 */
		createDropdown() {
			this.dropdown = document.createElement('div');
			this.dropdown.className = 'mcp-slash-autocomplete';
			this.dropdown.style.cssText = `
				position: absolute;
				display: none;
				background: white;
				border: 1px solid #ccc;
				border-radius: 4px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.1);
				max-height: 300px;
				overflow-y: auto;
				z-index: 10000;
				min-width: 300px;
			`;

			// Insert after input
			this.input.parentNode.insertBefore(this.dropdown, this.input.nextSibling);
		}

		/**
		 * Load commands from cache or API
		 */
		async loadCommands() {
			if (window.slashCommands) {
				this.commands = await window.slashCommands.fetchCommands();
			} else {
				// Fallback to direct API call
				try {
					const endpoint = window.mcpAiData?.slashCommandListEndpoint;
					const nonce = window.mcpAiData?.nonce;

					const response = await fetch(endpoint, {
						headers: {
							'X-WP-Nonce': nonce
						}
					});

					if (response.ok) {
						const data = await response.json();
						this.commands = data.commands || [];
					}
				} catch (error) {
					console.error('[Autocomplete] Failed to load commands:', error);
				}
			}
		}

		/**
		 * Show autocomplete dropdown
		 */
		show(input) {
			if (this.commands.length === 0) {
				this.loadCommands();
				return;
			}

			const query = input.slice(1).toLowerCase(); // Remove leading slash
			this.filteredCommands = this.fuzzyFilter(query);

			if (this.filteredCommands.length === 0) {
				this.hide();
				return;
			}

			this.render();
			this.position();
			this.dropdown.style.display = 'block';
			this.visible = true;
			this.selectedIndex = 0;
		}

		/**
		 * Hide autocomplete dropdown
		 */
		hide() {
			this.dropdown.style.display = 'none';
			this.visible = false;
			this.selectedIndex = -1;
		}

		/**
		 * Check if dropdown is visible
		 */
		isVisible() {
			return this.visible;
		}

		/**
		 * Fuzzy filter commands
		 */
		fuzzyFilter(query) {
			if (!query) {
				return this.commands.slice(0, 10); // Show first 10 if no query
			}

			return this.commands.filter(cmd => {
				const name = cmd.name.toLowerCase();
				const desc = (cmd.description || '').toLowerCase();
				const aliases = (cmd.aliases || []).join(' ').toLowerCase();

				// Simple fuzzy match
				const searchText = name + ' ' + desc + ' ' + aliases;
				return searchText.includes(query) || this.fuzzyMatch(name, query);
			}).slice(0, 10);
		}

		/**
		 * Fuzzy match algorithm
		 */
		fuzzyMatch(str, pattern) {
			let patternIdx = 0;
			let strIdx = 0;

			while (patternIdx < pattern.length && strIdx < str.length) {
				if (str[strIdx].toLowerCase() === pattern[patternIdx].toLowerCase()) {
					patternIdx++;
				}
				strIdx++;
			}

			return patternIdx === pattern.length;
		}

		/**
		 * Render dropdown content
		 */
		render() {
			this.dropdown.innerHTML = '';

			this.filteredCommands.forEach((cmd, index) => {
				const item = document.createElement('div');
				item.className = 'mcp-slash-autocomplete-item';
				item.style.cssText = `
					padding: 8px 12px;
					cursor: pointer;
					border-bottom: 1px solid #f0f0f0;
				`;

				if (index === this.selectedIndex) {
					item.style.backgroundColor = '#f5f5f5';
				}

				// Command name
				const name = document.createElement('div');
				name.style.fontWeight = 'bold';
				name.textContent = '/' + cmd.name;

				// Description
				const desc = document.createElement('div');
				desc.style.cssText = 'font-size: 12px; color: #666; margin-top: 2px;';
				desc.textContent = cmd.description || '';

				// Aliases
				if (cmd.aliases && cmd.aliases.length > 0) {
					const aliases = document.createElement('div');
					aliases.style.cssText = 'font-size: 11px; color: #999; margin-top: 2px;';
					aliases.textContent = 'Aliases: /' + cmd.aliases.join(', /');
					item.appendChild(name);
					item.appendChild(desc);
					item.appendChild(aliases);
				} else {
					item.appendChild(name);
					item.appendChild(desc);
				}

				// Click handler
				item.addEventListener('click', () => {
					this.selectCommand(cmd);
				});

				// Hover handler
				item.addEventListener('mouseenter', () => {
					this.selectedIndex = index;
					this.render();
				});

				this.dropdown.appendChild(item);
			});
		}

		/**
		 * Position dropdown near input
		 */
		position() {
			const rect = this.input.getBoundingClientRect();
			this.dropdown.style.position = 'fixed';
			this.dropdown.style.left = rect.left + 'px';
			this.dropdown.style.top = (rect.bottom + 4) + 'px';
			this.dropdown.style.width = Math.max(300, rect.width) + 'px';
		}

		/**
		 * Handle keyboard navigation
		 */
		handleKeyDown(e) {
			if (!this.visible) {
				return false;
			}

			switch (e.key) {
				case 'ArrowDown':
					this.selectedIndex = Math.min(this.selectedIndex + 1, this.filteredCommands.length - 1);
					this.render();
					return true;

				case 'ArrowUp':
					this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
					this.render();
					return true;

				case 'Enter':
					if (this.selectedIndex >= 0 && this.selectedIndex < this.filteredCommands.length) {
						this.selectCommand(this.filteredCommands[this.selectedIndex]);
						return true;
					}
					break;

				case 'Tab':
					if (this.selectedIndex >= 0 && this.selectedIndex < this.filteredCommands.length) {
						this.selectCommand(this.filteredCommands[this.selectedIndex]);
						return true;
					}
					break;

				case 'Escape':
					this.hide();
					return true;
			}

			return false;
		}

		/**
		 * Select a command
		 */
		selectCommand(cmd) {
			// Insert command into input
			this.input.value = '/' + cmd.name + ' ';
			this.input.focus();

			// Position cursor at end
			const length = this.input.value.length;
			this.input.setSelectionRange(length, length);

			this.hide();
		}
	}

	// Export for external use
	window.CommandAutocomplete = CommandAutocomplete;

})();
