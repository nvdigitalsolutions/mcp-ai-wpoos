/**
 * Voice Conversation Widget Handler
 *
 * Handles voice recording, transcription, and playback for the voice conversation widget.
 * Follows separation of concerns by delegating orchestration to the server.
 *
 * @package WP_MCP_AI
 */

( function( $ ) {
	'use strict';

	/**
	 * Voice Conversation Handler
	 */
	class VoiceConversationHandler {
		constructor( button ) {
			this.button = button;
			this.$button = $( button );
			this.$widget = this.$button.closest( '.wp-mcp-ai-voice-conversation-widget' );
			this.$transcript = this.$widget.find( '.wp-mcp-ai-voice-transcript' );
			this.$messages = this.$widget.find( '.wp-mcp-ai-voice-transcript__messages' );
			this.$status = this.$widget.find( '.wp-mcp-ai-voice-status' );

			// Get configuration from data attributes
			this.config = {
				assistantId: this.$button.data( 'assistant-id' ) || 0,
				recordingText: this.$button.data( 'recording-text' ) || 'Recording…',
				processingText: this.$button.data( 'processing-text' ) || 'Processing…',
				maxDuration: parseInt( this.$button.data( 'max-duration' ), 10 ) || 60,
				autoPlay: this.$button.data( 'auto-play' ) === 'true',
				showTranscript: this.$button.data( 'show-transcript' ) === 'true',
				allowGuests: this.$button.data( 'allow-guests' ) === 'true',
			};

			// State
			this.state = 'idle'; // idle, recording, processing, playing
			this.mediaRecorder = null;
			this.audioChunks = [];
			this.audioStream = null;
			this.recordingTimer = null;
			this.conversationHistory = [];

			this.init();
		}

		init() {
			this.$button.on( 'click', this.handleButtonClick.bind( this ) );
		}

		async handleButtonClick( e ) {
			e.preventDefault();

			if ( this.state === 'idle' ) {
				await this.startRecording();
			} else if ( this.state === 'recording' ) {
				await this.stopRecording();
			}
		}

		async startRecording() {
			try {
				// Request microphone access
				this.audioStream = await navigator.mediaDevices.getUserMedia( { audio: true } );

				// Create media recorder
				const options = { mimeType: 'audio/webm' };
				if ( ! MediaRecorder.isTypeSupported( options.mimeType ) ) {
					options.mimeType = 'audio/mp4';
				}

				this.mediaRecorder = new MediaRecorder( this.audioStream, options );
				this.audioChunks = [];

				this.mediaRecorder.addEventListener( 'dataavailable', ( event ) => {
					if ( event.data.size > 0 ) {
						this.audioChunks.push( event.data );
					}
				} );

				this.mediaRecorder.addEventListener( 'stop', () => {
					this.processRecording();
				} );

				// Start recording
				this.mediaRecorder.start();
				this.state = 'recording';
				this.updateButtonState();

				// Auto-stop after max duration
				this.recordingTimer = setTimeout( () => {
					if ( this.state === 'recording' ) {
						this.stopRecording();
					}
				}, this.config.maxDuration * 1000 );

			} catch ( error ) {
				console.error( 'Failed to start recording:', error );
				this.showError( 'Failed to access microphone. Please check your permissions.' );
			}
		}

		async stopRecording() {
			if ( this.mediaRecorder && this.state === 'recording' ) {
				this.mediaRecorder.stop();
				
				// Clear timer
				if ( this.recordingTimer ) {
					clearTimeout( this.recordingTimer );
					this.recordingTimer = null;
				}

				// Stop audio stream
				if ( this.audioStream ) {
					this.audioStream.getTracks().forEach( ( track ) => track.stop() );
					this.audioStream = null;
				}
			}
		}

		async processRecording() {
			this.state = 'processing';
			this.updateButtonState();

			try {
				// Create audio blob from chunks
				const audioBlob = new Blob( this.audioChunks, { type: 'audio/webm' } );

				// Create form data
				const formData = new FormData();
				formData.append( 'audio', audioBlob, 'recording.webm' );
				formData.append( 'assistant_id', this.config.assistantId );
				formData.append( 'allow_guests', this.config.allowGuests ? '1' : '0' );

				// Add conversation history for context
				if ( this.conversationHistory.length > 0 ) {
					formData.append( 'conversation_history', JSON.stringify( this.conversationHistory ) );
				}

				// Get nonce
				const nonce = this.getNonce();
				if ( nonce ) {
					formData.append( '_wpnonce', nonce );
				}

				// Send to server for orchestration
				const response = await fetch( wpMcpAiVoice.apiUrl + '/voice-conversation', {
					method: 'POST',
					body: formData,
					headers: {
						'X-WP-Nonce': nonce || '',
					},
				} );

				if ( ! response.ok ) {
					throw new Error( 'Server returned error: ' + response.status );
				}

				const result = await response.json();

				if ( result.success ) {
					this.handleResponse( result.data );
				} else {
					throw new Error( result.message || 'Unknown error occurred' );
				}

			} catch ( error ) {
				console.error( 'Processing error:', error );
				this.showError( 'Failed to process your recording: ' + error.message );
			} finally {
				this.state = 'idle';
				this.updateButtonState();
			}
		}

		handleResponse( data ) {
			// Add to conversation history
			if ( data.user_text ) {
				this.conversationHistory.push( {
					role: 'user',
					content: data.user_text,
				} );

				if ( this.config.showTranscript ) {
					this.addTranscriptMessage( 'user', data.user_text );
				}
			}

			if ( data.assistant_text ) {
				this.conversationHistory.push( {
					role: 'assistant',
					content: data.assistant_text,
				} );

				if ( this.config.showTranscript ) {
					this.addTranscriptMessage( 'assistant', data.assistant_text );
				}
			}

			// Play audio response if available and auto-play is enabled
			if ( data.audio_url && this.config.autoPlay ) {
				this.playAudio( data.audio_url );
			}
		}

		playAudio( url ) {
			const audio = new Audio( url );
			
			this.state = 'playing';
			this.updateButtonState();

			audio.addEventListener( 'ended', () => {
				this.state = 'idle';
				this.updateButtonState();
			} );

			audio.addEventListener( 'error', ( error ) => {
				console.error( 'Audio playback error:', error );
				this.showError( 'Failed to play audio response' );
				this.state = 'idle';
				this.updateButtonState();
			} );

			audio.play();
		}

		addTranscriptMessage( role, text ) {
			const $message = $( '<div>' )
				.addClass( 'wp-mcp-ai-voice-transcript__message' )
				.addClass( 'wp-mcp-ai-voice-transcript__message--' + role );

			const $label = $( '<div>' )
				.addClass( 'wp-mcp-ai-voice-transcript__message-label' )
				.text( role === 'user' ? 'You' : 'Assistant' );

			const $content = $( '<div>' )
				.addClass( 'wp-mcp-ai-voice-transcript__message-content' )
				.text( text );

			$message.append( $label, $content );
			this.$messages.append( $message );

			// Show transcript if hidden
			if ( this.$transcript.is( ':hidden' ) ) {
				this.$transcript.slideDown( 300 );
			}

			// Scroll to bottom
			this.$transcript.scrollTop( this.$transcript.prop( 'scrollHeight' ) );
		}

		updateButtonState() {
			let text = this.$button.data( 'original-text' ) || this.$button.find( '.wp-mcp-ai-voice-button__text' ).text();
			
			// Store original text if not already stored
			if ( ! this.$button.data( 'original-text' ) ) {
				this.$button.data( 'original-text', text );
			}

			this.$button.removeClass( 'is-idle is-recording is-processing is-playing' );

			switch ( this.state ) {
				case 'recording':
					this.$button.addClass( 'is-recording' );
					text = this.config.recordingText;
					break;
				case 'processing':
					this.$button.addClass( 'is-processing' );
					text = this.config.processingText;
					this.$button.prop( 'disabled', true );
					break;
				case 'playing':
					this.$button.addClass( 'is-playing' );
					this.$button.prop( 'disabled', true );
					break;
				default:
					this.$button.addClass( 'is-idle' );
					text = this.$button.data( 'original-text' );
					this.$button.prop( 'disabled', false );
			}

			this.$button.find( '.wp-mcp-ai-voice-button__text' ).text( text );
		}

		showError( message ) {
			this.$status
				.addClass( 'wp-mcp-ai-voice-status--error' )
				.text( message )
				.fadeIn( 300 )
				.delay( 5000 )
				.fadeOut( 300, () => {
					this.$status.removeClass( 'wp-mcp-ai-voice-status--error' );
				} );
		}

		getNonce() {
			// Try to get nonce from various sources
			if ( typeof wpMcpAiVoice !== 'undefined' && wpMcpAiVoice.nonce ) {
				return wpMcpAiVoice.nonce;
			}

			// Fallback to REST API nonce
			if ( typeof wpApiSettings !== 'undefined' && wpApiSettings.nonce ) {
				return wpApiSettings.nonce;
			}

			return '';
		}
	}

	/**
	 * Initialize voice conversation widgets
	 */
	function initVoiceConversation() {
		$( '.wp-mcp-ai-voice-button' ).each( function() {
			if ( ! $( this ).data( 'voice-handler' ) ) {
				const handler = new VoiceConversationHandler( this );
				$( this ).data( 'voice-handler', handler );
			}
		} );
	}

	// Initialize on document ready
	$( document ).ready( initVoiceConversation );

	// Reinitialize on Elementor preview refresh
	$( window ).on( 'elementor/frontend/init', function() {
		if ( typeof elementorFrontend !== 'undefined' ) {
			elementorFrontend.hooks.addAction( 'frontend/element_ready/widget', initVoiceConversation );
		}
	} );

} )( jQuery );
