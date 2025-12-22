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
			return __( 'AI Provider Configuration', 'wp-mcp-ai' );
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
			return __( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Google Gemini, Hugging Face, Ollama, LM Studio).', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get dynamic model choices (from CCT if available, or fallback).
			$model_choices = array();
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$model_choices = WP_MCP_AI_Admin_Settings::get_openai_default_model_choices_static();
			}

			// Fallback to minimal hardcoded list if static method unavailable.
			if ( empty( $model_choices ) ) {
				$model_choices = array(
					// Latest reasoning models (thinking models).
					'o1-2024-12-17' => 'o1 (Dec 2024)',
					'o1-preview'    => 'o1 Preview',
					'o1-mini'       => 'o1 Mini',
					'o3-mini'       => 'o3 Mini (24% faster, structured outputs)',
					// GPT-4o series (current flagship).
					'gpt-4o'        => 'GPT-4o',
					'gpt-4o-mini'   => 'GPT-4o Mini',
					// Legacy models.
					'gpt-4-turbo'   => 'GPT-4 Turbo',
					'gpt-4'         => 'GPT-4',
					'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
				);
			}

			return array(
				// Provider Priority List.
				'provider_priority_list'            => array(
					'type'        => 'custom',
					'label'       => __( 'Provider Priority Order', 'wp-mcp-ai' ),
					'description' => __( 'Drag and drop to reorder providers. The system will try providers in this order when one fails or is unavailable.', 'wp-mcp-ai' ),
					'default'     => array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio' ),
				),

				// OpenAI Settings.
				'enable_openai'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable OpenAI Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable OpenAI as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, OpenAI will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'openai_api_key'                    => array(
					'type'         => 'password',
					'label'        => __( 'OpenAI API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: OpenAI API keys URL */
						__( 'Your OpenAI API key. Get one from <a href="%s" target="_blank">OpenAI Platform</a>.', 'wp-mcp-ai' ),
						'https://platform.openai.com/api-keys'
					),
					'placeholder'  => 'sk-...',
					'autocomplete' => 'new-password',
				),
				'default_model'                     => array(
					'type'        => 'select',
					'label'       => __( 'Default OpenAI Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for OpenAI requests. This model will be used unless overridden by an assistant or specific API call. Consider cost, speed, and capability trade-offs.', 'wp-mcp-ai' ),
					'options'     => $model_choices,
					'default'     => 'gpt-4.1',
				),
				'openai_embedding_model'            => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Embedding Model', 'wp-mcp-ai' ),
					'description' => __( 'Model to use for generating text embeddings. text-embedding-3-small offers the best balance of performance and cost. text-embedding-3-large provides higher accuracy for complex tasks.', 'wp-mcp-ai' ),
					'options'     => array(
						'text-embedding-3-small' => 'text-embedding-3-small',
						'text-embedding-3-large' => 'text-embedding-3-large',
						'text-embedding-ada-002' => 'text-embedding-ada-002',
					),
					'default'     => 'text-embedding-3-small',
				),
				'openai_organization_id'            => array(
					'type'         => 'text',
					'label'        => __( 'OpenAI Organization ID (Optional)', 'wp-mcp-ai' ),
					'description'  => __( 'Your OpenAI organization ID if you belong to multiple organizations. This is optional for most users. Find it in your OpenAI account settings if needed.', 'wp-mcp-ai' ),
					'placeholder'  => 'org-...',
					'autocomplete' => 'off',
				),
				'openai_image_model'                => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Model', 'wp-mcp-ai' ),
					'description' => __( 'Default model for image generation via OpenAI. gpt-image-1 is the latest model with quality options. DALL-E 3 offers high quality with HD option. DALL-E 2 is faster and more economical.', 'wp-mcp-ai' ),
					'options'     => array(
						'gpt-image-1' => 'gpt-image-1 (Latest)',
						'dall-e-3'    => 'DALL-E 3',
						'dall-e-2'    => 'DALL-E 2',
					),
					'default'     => 'gpt-image-1',
				),
				'openai_image_size'                 => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Size', 'wp-mcp-ai' ),
					'description' => __( 'Default size for generated images. Square format (1024x1024) works best for most purposes. Portrait (2:3) and landscape (3:2) formats offer creative flexibility.', 'wp-mcp-ai' ),
					'options'     => array(
						'1024x1024' => '1024x1024 (Square)',
						'1024x1536' => '1024x1536 (Portrait 2:3)',
						'1536x1024' => '1536x1024 (Landscape 3:2)',
						'auto'      => 'Auto (Let AI decide)',
					),
					'default'     => '1024x1024',
				),
				'openai_image_quality'              => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Quality', 'wp-mcp-ai' ),
					'description' => __( 'Default quality setting for image generation. For gpt-image-1: low is faster/cheaper, medium balances quality and cost, high provides best results. For DALL-E models: standard or hd.', 'wp-mcp-ai' ),
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
				'openai_image_response_format'      => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Image Response Format', 'wp-mcp-ai' ),
					'description' => __( 'Format for receiving generated images from OpenAI. b64_json returns base64-encoded data directly (recommended). URL provides a hosted image link (expires after 1 hour).', 'wp-mcp-ai' ),
					'options'     => array(
						'b64_json' => 'Base64 JSON (Recommended)',
						'url'      => 'URL (Expires in 1 hour)',
					),
					'default'     => 'b64_json',
				),
				'openai_transcribe_model'           => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Transcription Model', 'wp-mcp-ai' ),
					'description' => __( 'Default model for audio transcription and translation. gpt-4o-mini-transcribe is optimized for transcription tasks.', 'wp-mcp-ai' ),
					'options'     => array(
						'gpt-4o-mini-transcribe' => 'gpt-4o-mini-transcribe (Recommended)',
						'whisper-1'              => 'Whisper-1',
					),
					'default'     => 'gpt-4o-mini-transcribe',
				),
				'openai_transcribe_response_format' => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Transcription Response Format', 'wp-mcp-ai' ),
					'description' => __( 'Default format for transcription responses. verbose_json includes timestamps and metadata, json returns text only.', 'wp-mcp-ai' ),
					'options'     => array(
						'verbose_json' => 'Verbose JSON (With timestamps)',
						'json'         => 'JSON (Text only)',
					),
					'default'     => 'verbose_json',
				),
				'openai_transcribe_language'        => array(
					'type'        => 'text',
					'label'       => __( 'OpenAI Transcription Language (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Optional ISO-639-1 language code (e.g., "en" for English, "es" for Spanish) to hint the language of the audio. Leave empty for automatic detection.', 'wp-mcp-ai' ),
					'placeholder' => 'en',
				),
				'openai_transcribe_temperature'     => array(
					'type'        => 'text',
					'label'       => __( 'OpenAI Transcription Temperature (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Optional sampling temperature between 0 and 1. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. Leave empty to use default.', 'wp-mcp-ai' ),
					'placeholder' => '0',
				),
				'openai_speech_model'               => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Model', 'wp-mcp-ai' ),
					'description' => __( 'Default model for text-to-speech (TTS) generation. gpt-4o-mini-tts is optimized for voice synthesis. tts-1 is the standard quality model, tts-1-hd provides higher quality audio.', 'wp-mcp-ai' ),
					'options'     => array(
						'gpt-4o-mini-tts' => 'gpt-4o-mini-tts (Recommended)',
						'tts-1'           => 'TTS-1 (Standard)',
						'tts-1-hd'        => 'TTS-1-HD (High Quality)',
					),
					'default'     => 'gpt-4o-mini-tts',
				),
				'openai_speech_voice'               => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Voice', 'wp-mcp-ai' ),
					'description' => __( 'Default voice for text-to-speech generation. Each voice has a distinct personality and tone. Preview voices at OpenAI documentation.', 'wp-mcp-ai' ),
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
				'openai_speech_format'              => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Text-to-Speech Format', 'wp-mcp-ai' ),
					'description' => __( 'Audio output format for TTS. MP3 offers best compatibility. OPUS is most efficient. AAC works well on Apple devices. FLAC is lossless quality. WAV is uncompressed.', 'wp-mcp-ai' ),
					'options'     => array(
						'mp3'  => 'MP3 (Most Compatible)',
						'opus' => 'OPUS (Most Efficient)',
						'aac'  => 'AAC (Apple Devices)',
						'flac' => 'FLAC (Lossless)',
						'wav'  => 'WAV (Uncompressed)',
					),
					'default'     => 'mp3',
				),
				'enable_high_token_model_switch'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable High Token Model Switch', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically switch to fallback model on token overflow', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, if a request exceeds the current model\'s token limit, the system will automatically switch to the specified fallback model with higher capacity. This prevents errors and ensures requests are processed even with large contexts.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'high_token_fallback_model'         => array(
					'type'        => 'text',
					'label'       => __( 'High Token Fallback Model', 'wp-mcp-ai' ),
					'description' => __( 'Model to use when token limit is exceeded. Should be a model with higher token capacity than your default. Examples: gemini-2.5-flash (1M tokens), gpt-4o (128k tokens). This setting works across all providers.', 'wp-mcp-ai' ),
					'default'     => 'gemini-2.5-flash',
					'placeholder' => 'gemini-2.5-flash',
				),

				// Anthropic Settings.
				'enable_anthropic'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Anthropic Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Anthropic (Claude) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Anthropic will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'anthropic_api_key'                 => array(
					'type'         => 'password',
					'label'        => __( 'Anthropic API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Anthropic Console URL */
						__( 'Your Anthropic API key. Get one from <a href="%s" target="_blank">Anthropic Console</a>.', 'wp-mcp-ai' ),
						'https://console.anthropic.com/'
					),
					'placeholder'  => 'sk-ant-...',
					'autocomplete' => 'new-password',
				),
				'anthropic_model'                   => array(
					'type'        => 'select',
					'label'       => __( 'Default Anthropic Model', 'wp-mcp-ai' ),
					'description' => __( 'The default Claude model to use for Anthropic requests. Claude 3.5 Sonnet offers the best balance of intelligence and speed. Claude 3.5 Haiku is faster and more economical for simpler tasks.', 'wp-mcp-ai' ),
					'options'     => array(
						'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Latest)',
						'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
						'claude-3-opus-20240229'     => 'Claude 3 Opus',
						'claude-3-sonnet-20240229'   => 'Claude 3 Sonnet',
						'claude-3-haiku-20240307'    => 'Claude 3 Haiku',
					),
					'default'     => 'claude-3-5-sonnet-20241022',
				),

				// Google Gemini Settings.
				'enable_gemini'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Gemini Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Google Gemini as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Gemini will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'gemini_api_key'                    => array(
					'type'         => 'password',
					'label'        => __( 'Gemini API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Google AI Studio URL */
						__( 'Your Google Gemini API key. Get one from <a href="%s" target="_blank">Google AI Studio</a>.', 'wp-mcp-ai' ),
						'https://aistudio.google.com/app/apikey'
					),
					'placeholder'  => 'AIza...',
					'autocomplete' => 'new-password',
				),
				'default_gemini_model'              => array(
					'type'        => 'select',
					'label'       => __( 'Default Gemini Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for Gemini requests. Gemini 2.5 Pro is the flagship model with best performance. Gemini 2.5 Flash is the latest stable model with multimodal support (text, image, video). Gemini 2.0 Flash is the previous stable generation. Gemini 1.5 Pro provides proven performance, while 1.5 Flash is faster and more economical.', 'wp-mcp-ai' ),
					'options'     => array(
						'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
						'gemini-2.5-flash' => 'Gemini 2.5 Flash (Latest - Stable)',
						'gemini-2.0-flash' => 'Gemini 2.0 Flash',
						'gemini-exp-1206'  => 'Gemini Exp 1206 (Experimental)',
						'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
						'gemini-1.5-flash' => 'Gemini 1.5 Flash',
						'gemini-pro'       => 'Gemini Pro',
					),
					'default'     => 'gemini-2.5-flash',
				),
				'gemini_image_model'                => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image Model', 'wp-mcp-ai' ),
					'description' => __( 'Default model for image generation via Gemini. gemini-2.5-flash-image is the latest specialized image generation model. gemini-exp-1206 provides experimental features.', 'wp-mcp-ai' ),
					'options'     => array(
						'gemini-2.5-flash-image' => 'Gemini 2.5 Flash Image (Latest)',
						'gemini-exp-1206'        => 'Gemini Exp 1206 (Experimental)',
					),
					'default'     => 'gemini-2.5-flash-image',
				),
				'gemini_image_mime_type'            => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image MIME Type', 'wp-mcp-ai' ),
					'description' => __( 'Default image format for Gemini-generated images. PNG offers lossless compression, JPEG is smaller for photos, WebP provides best compression.', 'wp-mcp-ai' ),
					'options'     => array(
						'image/png'  => 'PNG (Lossless)',
						'image/jpeg' => 'JPEG (Photo-optimized)',
						'image/webp' => 'WebP (Modern format)',
					),
					'default'     => 'image/png',
				),
				'gemini_image_aspect_ratio'         => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Image Aspect Ratio', 'wp-mcp-ai' ),
					'description' => __( 'Default aspect ratio for Gemini-generated images. Square (1:1) works for most purposes. Portrait (3:4, 9:16) and landscape (4:3, 16:9) offer creative flexibility.', 'wp-mcp-ai' ),
					'options'     => array(
						'1:1'  => '1:1 (Square)',
						'3:4'  => '3:4 (Portrait)',
						'4:3'  => '4:3 (Landscape)',
						'9:16' => '9:16 (Vertical)',
						'16:9' => '16:9 (Widescreen)',
					),
					'default'     => '1:1',
				),
				'gemini_video_model'                => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Model', 'wp-mcp-ai' ),
					'description' => __( 'Default model for video generation via Gemini Veo. veo-2.0-generate-001 is stable with fewer restrictions (supports 5-8 seconds, 720p max). veo-3.1-generate-preview is the latest with synchronized audio and 1080p support, but requires exactly 8 seconds for 1080p and has stricter quota limits.', 'wp-mcp-ai' ),
					'options'     => array(
						'veo-2.0-generate-001'     => 'Veo 2.0 Generate (Stable, Fewer Restrictions)',
						'veo-3.1-generate-preview' => 'Veo 3.1 Generate Preview (Latest, Audio, 1080p)',
					),
					'default'     => 'veo-2.0-generate-001',
				),
				'gemini_video_resolution'           => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Resolution', 'wp-mcp-ai' ),
					'description' => __( 'Default resolution for Gemini-generated videos. 720p is supported by all Veo models and works for all aspect ratios. 1080p is only available with Veo 3.1 for 16:9 aspect ratio and requires exactly 8 seconds duration. Note: Veo 2.0 always outputs 720p regardless of this setting.', 'wp-mcp-ai' ),
					'options'     => array(
						'720p'  => '720p (All models, all durations)',
						'1080p' => '1080p (Veo 3.1 only, 16:9, 8s required)',
					),
					'default'     => '720p',
				),
				'gemini_video_aspect_ratio'         => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Aspect Ratio', 'wp-mcp-ai' ),
					'description' => __( 'Default aspect ratio for Gemini-generated videos. 16:9 is landscape/widescreen format (supports both 720p and 1080p). 9:16 is vertical/portrait format (supports 720p only).', 'wp-mcp-ai' ),
					'options'     => array(
						'16:9' => '16:9 (Landscape/Widescreen)',
						'9:16' => '9:16 (Vertical/Portrait)',
					),
					'default'     => '16:9',
				),
				'gemini_video_duration'             => array(
					'type'        => 'select',
					'label'       => __( 'Gemini Video Duration', 'wp-mcp-ai' ),
					'description' => __( 'Default duration for Gemini-generated videos in seconds. Veo 3.1 supports 4-8 seconds (with potential for extended clips via API), Veo 2.0 supports 5-8 seconds. Note: If 1080p resolution is requested, duration will be automatically set to 8 seconds (API requirement).', 'wp-mcp-ai' ),
					'options'     => array(
						'4' => '4 seconds (Veo 3.1 only)',
						'5' => '5 seconds',
						'6' => '6 seconds',
						'7' => '7 seconds',
						'8' => '8 seconds (Required for 1080p)',
					),
					'default'     => '5',
				),

				// Ollama Settings.
				'enable_ollama'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Ollama Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Ollama (Local AI) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Ollama will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ollama_endpoint_url'               => array(
					'type'        => 'url',
					'label'       => __( 'Ollama Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL where your Ollama server is running. Examples: "http://localhost:11434" (same machine), "http://192.168.2.222:11434" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN Ollama: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'wp-mcp-ai' ),
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
				'ollama_model'                      => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Model', 'wp-mcp-ai' ),
					'description' => __( 'The model name to use with Ollama. Must match exactly a model you have pulled (e.g., llama3, mistral, codellama). Use \"ollama list\" in terminal to see available models.', 'wp-mcp-ai' ),
					'placeholder' => 'llama3',
				),
				'ollama_network_interface'          => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Network Interface (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your Ollama is on a different machine (e.g., 192.168.2.100), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// LM Studio Settings.
				'enable_lm_studio'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable LM Studio Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable LM Studio (Local AI) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, LM Studio will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'lm_studio_endpoint_url'            => array(
					'type'        => 'url',
					'label'       => __( 'LM Studio Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL where your LM Studio server is running. Examples: "http://localhost:1234" (same machine), "http://192.168.2.222:1234" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN LM Studio: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'wp-mcp-ai' ),
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
				'lm_studio_model'                   => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Model', 'wp-mcp-ai' ),
					'description' => __( 'The model identifier for your loaded LM Studio model. This is typically shown in the LM Studio interface. Some installations accept \"local-model\" as a generic identifier.', 'wp-mcp-ai' ),
					'placeholder' => 'local-model',
				),
				'lm_studio_network_interface'       => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Network Interface (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your LM Studio is on a different machine (e.g., 192.168.2.222), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Hugging Face Settings.
				'enable_huggingface'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Hugging Face Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Hugging Face Inference API as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Hugging Face will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'huggingface_api_key'               => array(
					'type'         => 'password',
					'label'        => __( 'Hugging Face API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Hugging Face tokens URL */
						__( 'Your Hugging Face API token. Get one from <a href="%s" target="_blank">Hugging Face Settings</a>. Use a token with "Inference" permissions.', 'wp-mcp-ai' ),
						'https://huggingface.co/settings/tokens'
					),
					'placeholder'  => 'hf_...',
					'autocomplete' => 'new-password',
				),
				'huggingface_endpoint_url'          => array(
					'type'        => 'url',
					'label'       => __( 'Hugging Face Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL for the Hugging Face Inference API. Use the default for the public API, or provide a custom endpoint URL for Inference Endpoints or private deployments. The plugin automatically appends the correct path (/chat/completions or /models).', 'wp-mcp-ai' ),
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
				'huggingface_model'                 => array(
					'type'        => 'text',
					'label'       => __( 'Hugging Face Model', 'wp-mcp-ai' ),
					'description' => __( 'The model identifier to use with Hugging Face. Examples: "meta-llama/Llama-3.3-70B-Instruct", "mistralai/Mistral-7B-Instruct-v0.3", "microsoft/Phi-3-mini-4k-instruct". Must be a chat/instruction model with a chat_template defined.', 'wp-mcp-ai' ),
					'placeholder' => 'meta-llama/Llama-3.3-70B-Instruct',
				),

				// Google Maps Settings.
				'google_maps_api_key'               => array(
					'type'         => 'password',
					'label'        => __( 'Google Maps API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Google Cloud Console URL */
						__( 'Your Google Maps Platform API key. Required for geocoding tools (address lookup, reverse geocoding, nearby places search). Get one from <a href="%s" target="_blank">Google Cloud Console</a>. You need to enable the "Geocoding API" and "Places API" for full functionality.', 'wp-mcp-ai' ),
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
			return array(
				'priority'    => array(
					'id'     => 'priority',
					'label'  => __( 'Priority Order', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-sort',
					'fields' => array( 'provider_priority_list' ),
				),
				'openai'      => array(
					'id'     => 'openai',
					'label'  => __( 'OpenAI', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_openai', 'openai_api_key', 'default_model', 'openai_embedding_model', 'openai_organization_id', 'openai_image_model', 'openai_image_size', 'openai_image_quality', 'openai_image_response_format', 'openai_transcribe_model', 'openai_transcribe_response_format', 'openai_transcribe_language', 'openai_transcribe_temperature', 'openai_speech_model', 'openai_speech_voice', 'openai_speech_format', 'enable_high_token_model_switch', 'high_token_fallback_model' ),
				),
				'anthropic'   => array(
					'id'     => 'anthropic',
					'label'  => __( 'Anthropic', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_anthropic', 'anthropic_api_key', 'anthropic_model' ),
				),
				'gemini'      => array(
					'id'     => 'gemini',
					'label'  => __( 'Google Gemini', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_gemini', 'gemini_api_key', 'default_gemini_model', 'gemini_image_model', 'gemini_image_mime_type', 'gemini_image_aspect_ratio', 'gemini_video_model', 'gemini_video_resolution', 'gemini_video_aspect_ratio', 'gemini_video_duration' ),
				),
				'ollama'      => array(
					'id'     => 'ollama',
					'label'  => __( 'Ollama (Local)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_ollama', 'ollama_endpoint_url', 'ollama_model', 'ollama_network_interface' ),
				),
				'lm_studio'   => array(
					'id'     => 'lm_studio',
					'label'  => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_lm_studio', 'lm_studio_endpoint_url', 'lm_studio_model', 'lm_studio_network_interface' ),
				),
				'huggingface' => array(
					'id'     => 'huggingface',
					'label'  => __( 'Hugging Face', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'enable_huggingface', 'huggingface_api_key', 'huggingface_endpoint_url', 'huggingface_model' ),
				),
				'google_maps' => array(
					'id'     => 'google_maps',
					'label'  => __( 'Google Maps', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-location',
					'fields' => array( 'google_maps_api_key' ),
				),
			);
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
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
		<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
<h2><?php echo esc_html( $this->get_title() ); ?></h2>
			<?php if ( $description ) : ?>
<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>

<div class="wp-mcp-ai-provider-subtabs">
<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Provider sub-tabs', 'wp-mcp-ai' ); ?>">
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
				'openai'       => __( 'OpenAI', 'wp-mcp-ai' ),
				'anthropic'    => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
				'gemini'       => __( 'Gemini', 'wp-mcp-ai' ),
				'huggingface'  => __( 'Hugging Face', 'wp-mcp-ai' ),
				'ollama'       => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'lm_studio'    => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
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
					<?php endif; ?>
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
			$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio' );
			$sanitized       = array();

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
							__( '%s: ', 'wp-mcp-ai' ),
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
