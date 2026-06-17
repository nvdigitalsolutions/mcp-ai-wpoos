/**
 * Tests for professional selector modal chat initialization.
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

describe( 'Professional selector modal init', () => {
	let $;
	let ajaxMock;
	let chatInitSpy;

	class JQueryCollection {
		constructor( nodes ) {
			this.nodes = nodes || [];
			this.length = this.nodes.length;
			this.nodes.forEach( ( node, index ) => {
				this[ index ] = node;
			} );
		}

		each( callback ) {
			this.nodes.forEach( ( node, index ) => {
				callback.call( node, index, node );
			} );
			return this;
		}

		attr( name, value ) {
			if ( value !== undefined ) {
				this.nodes.forEach( ( node ) => {
					node.setAttribute( name, value );
				} );
				return this;
			}
			return this.length ? this.nodes[ 0 ].getAttribute( name ) : undefined;
		}

		removeAttr( name ) {
			this.nodes.forEach( ( node ) => {
				node.removeAttribute( name );
			} );
			return this;
		}

		find( selector ) {
			const normalizedSelector = selector === 'option:selected' ? 'option:checked' : selector;
			const found = [];

			this.nodes.forEach( ( node ) => {
				found.push( ...Array.from( node.querySelectorAll( normalizedSelector ) ) );
			} );

			return new JQueryCollection( found );
		}

		on( eventName, handler ) {
			this.nodes.forEach( ( node ) => {
				node.__handlers = node.__handlers || {};
				node.__handlers[ eventName ] = node.__handlers[ eventName ] || [];
				node.__handlers[ eventName ].push( handler );
			} );
			return this;
		}

		data( name, value ) {
			if ( ! this.length ) {
				return value === undefined ? undefined : this;
			}

			const node = this.nodes[ 0 ];
			node.__jqData = node.__jqData || {};

			if ( value !== undefined ) {
				node.__jqData[ name ] = value;
				return this;
			}

			return node.__jqData[ name ];
		}

		val( value ) {
			if ( value !== undefined ) {
				this.nodes.forEach( ( node ) => {
					node.value = value;
				} );
				return this;
			}
			return this.length ? this.nodes[ 0 ].value : undefined;
		}

		prop( name, value ) {
			this.nodes.forEach( ( node ) => {
				node[ name ] = value;
			} );
			return this;
		}

		html( value ) {
			if ( value !== undefined ) {
				this.nodes.forEach( ( node ) => {
					node.innerHTML = value;
				} );
				return this;
			}
			return this.length ? this.nodes[ 0 ].innerHTML : '';
		}

		text( value ) {
			if ( value !== undefined ) {
				this.nodes.forEach( ( node ) => {
					node.textContent = value;
				} );
				return this;
			}
			return this.nodes.map( ( node ) => node.textContent ).join( '' );
		}

		show() {
			this.nodes.forEach( ( node ) => {
				node.style.display = 'block';
			} );
			return this;
		}

		addClass( className ) {
			this.nodes.forEach( ( node ) => node.classList.add( className ) );
			return this;
		}

		removeClass( className ) {
			this.nodes.forEach( ( node ) => node.classList.remove( className ) );
			return this;
		}

		empty() {
			this.nodes.forEach( ( node ) => {
				node.innerHTML = '';
			} );
			return this;
		}

		is( selector ) {
			if ( ! this.length ) {
				return false;
			}

			if ( ':visible' === selector ) {
				return this.nodes[ 0 ].style.display !== 'none';
			}

			return this.nodes[ 0 ].matches( selector );
		}
	}

	beforeEach( () => {
		jest.useFakeTimers();

		document.body.innerHTML = `
			<div class="wp-mcp-ai-professional-selector" id="selector-1" data-wp-mcp-ai-professional-selector>
				<form class="wp-mcp-ai-professional-selector__form">
					<select data-assistant-select>
						<option value="">— Select Assistant —</option>
						<option value="assistant-1" selected>Assistant One</option>
					</select>
					<select data-professional-select>
						<option value="">— Select Professional —</option>
						<option value="profession-7" selected>Architect</option>
					</select>
					<select data-provider-select>
						<option value="">— Select Provider —</option>
						<option value="openai" selected>OpenAI</option>
					</select>
					<select data-model-select>
						<option value="">— Select Model —</option>
						<option value="gpt-4.1" selected>GPT-4.1</option>
					</select>
					<input data-temperature-input value="0.7" />
					<div data-error-message hidden></div>
				</form>
				<div class="wp-mcp-ai-professional-selector-modal" data-modal style="display:none;">
					<div data-modal-backdrop></div>
					<div class="wp-mcp-ai-professional-selector-modal__panel">
						<h2 data-modal-title></h2>
						<button type="button" data-modal-close>Close</button>
						<div data-modal-config></div>
						<div data-modal-chat></div>
					</div>
				</div>
			</div>
			<script type="application/json" data-selector-config="selector-1">
				{"instanceId":"selector-1","allowGuests":false,"saveTranscript":false,"enableStreaming":true,"allowSensitiveTools":true,"template":"classic","showTemperature":true}
			</script>
		`;

		ajaxMock = jest.fn( ( options ) => {
			options.success( {
				success: true,
				data: {
					html: `
						<div class="wp-mcp-ai-chat" id="chat-instance-1" data-wp-mcp-ai-chat>
							<form class="wp-mcp-ai-chat__form">
								<div class="wp-mcp-ai-chat__messages"></div>
								<div class="wp-mcp-ai-chat__status"></div>
								<textarea class="wp-mcp-ai-chat__input"></textarea>
								<button type="submit" class="wp-mcp-ai-chat__submit">Send</button>
							</form>
						</div>
					`,
					config: {
						id: 'chat-instance-1',
						assistantId: 'assistant-1',
						messagesEndpoint: '/wp-json/mcp-ai/v1/chat-client',
					},
				},
			} );
		} );

		$ = jest.fn( ( selector ) => {
			if ( selector instanceof JQueryCollection ) {
				return selector;
			}

			if ( selector === document ) {
				return {
					ready: ( callback ) => {
						callback();
					},
					on: jest.fn(),
				};
			}

			if ( selector && selector.nodeType ) {
				return new JQueryCollection( [ selector ] );
			}

			return new JQueryCollection( Array.from( document.querySelectorAll( selector ) ) );
		} );

		$.ajax = ajaxMock;
		$.each = jest.fn();

		global.jQuery = $;
		global.$ = $;
		global.wpMcpAiProfessionalSelector = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'selector-nonce',
			strings: {
				selectModel: '— Select Model —',
				errorLoading: 'Failed to load configuration. Please try again.',
				selectRequired: 'Please select an assistant, professional, provider, and model.',
			},
		};

		chatInitSpy = jest.fn();
		global.wpMcpAiChatInit = {
			init: chatInitSpy,
		};
		global.wpMcpAiChatInstances = {};

		const source = fs.readFileSync(
			path.join( __dirname, '../../assets/js/professional-selector.js' ),
			'utf8'
		);

		eval( source );
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
		jest.clearAllMocks();
		delete global.jQuery;
		delete global.$;
		delete global.wpMcpAiProfessionalSelector;
		delete global.wpMcpAiChatInit;
		delete global.wpMcpAiChatInstances;
	} );

	it( 'initializes dynamically injected modal chat markup through wpMcpAiChatInit with modal scope', () => {
		const form = document.querySelector( '.wp-mcp-ai-professional-selector__form' );
		const submitHandler = form.__handlers.submit[ 0 ];
		const modalChat = document.querySelector( '[data-modal-chat]' );

		submitHandler( {
			preventDefault: jest.fn(),
		} );

		jest.runAllTimers();

		expect( ajaxMock ).toHaveBeenCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					action: 'wp_mcp_ai_render_professional_chat',
				} ),
			} )
		);
		expect( chatInitSpy ).toHaveBeenCalledWith( modalChat );
		expect( global.wpMcpAiChatInstances[ 'chat-instance-1' ] ).toEqual(
			expect.objectContaining( {
				assistantId: 'assistant-1',
				provider: 'openai',
				model: 'gpt-4.1',
				temperature: '0.7',
			} )
		);
	} );
} );
