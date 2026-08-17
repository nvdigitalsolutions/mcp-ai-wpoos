<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Chat Interface — interactive AI chat testing panel.
 *
 * Renders a full chat UI (not a settings form) on its own tab.
 * The actual chat logic lives in JS (content-graph-ai-chat.js) which
 * talks to the REST endpoint at /nvoos-content-graph/v1/ai/chat.
 *
 * @since 1.0.0
 */
class ChatInterface extends Section {

	public function get_id(): string {
		return 'ai_chat_interface';
	}

	public function get_title(): string {
		return __( 'Chat Tester', 'nvoos-content-graph-ai' );
	}

	public function get_tab(): string {
		return 'ai_chat_ui';
	}

	public function get_priority(): int {
		return 5;
	}

	public function get_fields(): array {
		return array(); // No form fields — custom render.
	}

	public function render(): void {
		$this->enqueueAssets();
		$this->renderChatMarkup();
	}

	/**
	 * Override: don't wrap the chat UI in a form-table.
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		$this->render();
	}

	// ─── Asset enqueue ──────────────────────────────────────────────

	private function enqueueAssets(): void {
		$cssUrl = NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/content-graph-ai-chat.css';
		$cssVer = NVOOS_CONTENT_GRAPH_AI_VERSION;
		$jsUrl  = NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-chat.js';
		$jsVer  = NVOOS_CONTENT_GRAPH_AI_VERSION;

		\wp_enqueue_style(
			'nvoos-content-graph-ai-chat',
			$cssUrl,
			array(),
			$cssVer,
		);

		\wp_enqueue_script(
			'nvoos-content-graph-ai-chat',
			$jsUrl,
			array(),
			$jsVer,
			true, // in footer
		);

		// Pass config to JS.
		\wp_add_inline_script(
			'nvoos-content-graph-ai-chat',
			'window.NvoosContentGraphAiChat = ' . \wp_json_encode(
				array(
					'restUrl'   => \rest_url( 'nvoos-content-graph/v1' ),
					'nonce'     => \wp_create_nonce( 'wp_rest' ),
					'providers' => $this->getAvailableProviders(),
					'i18n'      => array(
						'placeholder'    => __( 'Type your message…', 'nvoos-content-graph-ai' ),
						'send'           => __( 'Send', 'nvoos-content-graph-ai' ),
						'thinking'       => __( 'Thinking…', 'nvoos-content-graph-ai' ),
						'error'          => __( 'Something went wrong. Check the console for details.', 'nvoos-content-graph-ai' ),
						'toolsUsed'      => __( 'Tools used', 'nvoos-content-graph-ai' ),
						'cost'           => __( 'Cost', 'nvoos-content-graph-ai' ),
						'selectProvider' => __( 'Provider', 'nvoos-content-graph-ai' ),
					),
				),
				\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
			) . ';',
			'before',
		);
	}

	/**
	 * Get the list of available AI providers with their labels.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function getAvailableProviders(): array {
		$map = array(
			'openai'       => 'OpenAI',
			'gemini'       => 'Google Gemini',
			'anthropic'    => 'Anthropic Claude',
			'ollama'       => 'Ollama (local)',
			'deepseek'     => 'DeepSeek',
			'openrouter'   => 'OpenRouter',
			'huggingface'  => 'HuggingFace',
			'cloudflare'   => 'Cloudflare Workers AI',
			'lm_studio'    => 'LM Studio (local)',
			'nvidia_nim'   => 'NVIDIA NIM',
			'digitalocean' => 'DigitalOcean',
			'kimi'         => 'Kimi (Moonshot)',
			'baseten'      => 'Baseten',
		);

		$providers = array();
		foreach ( $map as $slug => $label ) {
			$providers[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}

		return $providers;
	}

	// ─── HTML rendering ─────────────────────────────────────────────

	private function renderChatMarkup(): void {
		?>
		<div id="nvoos-content-graph-ai-chat-app" class="nvoos-chat-app">
			<!-- Toolbar -->
			<div class="nvoos-chat-toolbar">
				<label class="nvoos-chat-toolbar__label" for="nvoos-chat-provider">
					<?php echo \esc_html__( 'Provider', 'nvoos-content-graph-ai' ); ?>
				</label>
				<select id="nvoos-chat-provider" class="nvoos-chat-toolbar__select">
				</select>

				<label class="nvoos-chat-toolbar__label" for="nvoos-chat-model">
					<?php echo \esc_html__( 'Model', 'nvoos-content-graph-ai' ); ?>
				</label>
				<select id="nvoos-chat-model" class="nvoos-chat-toolbar__select">
					<option value=""><?php echo \esc_html__( 'Default', 'nvoos-content-graph-ai' ); ?></option>
				</select>

				<button type="button" id="nvoos-chat-clear" class="button">
					<?php echo \esc_html__( 'Clear Chat', 'nvoos-content-graph-ai' ); ?>
				</button>

				<span id="nvoos-chat-cost" class="nvoos-chat-cost" aria-live="polite"></span>
			</div>

			<!-- Messages -->
			<div id="nvoos-chat-messages" class="nvoos-chat-messages" role="log" aria-live="polite">
				<div class="nvoos-chat-empty">
					<?php echo \esc_html__( 'Send a message to test the AI. Your knowledge graph is available as context.', 'nvoos-content-graph-ai' ); ?>
				</div>
			</div>

			<!-- Input -->
			<div class="nvoos-chat-input-row">
				<textarea
					id="nvoos-chat-input"
					class="nvoos-chat-input"
					rows="2"
					placeholder="<?php echo \esc_attr__( 'Type your message…', 'nvoos-content-graph-ai' ); ?>"
					aria-label="<?php echo \esc_attr__( 'Chat message', 'nvoos-content-graph-ai' ); ?>"
				></textarea>
				<button
					type="button"
					id="nvoos-chat-send"
					class="button button-primary nvoos-chat-send"
				>
					<?php echo \esc_html__( 'Send', 'nvoos-content-graph-ai' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}
