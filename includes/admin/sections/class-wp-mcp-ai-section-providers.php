<?php
/**
 * AI Providers Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Providers' ) ) {
	/**
	 * AI Providers settings section.
	 */
	class WP_MCP_AI_Section_Providers extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'providers';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'AI Provider Configuration', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'providers';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Google Gemini, Hugging Face, Ollama, LM Studio, Cloudflare Workers AI).', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/admin/SETTINGS_DASHBOARD_GUIDE.md#providers-tab';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get dynamic model choices from Model Config.
			$openai_models = array();
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$openai_models = WP_MCP_AI_Model_Config::get_models_by_provider( 'openai' );
			}

			// Fallback to minimal list if Model Config unavailable.
			if ( empty( $openai_models ) ) {
				$openai_models = array(
					'gpt-4o'      => 'GPT-4o',
					'gpt-4o-mini' => 'GPT-4o Mini',
					'gpt-4-turbo' => 'GPT-4 Turbo',
				);
			}

			// Get Anthropic models from Model Config.
			$anthropic_models = array();
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$anthropic_models = WP_MCP_AI_Model_Config::get_models_by_provider( 'anthropic' );
			}

			// Fallback to minimal list.
			if ( empty( $anthropic_models ) ) {
				$anthropic_models = array(
					'claude-sonnet-4-5'          => 'Claude Sonnet 4.5 (Recommended)',
					'claude-haiku-4-5'           => 'Claude Haiku 4.5 (Fastest)',
					'claude-opus-4-5'            => 'Claude Opus 4.5 (Flagship)',
					'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Legacy)',
					'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku (Legacy)',
				);
			}

			// Get Gemini models from Model Config.
			$gemini_models = array();
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$gemini_models = WP_MCP_AI_Model_Config::get_models_by_provider( 'gemini' );
			}

			// Fallback to minimal list.
			if ( empty( $gemini_models ) ) {
				$gemini_models = array(
					'gemini-2.5-flash' => 'Gemini 2.5 Flash (Latest - Stable)',
					'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
				);
			}

			// Get Cloudflare models from Model Config.
			$cloudflare_models = array();
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$cloudflare_models = WP_MCP_AI_Model_Config::get_models_by_provider( 'cloudflare' );
			}

			// Fallback to minimal list.
			if ( empty( $cloudflare_models ) ) {
				$cloudflare_models = array(
					'@cf/meta/llama-3.2-3b-instruct' => 'Llama 3.2 3B Instruct (Recommended)',
					'@cf/meta/llama-3.3-70b-instruct-fp8-fast' => 'Llama 3.3 70B Instruct FP8 Fast',
				);
			}

			// Get provider list dynamically.
			$provider_list = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$configured_providers = WP_MCP_AI_Model_Config::get_all_provider_slugs();
				if ( ! empty( $configured_providers ) ) {
					$provider_list = $configured_providers;
				}
			}

			return array(
				// Provider Priority List.
				'provider_priority_list'             => array(
					'type'        => 'custom',
					'label'       => __( 'Provider Priority Order', 'mcp-ai-wpoos' ),
					'description' => __( 'Drag and drop to reorder providers. The system will try providers in this order when one fails or is unavailable.', 'mcp-ai-wpoos' ),
					'default'     => $provider_list,
				),

				// OpenAI Settings.
				'enable_openai'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable OpenAI Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable OpenAI as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, OpenAI will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'openai_api_key'                     => array(
					'type'         => 'password',
					'label'        => __( 'OpenAI API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: OpenAI API keys URL */
						__( 'Your OpenAI API key. Get one from <a href="%s" target="_blank">OpenAI Platform</a>.', 'mcp-ai-wpoos' ),
						'https://platform.openai.com/api-keys'
					),
					'placeholder'  => 'sk-...',
					'autocomplete' => 'new-password',
				),
				'default_model'                      => array(
					'type'        => 'select',
					'label'       => __( 'Default OpenAI Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The default model to use for OpenAI requests. This model will be used unless overridden by an assistant or specific API call. Consider cost, speed, and capability trade-offs.', 'mcp-ai-wpoos' ),
					'options'     => $openai_models,
					'default'     => 'gpt-4.1',
				),
				'openai_embedding_model'             => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Embedding Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Model to use for generating text embeddings. text-embedding-3-small offers the best balance of performance and cost. text-embedding-3-large provides higher accuracy for complex tasks.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'text-embedding-3-small' => 'text-embedding-3-small',
						'text-embedding-3-large' => 'text-embedding-3-large',
						'text-embedding-ada-002' => 'text-embedding-ada-002',
					),
					'default'     => 'text-embedding-3-small',
				),
				'openai_organization_id'             => array(
					'type'         => 'text',
					'label'        => __( 'OpenAI Organization ID (Optional)', 'mcp-ai-wpoos' ),
					'description'  => __( 'Your OpenAI organization ID if you belong to multiple organizations. This is optional for most users. Find it in your OpenAI account settings if needed.', 'mcp-ai-wpoos' ),
					'placeholder'  => 'org-...',
					'autocomplete' => 'off',
				),
				'openai_image_model'                 => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for image generation via OpenAI. gpt-image-1 is the latest model with quality options. DALL-E 3 offers high quality with HD option. DALL-E 2 is faster and more economical.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'gpt-image-1' => 'gpt-image-1 (Latest)',
						'dall-e-3'    => 'DALL-E 3',
						'dall-e-2'    => 'DALL-E 2',
					),
					'default'     => 'gpt-image-1',
				),
				'openai_image_size'                  => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Size', 'mcp-ai-wpoos' ),
					'description' => __( 'Default size for generated images. Square format (1024x1024) works best for most purposes. Portrait (2:3) and landscape (3:2) formats offer creative flexibility.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'1024x1024' => '1024x1024 (Square)',
						'1024x1536' => '1024x1536 (Portrait 2:3)',
						'1536x1024' => '1536x1024 (Landscape 3:2)',
						'auto'      => 'Auto (Let AI decide)',
					),
					'default'     => '1024x1024',
				),
				'openai_image_quality'               => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Quality', 'mcp-ai-wpoos' ),
					'description' => __( 'Default quality setting for image generation. For gpt-image-1: low is faster/cheaper, medium balances quality and cost, high provides best results. For DALL-E models: standard or hd.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'low'      => 'Low (gpt-image-1)',
						'medium'   => 'Medium (gpt-image-1)',
						'high'     => 'High (gpt-image-1)',
						'auto'     => 'Auto (gpt-image-1)',
						'standard' => 'Standard (DALL-E)',
						'hd'       => 'HD (DALL-E)',
					),
					'default'     => 'medium',
				),
				'openai_image_response_format'       => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Response Format', 'mcp-ai-wpoos' ),
					'description' => __( 'Format for receiving generated images from OpenAI. b64_json returns base64-encoded data directly (recommended). URL provides a hosted image link (expires after 1 hour).', 'mcp-ai-wpoos' ),
					'options'     => array(
						'b64_json' => 'Base64 JSON (Recommended)',
						'url'      => 'URL (Expires in 1 hour)',
					),
					'default'     => 'b64_json',
				),
				'openai_transcribe_model'            => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Transcription Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for audio transcription and translation. whisper-1 is the OpenAI Whisper model for speech-to-text.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'whisper-1' => 'Whisper-1 (OpenAI Official)',
					),
					'default'     => 'whisper-1',
				),
				'openai_transcribe_response_format'  => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Transcription Response Format', 'mcp-ai-wpoos' ),
					'description' => __( 'Default format for transcription responses. verbose_json includes timestamps and metadata, json returns text only.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'verbose_json' => 'Verbose JSON (With timestamps)',
						'json'         => 'JSON (Text only)',
					),
					'default'     => 'verbose_json',
				),
				'openai_transcribe_language'         => array(
					'type'        => 'text',
					'label'       => __( 'OpenAI Transcription Language (Optional)', 'mcp-ai-wpoos' ),
					'description' => __( 'Optional ISO-639-1 language code (e.g., "en" for English, "es" for Spanish) to hint the language of the audio. Leave empty for automatic detection.', 'mcp-ai-wpoos' ),
					'placeholder' => 'en',
				),
				'openai_transcribe_temperature'      => array(
					'type'        => 'text',
					'label'       => __( 'OpenAI Transcription Temperature (Optional)', 'mcp-ai-wpoos' ),
					'description' => __( 'Optional sampling temperature between 0 and 1. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. Leave empty to use default.', 'mcp-ai-wpoos' ),
					'placeholder' => '0',
				),
				'openai_speech_model'                => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for text-to-speech (TTS) generation. tts-1 is the standard quality model, tts-1-hd provides higher quality audio.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'tts-1'    => 'TTS-1 (Standard Quality)',
						'tts-1-hd' => 'TTS-1-HD (High Quality)',
					),
					'default'     => 'tts-1',
				),
				'openai_speech_voice'                => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Voice', 'mcp-ai-wpoos' ),
					'description' => __( 'Default voice for text-to-speech generation. Each voice has a distinct personality and tone. Preview voices at OpenAI documentation.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'alloy'   => 'Alloy (Neutral)',
						'echo'    => 'Echo (Warm)',
						'fable'   => 'Fable (Expressive)',
						'onyx'    => 'Onyx (Deep)',
						'nova'    => 'Nova (Energetic)',
						'shimmer' => 'Shimmer (Soft)',
					),
					'default'     => 'alloy',
				),
				'openai_speech_format'               => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Format', 'mcp-ai-wpoos' ),
					'description' => __( 'Audio output format for TTS. MP3 offers best compatibility. OPUS is most efficient. AAC works well on Apple devices. FLAC is lossless quality. WAV is uncompressed.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'mp3'  => 'MP3 (Most Compatible)',
						'opus' => 'OPUS (Most Efficient)',
						'aac'  => 'AAC (Apple Devices)',
						'flac' => 'FLAC (Lossless)',
						'wav'  => 'WAV (Uncompressed)',
					),
					'default'     => 'mp3',
				),
				'enable_voice_activity_detection'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Voice Activity Detection (VAD)', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable hands-free voice chat with auto-send on pause', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, voice chat automatically stops recording and sends your message after detecting a pause in your speech (700ms of silence). This provides a hands-free experience. You can still manually stop recording anytime.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'vad_silence_threshold'              => array(
					'type'        => 'number',
					'label'       => __( 'VAD Silence Threshold (milliseconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to wait after detecting silence before auto-stopping recording. Industry standard: 700ms. Lower values (500ms) are more responsive but may cut off pauses. Higher values (1000ms+) give more time for thinking.', 'mcp-ai-wpoos' ),
					'default'     => '700',
					'min'         => '300',
					'max'         => '3000',
					'step'        => '100',
				),
				'vad_min_speech_duration'            => array(
					'type'        => 'number',
					'label'       => __( 'VAD Minimum Speech Duration (milliseconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'Minimum amount of speech required before VAD can auto-stop. Prevents false triggers from quick sounds. Recommended: 300ms.', 'mcp-ai-wpoos' ),
					'default'     => '300',
					'min'         => '100',
					'max'         => '1000',
					'step'        => '50',
				),
				'vad_audio_threshold'                => array(
					'type'        => 'number',
					'label'       => __( 'VAD Audio Level Threshold (dB)', 'mcp-ai-wpoos' ),
					'description' => __( 'Audio level threshold for detecting speech vs silence. Default: -50dB. Lower values (e.g., -60dB) are more sensitive and detect quieter speech. Higher values (e.g., -40dB) require louder speech and ignore more background noise.', 'mcp-ai-wpoos' ),
					'default'     => '-50',
					'min'         => '-70',
					'max'         => '-30',
					'step'        => '5',
				),
				'enable_high_token_model_switch'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable High Token Model Switch', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically switch to fallback model on token overflow', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, if a request exceeds the current model\'s token limit, the system will automatically switch to the specified fallback model with higher capacity. This prevents errors and ensures requests are processed even with large contexts.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'high_token_fallback_model'          => array(
					'type'        => 'text',
					'label'       => __( 'High Token Fallback Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Model to use when token limit is exceeded. Should be a model with higher token capacity than your default. Examples: gemini-2.5-flash (1M tokens), gpt-4o (128k tokens). This setting works across all providers.', 'mcp-ai-wpoos' ),
					'default'     => 'gemini-2.5-flash',
					'placeholder' => 'gemini-2.5-flash',
				),

				// OpenAI Caching Settings.
				'enable_openai_api_caching'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable OpenAI API Response Caching', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Cache OpenAI API responses to improve performance', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, caches model lists and embedding responses to reduce API calls and improve performance. Only deterministic operations are cached (chat completions are never cached).', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'openai_model_list_cache_ttl'        => array(
					'type'        => 'number',
					'label'       => __( 'OpenAI Model List Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache the OpenAI model list. Model lists rarely change, so longer caching is recommended. Default: 12 hours (43200 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '43200',
					'min'         => '300',
					'max'         => '86400',
					'step'        => '300',
				),
				'openai_embedding_cache_ttl'         => array(
					'type'        => 'number',
					'label'       => __( 'OpenAI Embedding Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache embedding responses. Embeddings are deterministic (same input = same output), so longer caching is safe. Default: 24 hours (86400 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '86400',
					'min'         => '300',
					'max'         => '604800',
					'step'        => '3600',
				),

				// Anthropic Settings.
				'enable_anthropic'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Anthropic Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Anthropic (Claude) as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, Anthropic will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'anthropic_api_key'                  => array(
					'type'         => 'password',
					'label'        => __( 'Anthropic API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Anthropic Console URL */
						__( 'Your Anthropic API key. Get one from <a href="%s" target="_blank">Anthropic Console</a>.', 'mcp-ai-wpoos' ),
						'https://console.anthropic.com/'
					),
					'placeholder'  => 'sk-ant-...',
					'autocomplete' => 'new-password',
				),
				'anthropic_model'                    => array(
					'type'        => 'select',
					'label'       => __( 'Default Anthropic Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The default Claude model to use for Anthropic requests. Claude 3.5 Sonnet offers the best balance of intelligence and speed. Claude 3.5 Haiku is faster and more economical for simpler tasks.', 'mcp-ai-wpoos' ),
					'options'     => $anthropic_models,
					'default'     => 'claude-3-5-sonnet-20241022',
				),
				'anthropic_vision_model'             => array(
					'type'        => 'select',
					'label'       => __( 'Anthropic Vision Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for image analysis and vision tasks via Anthropic. All Claude 3+ models support vision capabilities. Claude 3.5 Sonnet and Opus offer the best vision performance.', 'mcp-ai-wpoos' ),
					'options'     => $anthropic_models,
					'default'     => 'claude-3-5-sonnet-20241022',
				),
				'anthropic_max_image_tokens'         => array(
					'type'        => 'text',
					'label'       => __( 'Anthropic Max Image Tokens', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of tokens to allocate for image analysis. Higher values allow more detailed analysis but use more tokens. Leave empty to use model defaults. Typical range: 1000-4000.', 'mcp-ai-wpoos' ),
					'placeholder' => '1568',
					'sanitize'    => 'absint',
				),

				// Anthropic Caching Settings.
				'enable_anthropic_api_caching'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Anthropic API Response Caching', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Cache Anthropic API responses to improve performance', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, caches model lists and other deterministic API responses to reduce API calls and improve performance. Chat completions are never cached.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'anthropic_model_list_cache_ttl'     => array(
					'type'        => 'number',
					'label'       => __( 'Anthropic Model List Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache the Anthropic model list. Model lists rarely change, so longer caching is recommended. Default: 12 hours (43200 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '43200',
					'min'         => '300',
					'max'         => '86400',
					'step'        => '300',
				),

				// Google Gemini Settings.
				'enable_gemini'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Gemini Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Google Gemini as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, Gemini will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'gemini_api_key'                     => array(
					'type'         => 'password',
					'label'        => __( 'Gemini API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Google AI Studio URL */
						__( 'Your Google Gemini API key. Get one from <a href="%s" target="_blank">Google AI Studio</a>.', 'mcp-ai-wpoos' ),
						'https://aistudio.google.com/app/apikey'
					),
					'placeholder'  => 'AIza...',
					'autocomplete' => 'new-password',
				),
				'default_gemini_model'               => array(
					'type'        => 'select',
					'label'       => __( 'Default Gemini Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The default model to use for Gemini requests. Gemini 2.5 Pro is the flagship model with best performance. Gemini 2.5 Flash is the latest stable model with multimodal support (text, image, video). Gemini 2.0 Flash is the previous stable generation. Gemini 1.5 Pro provides proven performance, while 1.5 Flash is faster and more economical.', 'mcp-ai-wpoos' ),
					'options'     => $gemini_models,
					'default'     => 'gemini-2.5-flash',
				),
				'gemini_image_model'                 => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for image generation via Gemini. gemini-2.5-flash-image is the latest specialized image generation model. gemini-exp-1206 provides experimental features.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'gemini-2.5-flash-image' => 'Gemini 2.5 Flash Image (Latest)',
						'gemini-exp-1206'        => 'Gemini Exp 1206 (Experimental)',
					),
					'default'     => 'gemini-2.5-flash-image',
				),
				'gemini_image_mime_type'             => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image MIME Type', 'mcp-ai-wpoos' ),
					'description' => __( 'Default image format for Gemini-generated images. PNG offers lossless compression, JPEG is smaller for photos, WebP provides best compression.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'image/png'  => 'PNG (Lossless)',
						'image/jpeg' => 'JPEG (Photo-optimized)',
						'image/webp' => 'WebP (Modern format)',
					),
					'default'     => 'image/png',
				),
				'gemini_image_aspect_ratio'          => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image Aspect Ratio', 'mcp-ai-wpoos' ),
					'description' => __( 'Default aspect ratio for Gemini-generated images. Square (1:1) works for most purposes. Portrait (3:4, 9:16) and landscape (4:3, 16:9) offer creative flexibility.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'auto' => 'Auto (Let AI decide)',
						'1:1'  => '1:1 (Square)',
						'3:4'  => '3:4 (Portrait)',
						'4:3'  => '4:3 (Landscape)',
						'9:16' => '9:16 (Vertical)',
						'16:9' => '16:9 (Widescreen)',
					),
					'default'     => '4:3',
				),
				'gemini_video_model'                 => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Default model for video generation via Gemini Veo. veo-2.0-generate-001 is stable with fewer restrictions (supports 5-8 seconds, 720p max). veo-3.1-generate-preview is the latest with synchronized audio and 1080p support, but requires exactly 8 seconds for 1080p and has stricter quota limits.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'veo-2.0-generate-001'     => 'Veo 2.0 Generate (Stable, Fewer Restrictions)',
						'veo-3.1-generate-preview' => 'Veo 3.1 Generate Preview (Latest, Audio, 1080p)',
					),
					'default'     => 'veo-2.0-generate-001',
				),
				'gemini_video_resolution'            => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Resolution', 'mcp-ai-wpoos' ),
					'description' => __( 'Default resolution for Gemini-generated videos. 720p is supported by all Veo models and works for all aspect ratios. 1080p is only available with Veo 3.1 for 16:9 aspect ratio and requires exactly 8 seconds duration. Note: Veo 2.0 always outputs 720p regardless of this setting.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'720p'  => '720p (All models, all durations)',
						'1080p' => '1080p (Veo 3.1 only, 16:9, 8s required)',
					),
					'default'     => '720p',
				),
				'gemini_video_aspect_ratio'          => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Aspect Ratio', 'mcp-ai-wpoos' ),
					'description' => __( 'Default aspect ratio for Gemini-generated videos. 16:9 is landscape/widescreen format (supports both 720p and 1080p). 9:16 is vertical/portrait format (supports 720p only).', 'mcp-ai-wpoos' ),
					'options'     => array(
						'16:9' => '16:9 (Landscape/Widescreen)',
						'9:16' => '9:16 (Vertical/Portrait)',
					),
					'default'     => '16:9',
				),
				'gemini_video_duration'              => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Duration', 'mcp-ai-wpoos' ),
					'description' => __( 'Default duration for Gemini-generated videos in seconds. Veo 3.1 supports 4-8 seconds (with potential for extended clips via API), Veo 2.0 supports 5-8 seconds. Note: If 1080p resolution is requested, duration will be automatically set to 8 seconds (API requirement).', 'mcp-ai-wpoos' ),
					'options'     => array(
						'4' => '4 seconds (Veo 3.1 only)',
						'5' => '5 seconds',
						'6' => '6 seconds',
						'7' => '7 seconds',
						'8' => '8 seconds (Required for 1080p)',
					),
					'default'     => '5',
				),

				// Gemini Caching Settings.
				'enable_gemini_api_caching'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Gemini API Response Caching', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Cache Gemini API responses to improve performance', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, caches model lists, token counts, and embedding responses to reduce API calls and improve performance. Only deterministic operations are cached (chat completions are never cached).', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'gemini_model_list_cache_ttl'        => array(
					'type'        => 'number',
					'label'       => __( 'Gemini Model List Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache the Gemini model list. Model lists rarely change, so longer caching is recommended. Default: 12 hours (43200 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '43200',
					'min'         => '300',
					'max'         => '86400',
					'step'        => '300',
				),
				'gemini_embedding_cache_ttl'         => array(
					'type'        => 'number',
					'label'       => __( 'Gemini Embedding Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache embedding responses. Embeddings are deterministic (same input = same output), so longer caching is safe. Default: 24 hours (86400 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '86400',
					'min'         => '300',
					'max'         => '604800',
					'step'        => '3600',
				),
				'gemini_token_count_cache_ttl'       => array(
					'type'        => 'number',
					'label'       => __( 'Gemini Token Count Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache token counting results. Token counts are deterministic for the same input and model. Default: 1 hour (3600 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '3600',
					'min'         => '300',
					'max'         => '86400',
					'step'        => '300',
				),

				// Gemini Audio Settings (Speech-to-Text & Text-to-Speech).
				'gemini_audio_language'              => array(
					'type'        => 'text',
					'label'       => __( 'Gemini Audio Language Code', 'mcp-ai-wpoos' ),
					'description' => __( 'Default language code for Google Speech-to-Text and Text-to-Speech (e.g., "en-US", "es-ES", "fr-FR"). Supports 125+ languages.', 'mcp-ai-wpoos' ),
					'placeholder' => 'en-US',
					'default'     => 'en-US',
				),
				'gemini_speech_voice'                => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Speech Voice', 'mcp-ai-wpoos' ),
					'description' => __( 'Default voice for Google Text-to-Speech. Neural2 voices provide improved quality. Choose based on desired gender and language.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'en-US-Neural2-A' => 'en-US-Neural2-A (Male)',
						'en-US-Neural2-C' => 'en-US-Neural2-C (Female, Recommended)',
						'en-US-Neural2-D' => 'en-US-Neural2-D (Male)',
						'en-US-Neural2-E' => 'en-US-Neural2-E (Female)',
						'en-US-Neural2-F' => 'en-US-Neural2-F (Female)',
						'en-US-Neural2-G' => 'en-US-Neural2-G (Female)',
						'en-US-Neural2-H' => 'en-US-Neural2-H (Female)',
						'en-US-Neural2-I' => 'en-US-Neural2-I (Male)',
						'en-US-Neural2-J' => 'en-US-Neural2-J (Male)',
					),
					'default'     => 'en-US-Neural2-C',
				),

				// Ollama Settings.
				'enable_ollama'                      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Ollama Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Ollama (Local AI) as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, Ollama will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'ollama_endpoint_url'                => array(
					'type'        => 'url',
					'label'       => __( 'Ollama Endpoint URL', 'mcp-ai-wpoos' ),
					'description' => __( 'URL where your Ollama server is running. Examples: "http://localhost:11434" (same machine), "http://192.168.2.222:11434" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN Ollama: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'mcp-ai-wpoos' ),
					'placeholder' => 'http://localhost:11434',
					/**
					 * Filter the default Ollama endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'http://localhost:11434'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_ollama_endpoint_url', 'http://localhost:11434' ),
				),
				'ollama_model'                       => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The model name to use with Ollama. Must match exactly a model you have pulled (e.g., llama3, mistral, codellama). Use \"ollama list\" in terminal to see available models.', 'mcp-ai-wpoos' ),
					'placeholder' => 'llama3',
				),
				'ollama_network_interface'           => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Network Interface (Optional)', 'mcp-ai-wpoos' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your Ollama is on a different machine (e.g., 192.168.2.100), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),

				// Ollama Caching Settings.
				'enable_ollama_api_caching'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Ollama API Response Caching', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Cache Ollama API responses to improve performance', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, caches model lists and embedding responses to reduce API calls and improve performance. Uses shorter TTLs since Ollama is local. Only deterministic operations are cached (chat completions are never cached).', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'ollama_model_list_cache_ttl'        => array(
					'type'        => 'number',
					'label'       => __( 'Ollama Model List Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache the Ollama model list. Since Ollama is local, shorter caching is sufficient. Default: 5 minutes (300 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '300',
					'min'         => '60',
					'max'         => '3600',
					'step'        => '60',
				),
				'ollama_embedding_cache_ttl'         => array(
					'type'        => 'number',
					'label'       => __( 'Ollama Embedding Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache embedding responses. Embeddings are deterministic (same input = same output), so longer caching is safe. Default: 24 hours (86400 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '86400',
					'min'         => '300',
					'max'         => '604800',
					'step'        => '3600',
				),

				// LM Studio Settings.
				'enable_lm_studio'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable LM Studio Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable LM Studio (Local AI) as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, LM Studio will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'lm_studio_endpoint_url'             => array(
					'type'        => 'url',
					'label'       => __( 'LM Studio Endpoint URL', 'mcp-ai-wpoos' ),
					'description' => __( 'URL where your LM Studio server is running. Examples: "http://localhost:1234" (same machine), "http://192.168.2.222:1234" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN LM Studio: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'mcp-ai-wpoos' ),
					'placeholder' => 'http://localhost:1234',
					/**
					 * Filter the default LM Studio endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'http://localhost:1234'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url', 'http://localhost:1234' ),
				),
				'lm_studio_model'                    => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The model identifier for your loaded LM Studio model. This is typically shown in the LM Studio interface. Some installations accept \"local-model\" as a generic identifier.', 'mcp-ai-wpoos' ),
					'placeholder' => 'local-model',
				),
				'lm_studio_network_interface'        => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Network Interface (Optional)', 'mcp-ai-wpoos' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your LM Studio is on a different machine (e.g., 192.168.2.222), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),

				// Hugging Face Settings.
				'enable_huggingface'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Hugging Face Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Hugging Face Inference API as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, Hugging Face will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'huggingface_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'Hugging Face API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Hugging Face tokens URL */
						__( 'Your Hugging Face API token. Get one from <a href="%s" target="_blank">Hugging Face Settings</a>. Use a token with "Inference" permissions.', 'mcp-ai-wpoos' ),
						'https://huggingface.co/settings/tokens'
					),
					'placeholder'  => 'hf_...',
					'autocomplete' => 'new-password',
				),
				'huggingface_endpoint_url'           => array(
					'type'        => 'url',
					'label'       => __( 'Hugging Face Endpoint URL', 'mcp-ai-wpoos' ),
					'description' => __( 'URL for the Hugging Face Inference API. Use the default for the public API, or provide a custom endpoint URL for Inference Endpoints or private deployments. The plugin automatically appends the correct path (/chat/completions or /models).', 'mcp-ai-wpoos' ),
					'placeholder' => 'https://router.huggingface.co/v1',
					/**
					 * Filter the default Hugging Face endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'https://router.huggingface.co/v1'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_huggingface_endpoint_url', 'https://router.huggingface.co/v1' ),
				),
				'huggingface_model'                  => array(
					'type'        => 'text',
					'label'       => __( 'Hugging Face Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The model identifier to use with Hugging Face. Examples: "meta-llama/Llama-3.3-70B-Instruct", "mistralai/Mistral-7B-Instruct-v0.3", "microsoft/Phi-3-mini-4k-instruct". Must be a chat/instruction model with a chat_template defined.', 'mcp-ai-wpoos' ),
					'placeholder' => 'meta-llama/Llama-3.3-70B-Instruct',
				),

				// Hugging Face Audio Settings (Speech-to-Text).
				'huggingface_audio_model'            => array(
					'type'        => 'text',
					'label'       => __( 'Hugging Face Audio Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Model identifier for audio transcription. Default is "openai/whisper-large-v3" which provides high-quality multilingual transcription via Hugging Face Inference API.', 'mcp-ai-wpoos' ),
					'placeholder' => 'openai/whisper-large-v3',
					'default'     => 'openai/whisper-large-v3',
				),

				// Hugging Face Dataset Viewer Settings.
				'enable_huggingface_datasets'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable HuggingFace Dataset Viewer', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable tools for querying HuggingFace datasets', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, AI assistants can query 100,000+ machine learning datasets from HuggingFace Hub without downloading them. Useful for dataset discovery, preview, search, and filtering for few-shot learning.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'huggingface_datasets_api_token'     => array(
					'type'         => 'password',
					'label'        => __( 'HuggingFace API Token (Optional)', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Hugging Face tokens URL */
						__( 'Optional: Only required for accessing private or gated datasets. Public datasets work without a token. Get one from <a href="%s" target="_blank">HuggingFace Settings</a>.', 'mcp-ai-wpoos' ),
						'https://huggingface.co/settings/tokens'
					),
					'placeholder'  => 'hf_...',
					'autocomplete' => 'new-password',
				),
				'huggingface_datasets_cache_ttl'     => array(
					'type'        => 'number',
					'label'       => __( 'Cache TTL (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache dataset API responses. Longer values reduce API calls but may show stale data. Range: 60-86400 seconds (1 minute to 24 hours).', 'mcp-ai-wpoos' ),
					'default'     => 3600,
					'min'         => 60,
					'max'         => 86400,
					'step'        => 60,
				),
				'huggingface_datasets_default_limit' => array(
					'type'        => 'number',
					'label'       => __( 'Default Row Limit', 'mcp-ai-wpoos' ),
					'description' => __( 'Default number of rows to return when previewing datasets. Maximum 100 rows per request. Lower values reduce token usage.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'min'         => 1,
					'max'         => 100,
					'step'        => 1,
				),

				// Cloudflare Workers AI Settings.
				'enable_cloudflare'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cloudflare Workers AI Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Cloudflare Workers AI as an available provider', 'mcp-ai-wpoos' ),
					'description'    => __( 'When disabled, Cloudflare Workers AI will not be available for use by assistants or API requests.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'cloudflare_api_token'               => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Cloudflare API tokens URL */
						__( 'Your Cloudflare API token with Workers AI permissions. Get one from <a href="%s" target="_blank">Cloudflare Dashboard</a>. Create a token with "Workers AI" permissions for your account.', 'mcp-ai-wpoos' ),
						'https://dash.cloudflare.com/profile/api-tokens'
					),
					'placeholder'  => 'Bearer token...',
					'autocomplete' => 'new-password',
				),
				'cloudflare_account_id'              => array(
					'type'         => 'text',
					'label'        => __( 'Cloudflare Account ID', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Cloudflare Dashboard URL */
						__( 'Your Cloudflare account ID. Find this in your <a href="%s" target="_blank">Cloudflare Dashboard</a> under Workers & Pages > Overview.', 'mcp-ai-wpoos' ),
						'https://dash.cloudflare.com/'
					),
					'placeholder'  => '1234567890abcdef...',
					'autocomplete' => 'off',
				),
				'cloudflare_model'                   => array(
					'type'        => 'select',
					'label'       => __( 'Default Cloudflare Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The default model to use for Cloudflare Workers AI requests. Updated catalog includes function calling and text generation models. Llama 3.2-3B-Instruct is recommended for general use.', 'mcp-ai-wpoos' ),
					'options'     => $cloudflare_models,
					'default'     => '@cf/meta/llama-3.2-3b-instruct',
				),
				'cloudflare_image_model'             => array(
					'type'        => 'select',
					'label'       => __( 'Default Cloudflare Image Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The default model to use for Cloudflare Workers AI text-to-image generation. Stable Diffusion XL Base is recommended for general purpose use.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'@cf/stabilityai/stable-diffusion-xl-base-1.0' => 'Stable Diffusion XL Base 1.0 (Recommended)',
						'@cf/bytedance/stable-diffusion-xl-lightning' => 'Stable Diffusion XL Lightning (Fast)',
						'@cf/black-forest-labs/flux-1-schnell' => 'Flux-1 Schnell',
						'@cf/black-forest-labs/flux-2-dev' => 'Flux-2 Dev',
						'@cf/leonardo/lucid-origin'        => 'Leonardo Lucid Origin',
						'@cf/leonardo/phoenix-1.0'         => 'Leonardo Phoenix 1.0',
						'@cf/lykon/dreamshaper-8-lcm'      => 'Dreamshaper 8 LCM',
					),
					'default'     => '@cf/stabilityai/stable-diffusion-xl-base-1.0',
				),
				'cloudflare_image_width'             => array(
					'type'        => 'number',
					'label'       => __( 'Default Image Width', 'mcp-ai-wpoos' ),
					'description' => __( 'Default width for generated images in pixels (256-2048). Different models may have different optimal sizes.', 'mcp-ai-wpoos' ),
					'default'     => 1024,
					'min'         => 256,
					'max'         => 2048,
					'step'        => 64,
				),
				'cloudflare_image_height'            => array(
					'type'        => 'number',
					'label'       => __( 'Default Image Height', 'mcp-ai-wpoos' ),
					'description' => __( 'Default height for generated images in pixels (256-2048). Different models may have different optimal sizes.', 'mcp-ai-wpoos' ),
					'default'     => 1024,
					'min'         => 256,
					'max'         => 2048,
					'step'        => 64,
				),
				'cloudflare_image_num_steps'         => array(
					'type'        => 'number',
					'label'       => __( 'Default Number of Steps', 'mcp-ai-wpoos' ),
					'description' => __( 'Default number of diffusion steps (1-20). More steps can improve quality but take longer. 20 is recommended.', 'mcp-ai-wpoos' ),
					'default'     => 20,
					'min'         => 1,
					'max'         => 20,
					'step'        => 1,
				),
				'cloudflare_image_guidance'          => array(
					'type'        => 'number',
					'label'       => __( 'Default Guidance Scale', 'mcp-ai-wpoos' ),
					'description' => __( 'Default guidance scale controls how closely the image follows the prompt. Higher values (7-15) mean stricter adherence. 7.5 is recommended.', 'mcp-ai-wpoos' ),
					'default'     => 7.5,
					'min'         => 1.0,
					'max'         => 20.0,
					'step'        => 0.5,
				),

				// Cloudflare Caching Settings.
				'enable_cloudflare_api_caching'      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cloudflare API Response Caching', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Cache Cloudflare Workers AI responses to improve performance', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, caches model lists and other deterministic API responses to reduce API calls and improve performance. Chat completions and image generations are never cached.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'cloudflare_model_list_cache_ttl'    => array(
					'type'        => 'number',
					'label'       => __( 'Cloudflare Model List Cache Duration (seconds)', 'mcp-ai-wpoos' ),
					'description' => __( 'How long to cache the Cloudflare Workers AI model list. Model lists rarely change, so longer caching is recommended. Default: 12 hours (43200 seconds).', 'mcp-ai-wpoos' ),
					'default'     => '43200',
					'min'         => '300',
					'max'         => '86400',
					'step'        => '300',
				),

				// Cloudflare Audio Settings (Speech-to-Text).
				'cloudflare_audio_model'             => array(
					'type'        => 'select',
					'label'       => __( 'Cloudflare STT Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Speech-to-Text model for audio transcription. Whisper provides standard transcription. Deepgram Flux offers advanced features with turn detection for conversational AI.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'@cf/openai/whisper' => __( 'Whisper (Standard - OpenAI Whisper)', 'mcp-ai-wpoos' ),
						'@cf/deepgram/flux'  => __( 'Deepgram Flux (Advanced - Turn Detection)', 'mcp-ai-wpoos' ),
					),
					'default'     => '@cf/openai/whisper',
				),

				// Google Maps Settings.
				'google_maps_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'Google Maps API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: Google Cloud Console URL */
						__( 'Your Google Maps Platform API key. Required for geocoding tools (address lookup, reverse geocoding, nearby places search). Get one from <a href="%s" target="_blank">Google Cloud Console</a>. You need to enable the "Geocoding API" and "Places API" for full functionality.', 'mcp-ai-wpoos' ),
						'https://console.cloud.google.com/google/maps-apis/credentials'
					),
					'placeholder'  => 'AIza...',
					'autocomplete' => 'new-password',
				),
			);
		}

		/**
		 * Get provider sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			$groups = array(
				'priority'             => array(
					'id'     => 'priority',
					'label'  => __( 'Priority Order', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-sort',
					'fields' => array( 'provider_priority_list' ),
				),
				'openai'               => array(
					'id'     => 'openai',
					'label'  => __( 'OpenAI', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_openai', 'openai_api_key', 'default_model', 'openai_embedding_model', 'openai_organization_id', 'openai_image_model', 'openai_image_size', 'openai_image_quality', 'openai_image_response_format', 'openai_transcribe_model', 'openai_transcribe_response_format', 'openai_transcribe_language', 'openai_transcribe_temperature', 'openai_speech_model', 'openai_speech_voice', 'openai_speech_format', 'enable_high_token_model_switch', 'high_token_fallback_model', 'enable_openai_api_caching', 'openai_model_list_cache_ttl', 'openai_embedding_cache_ttl', 'enable_voice_activity_detection', 'vad_silence_threshold', 'vad_min_speech_duration', 'vad_audio_threshold' ),
				),
				'anthropic'            => array(
					'id'     => 'anthropic',
					'label'  => __( 'Anthropic', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_anthropic', 'anthropic_api_key', 'anthropic_model', 'anthropic_vision_model', 'anthropic_max_image_tokens', 'enable_anthropic_api_caching', 'anthropic_model_list_cache_ttl' ),
				),
				'gemini'               => array(
					'id'     => 'gemini',
					'label'  => __( 'Google Gemini', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_gemini', 'gemini_api_key', 'default_gemini_model', 'gemini_image_model', 'gemini_image_mime_type', 'gemini_image_aspect_ratio', 'gemini_video_model', 'gemini_video_resolution', 'gemini_video_aspect_ratio', 'gemini_video_duration', 'enable_gemini_api_caching', 'gemini_model_list_cache_ttl', 'gemini_embedding_cache_ttl', 'gemini_token_count_cache_ttl', 'gemini_audio_language', 'gemini_speech_voice' ),
				),
				'ollama'               => array(
					'id'     => 'ollama',
					'label'  => __( 'Ollama (Local)', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_ollama', 'ollama_endpoint_url', 'ollama_model', 'ollama_network_interface', 'enable_ollama_api_caching', 'ollama_model_list_cache_ttl', 'ollama_embedding_cache_ttl' ),
				),
				'lm_studio'            => array(
					'id'     => 'lm_studio',
					'label'  => __( 'LM Studio (Local)', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_lm_studio', 'lm_studio_endpoint_url', 'lm_studio_model', 'lm_studio_network_interface' ),
				),
				'huggingface'          => array(
					'id'     => 'huggingface',
					'label'  => __( 'Hugging Face', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'enable_huggingface', 'huggingface_api_key', 'huggingface_endpoint_url', 'huggingface_model', 'huggingface_audio_model' ),
				),
				'huggingface_datasets' => array(
					'id'     => 'huggingface_datasets',
					'label'  => __( 'HF Datasets', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-database',
					'fields' => array( 'enable_huggingface_datasets', 'huggingface_datasets_api_token', 'huggingface_datasets_cache_ttl', 'huggingface_datasets_default_limit' ),
				),
				'cloudflare'           => array(
					'id'     => 'cloudflare',
					'label'  => __( 'Cloudflare', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'enable_cloudflare', 'cloudflare_api_token', 'cloudflare_account_id', 'cloudflare_model', 'cloudflare_image_model', 'cloudflare_image_width', 'cloudflare_image_height', 'cloudflare_image_num_steps', 'cloudflare_image_guidance', 'enable_cloudflare_api_caching', 'cloudflare_model_list_cache_ttl', 'cloudflare_audio_model' ),
				),
				'google_maps'          => array(
					'id'     => 'google_maps',
					'label'  => __( 'Google Maps', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-location',
					'fields' => array( 'google_maps_api_key' ),
				),
			);

			// Merge Pro provider subtabs if Pro addon is active.
			// This allows the Embedded LLM subtab to appear alongside other providers.
			if ( class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
				$pro_providers_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
				if ( $pro_providers_section && method_exists( $pro_providers_section, 'get_subtab_groups' ) ) {
					// Get Pro provider subtabs using reflection to call protected method.
					$reflection = new ReflectionClass( $pro_providers_section );
					if ( $reflection->hasMethod( 'get_subtab_groups' ) ) {
						$method = $reflection->getMethod( 'get_subtab_groups' );
						$method->setAccessible( true );
						$pro_groups = $method->invoke( $pro_providers_section );
						if ( is_array( $pro_groups ) ) {
							// Merge Pro subtabs into the main groups array.
							$groups = array_merge( $groups, $pro_groups );
						}
					}
				}
			}

			// Filter out null values (e.g., embedded provider in base version).
			return array_filter( $groups );
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

			// Default to 'priority' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'priority';
			}

			return $subtab;
		}

		/**
		 * Render the section wrapper with sub-tabs.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
		<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
<h2><?php echo esc_html( $this->get_title() ); ?></h2>
			<?php if ( $description ) : ?>
<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
			<?php if ( $documentation_url ) : ?>
				<p class="section-documentation">
					<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
					<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
						<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
					</a>
				</p>
			<?php endif; ?>

<div class="wp-mcp-ai-provider-subtabs">
<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Provider sub-tabs', 'mcp-ai-wpoos' ); ?>">
			<?php foreach ( $subtab_groups as $group ) : ?>
				<?php
				$subtab_url = add_query_arg(
					array(
						'page'   => 'wp-mcp-ai-dashboard',
						'tab'    => 'providers',
						'subtab' => $group['id'],
					),
					admin_url( 'admin.php' )
				);
				$is_active  = ( $group['id'] === $active_subtab );
				?>
<a href="<?php echo esc_url( $subtab_url ); ?>"
	class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
	data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
				<?php echo esc_html( $group['label'] ); ?>
</a>
		<?php endforeach; ?>
</nav>

				<!-- Hidden field to preserve subtab during form submission -->
				<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />

<div class="wp-mcp-ai-subtab-content">
<table class="form-table" role="presentation">
				<?php $this->render(); ?>
</table>
</div>
</div>
</div>
				<?php
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// If this is the 'embedded' subtab, delegate to Pro Providers section.
			if ( 'embedded' === $active_subtab && class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
				$pro_providers_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
				if ( $pro_providers_section && method_exists( $pro_providers_section, 'get_fields' ) ) {
					// Get Pro provider fields using reflection to call protected method.
					$reflection = new ReflectionClass( $pro_providers_section );
					if ( $reflection->hasMethod( 'get_fields' ) ) {
						$method = $reflection->getMethod( 'get_fields' );
						$method->setAccessible( true );
						$pro_fields = $method->invoke( $pro_providers_section );
						
						// Render Pro provider fields for the embedded subtab.
						foreach ( $active_group['fields'] as $key ) {
							if ( isset( $pro_fields[ $key ] ) ) {
								// Use Pro section's render_field method if available, otherwise use our own.
								if ( method_exists( $pro_providers_section, 'render_field' ) ) {
									$render_method = $reflection->getMethod( 'render_field' );
									$render_method->setAccessible( true );
									$render_method->invoke( $pro_providers_section, $key, $pro_fields[ $key ] );
								} else {
									$this->render_field( $key, $pro_fields[ $key ] );
								}
							}
						}
						return;
					}
				}
			}

			// Render fields for the active sub-tab.
			if ( 'priority' === $active_subtab && isset( $fields['provider_priority_list'] ) ) {
				$this->render_provider_priority_list( $fields['provider_priority_list'] );
			} else {
				foreach ( $active_group['fields'] as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$this->render_field( $key, $fields[ $key ] );
					}
				}
			}
		}

		/**
		 * Render the provider priority list field.
		 *
		 * @param array $field Field configuration.
		 */
		private function render_provider_priority_list( $field ) {
			$label       = isset( $field['label'] ) ? $field['label'] : '';
			$description = isset( $field['description'] ) ? $field['description'] : '';
			$saved_value = WP_MCP_AI_Settings_Registry::get_setting( 'provider_priority_list', array() );
			$default     = isset( $field['default'] ) ? $field['default'] : array();

			// Merge saved value with defaults to ensure all providers are included.
			// Existing users may have old lists without 'huggingface'.
			$value = is_array( $saved_value ) && ! empty( $saved_value ) ? $saved_value : $default;

			// Append any missing providers from defaults to the end.
			foreach ( $default as $provider ) {
				if ( ! in_array( $provider, $value, true ) ) {
					$value[] = $provider;
				}
			}

			$provider_labels = array(
				'openai'      => __( 'OpenAI', 'mcp-ai-wpoos' ),
				'anthropic'   => __( 'Anthropic (Claude)', 'mcp-ai-wpoos' ),
				'gemini'      => __( 'Gemini', 'mcp-ai-wpoos' ),
				'huggingface' => __( 'Hugging Face', 'mcp-ai-wpoos' ),
				'ollama'      => __( 'Ollama (Local AI)', 'mcp-ai-wpoos' ),
				'lm_studio'   => __( 'LM Studio (Local AI)', 'mcp-ai-wpoos' ),
				'cloudflare'  => __( 'Cloudflare (Workers AI)', 'mcp-ai-wpoos' ),
				'embedded'    => __( 'Embedded LLM (Local AI - Pro)', 'mcp-ai-wpoos' ),
			);
			?>
			<tr>
				<th scope="row">
					<label><?php echo esc_html( $label ); ?></label>
				</th>
				<td>
					<div id="wp-mcp-ai-provider-priority-list" class="wp-mcp-ai-sortable-list">
						<ul id="wp-mcp-ai-provider-sortable">
						<?php foreach ( $value as $provider ) : ?>
								<?php if ( isset( $provider_labels[ $provider ] ) ) : ?>
									<li class="wp-mcp-ai-provider-item" data-provider="<?php echo esc_attr( $provider ); ?>">
										<span class="dashicons dashicons-menu"></span>
										<span class="provider-label"><?php echo esc_html( $provider_labels[ $provider ] ); ?></span>
										<input type="hidden" name="wp_mcp_ai_settings[provider_priority_list][]" value="<?php echo esc_attr( $provider ); ?>">
									</li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php if ( $description ) : ?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
					<?php
					endif;
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
				?>
					<style>
						#wp-mcp-ai-provider-sortable {
							list-style: none;
							margin: 0;
							padding: 0;
						}
						.wp-mcp-ai-provider-item {
							background: #fff;
							border: 1px solid #ddd;
							padding: 10px 15px;
							margin: 5px 0;
							cursor: move;
							display: flex;
							align-items: center;
							gap: 10px;
							border-radius: 3px;
							transition: box-shadow 0.2s ease;
							max-width: 400px;
						}
						.wp-mcp-ai-provider-item:hover {
							box-shadow: 0 2px 4px rgba(0,0,0,0.1);
						}
						.wp-mcp-ai-provider-item .dashicons {
							color: #999;
							flex-shrink: 0;
						}
						.wp-mcp-ai-provider-item.ui-sortable-helper {
							background: #f0f0f0;
							border-color: #0073aa;
							box-shadow: 0 4px 8px rgba(0,0,0,0.2);
						}
						.wp-mcp-ai-provider-item.ui-sortable-placeholder {
							background: #f9f9f9;
							border: 2px dashed #ddd;
							visibility: visible !important;
							height: 42px;
						}
						.wp-mcp-ai-provider-item .provider-label {
							flex: 1;
							font-weight: 500;
						}
					</style>
				</td>
			</tr>
			<?php
		}

		/**
		 * Sanitize input for this section.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			$sanitized = array();

			// Handle provider_priority_list separately.
			if ( isset( $input['provider_priority_list'] ) && is_array( $input['provider_priority_list'] ) ) {
				$sanitized['provider_priority_list'] = $this->sanitize_provider_priority_list( $input['provider_priority_list'] );
			}

			// Call parent sanitization for other fields.
			$parent_sanitized = parent::sanitize( $input );
			$sanitized        = array_merge( $parent_sanitized, $sanitized );

			return $sanitized;
		}

		/**
		 * Sanitize provider priority list.
		 *
		 * @param array $priority_list The provider priority list to sanitize.
		 * @return array Sanitized provider priority list.
		 */
		private function sanitize_provider_priority_list( $priority_list ) {
			// Get valid providers dynamically from Model Config.
			$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
			if ( class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				$configured_providers = WP_MCP_AI_Model_Config::get_all_provider_slugs();
				if ( ! empty( $configured_providers ) ) {
					$valid_providers = $configured_providers;
				}
			}

			$sanitized = array();

			if ( ! is_array( $priority_list ) ) {
				return $valid_providers;
			}

			foreach ( $priority_list as $provider ) {
				$provider = sanitize_text_field( $provider );
				if ( in_array( $provider, $valid_providers, true ) && ! in_array( $provider, $sanitized, true ) ) {
					$sanitized[] = $provider;
				}
			}

			// Ensure all providers are included (add any missing ones at the end).
			foreach ( $valid_providers as $provider ) {
				if ( ! in_array( $provider, $sanitized, true ) ) {
					$sanitized[] = $provider;
				}
			}

			return $sanitized;
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate URLs.
			$url_fields = array( 'ollama_endpoint_url', 'lm_studio_endpoint_url', 'huggingface_endpoint_url' );
			foreach ( $url_fields as $field ) {
				if ( isset( $input[ $field ] ) && ! empty( $input[ $field ] ) ) {
					$result = WP_MCP_AI_Settings_Validator::validate_url( $input[ $field ] );
					if ( is_wp_error( $result ) ) {
						$errors[] = sprintf(
						/* translators: %s: field name */
							__( '%s: ', 'mcp-ai-wpoos' ),
							$field
						) . $result->get_error_message();
					}
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}

	}
}
