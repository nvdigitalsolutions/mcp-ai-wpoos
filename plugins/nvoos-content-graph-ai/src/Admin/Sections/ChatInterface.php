<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraph\Admin\Section;

/**
 * Chat Interface — interactive AI chat testing panel.
 *
 * Renders a full chat UI (not a settings form) on its own tab.
 * The actual chat logic lives in JS (content-graph-ai-chat.js) which
 * talks to the REST endpoints under /nvoos-content-graph/v1/ai/.
 *
 * The tester mirrors the SPA-v2 SSE contract and stays dependency-free:
 * provider/model/tool controls, system prompt toggle, streaming answers,
 * markdown rendering, tool cards, cost badge, debug log.
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
		$sseUrl = NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-sse.js';
		$jsUrl  = NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/content-graph-ai-chat.js';
		$jsVer  = NVOOS_CONTENT_GRAPH_AI_VERSION;

		\wp_enqueue_style(
			'nvoos-content-graph-ai-chat',
			$cssUrl,
			array(),
			$cssVer,
		);

		\wp_enqueue_script(
			'nvoos-content-graph-ai-sse',
			$sseUrl,
			array(),
			$jsVer,
			true, // in footer
		);

		\wp_enqueue_script(
			'nvoos-content-graph-ai-chat',
			$jsUrl,
			array( 'nvoos-content-graph-ai-sse' ),
			$jsVer,
			true, // in footer
		);

		$settings = \NvoosContentGraph\Settings::all();

		// Pass config to JS.
		\wp_add_inline_script(
			'nvoos-content-graph-ai-chat',
			'window.NvoosContentGraphAiChat = ' . \wp_json_encode(
				array(
					'restUrl'           => \rest_url( 'nvoos-content-graph/v1' ),
					'nonce'             => \wp_create_nonce( 'wp_rest' ),
					'defaultProvider'   => (string) ( $settings['ai_default_provider'] ?? 'openai' ),
					'defaultModel'      => (string) ( $settings['ai_default_model'] ?? 'gpt-4o' ),
					'providersFallback' => self::getProviderFallback(),
					'i18n'              => array(
						'placeholder'        => __( 'Type your message…', 'nvoos-content-graph-ai' ),
						'send'               => __( 'Send', 'nvoos-content-graph-ai' ),
						'stop'               => __( 'Stop', 'nvoos-content-graph-ai' ),
						'stopped'            => __( 'Stopped.', 'nvoos-content-graph-ai' ),
						'thinking'           => __( 'Thinking…', 'nvoos-content-graph-ai' ),
						'error'              => __( 'Something went wrong. Check the debug log for details.', 'nvoos-content-graph-ai' ),
						'retry'              => __( 'Retry', 'nvoos-content-graph-ai' ),
						'rejected'           => __( 'Request rejected by policy.', 'nvoos-content-graph-ai' ),
						'noResponse'         => __( 'No response content was received.', 'nvoos-content-graph-ai' ),
						'toolsUsed'          => __( 'Tools used', 'nvoos-content-graph-ai' ),
						'cost'               => __( 'Cost', 'nvoos-content-graph-ai' ),
						'provider'           => __( 'Provider', 'nvoos-content-graph-ai' ),
						'model'              => __( 'Model', 'nvoos-content-graph-ai' ),
						'tools'              => __( 'Tools', 'nvoos-content-graph-ai' ),
						'none'               => __( 'None', 'nvoos-content-graph-ai' ),
						'systemPrompt'       => __( 'System prompt', 'nvoos-content-graph-ai' ),
						'clearChat'          => __( 'Clear Chat', 'nvoos-content-graph-ai' ),
						'empty'              => __( 'Send a message to test the AI. Your knowledge graph is available as context.', 'nvoos-content-graph-ai' ),
						'copy'               => __( 'Copy', 'nvoos-content-graph-ai' ),
						'copied'             => __( 'Copied', 'nvoos-content-graph-ai' ),
						'raw'                => __( 'Raw', 'nvoos-content-graph-ai' ),
						'rendered'           => __( 'Rendered', 'nvoos-content-graph-ai' ),
						'debug'              => __( 'Debug log', 'nvoos-content-graph-ai' ),
						'configured'         => __( 'configured', 'nvoos-content-graph-ai' ),
						'missingKey'         => __( 'no key', 'nvoos-content-graph-ai' ),
						'configError'        => __( 'Could not load tester configuration. Using fallback provider list.', 'nvoos-content-graph-ai' ),
						'graphTools'         => __( 'Graph tools (read-only)', 'nvoos-content-graph-ai' ),
						'noTools'            => __( 'No tools', 'nvoos-content-graph-ai' ),
						'contextUnavailable' => __( 'Graph context is unavailable — build the knowledge graph first.', 'nvoos-content-graph-ai' ),
						'modelsFailed'       => __( 'Could not load the model list for this provider. You can still type a model id manually.', 'nvoos-content-graph-ai' ),
					),
				),
				\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
			) . ';',
			'before',
		);
	}

	/**
	 * Fallback provider map used when the /ai/chat/config endpoint
	 * cannot be reached (offline resilience for the dropdown).
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function getProviderFallback(): array {
		$map = array(
			'openai'       => __( 'OpenAI', 'nvoos-content-graph-ai' ),
			'gemini'       => __( 'Google Gemini', 'nvoos-content-graph-ai' ),
			'anthropic'    => __( 'Anthropic Claude', 'nvoos-content-graph-ai' ),
			'ollama'       => __( 'Ollama (local)', 'nvoos-content-graph-ai' ),
			'deepseek'     => __( 'DeepSeek', 'nvoos-content-graph-ai' ),
			'openrouter'   => __( 'OpenRouter', 'nvoos-content-graph-ai' ),
			'huggingface'  => __( 'HuggingFace', 'nvoos-content-graph-ai' ),
			'cloudflare'   => __( 'Cloudflare Workers AI', 'nvoos-content-graph-ai' ),
			'lm_studio'    => __( 'LM Studio (local)', 'nvoos-content-graph-ai' ),
			'nvidia_nim'   => __( 'NVIDIA NIM', 'nvoos-content-graph-ai' ),
			'digitalocean' => __( 'DigitalOcean', 'nvoos-content-graph-ai' ),
			'kimi'         => __( 'Kimi (Moonshot)', 'nvoos-content-graph-ai' ),
			'baseten'      => __( 'Baseten', 'nvoos-content-graph-ai' ),
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
				<input
					type="text"
					id="nvoos-chat-model"
					class="nvoos-chat-toolbar__input"
					list="nvoos-chat-model-list"
					placeholder="<?php echo \esc_attr__( 'Default', 'nvoos-content-graph-ai' ); ?>"
					aria-label="<?php echo \esc_attr__( 'Model', 'nvoos-content-graph-ai' ); ?>"
				>
				<datalist id="nvoos-chat-model-list"></datalist>

				<label class="nvoos-chat-toolbar__label" for="nvoos-chat-tools">
					<?php echo \esc_html__( 'Tools', 'nvoos-content-graph-ai' ); ?>
				</label>
				<select id="nvoos-chat-tools" class="nvoos-chat-toolbar__select nvoos-chat-toolbar__select--short">
					<option value="none"><?php echo \esc_html__( 'None', 'nvoos-content-graph-ai' ); ?></option>
					<option value="graph"><?php echo \esc_html__( 'Graph', 'nvoos-content-graph-ai' ); ?></option>
				</select>

					<label class="nvoos-chat-toolbar__check" for="nvoos-chat-system-prompt">
						<input type="checkbox" id="nvoos-chat-system-prompt" checked>
						<?php echo \esc_html__( 'System prompt', 'nvoos-content-graph-ai' ); ?>
					</label>

					<label class="nvoos-chat-toolbar__check" for="nvoos-chat-context" title="<?php echo \esc_attr__( 'Include relevant context from the knowledge graph when available.', 'nvoos-content-graph-ai' ); ?>">
						<input type="checkbox" id="nvoos-chat-context" checked>
						<?php echo \esc_html__( 'Graph context', 'nvoos-content-graph-ai' ); ?>
					</label>

				<button type="button" id="nvoos-chat-clear" class="button">
					<?php echo \esc_html__( 'Clear Chat', 'nvoos-content-graph-ai' ); ?>
				</button>

				<button type="button" id="nvoos-chat-stop" class="button" disabled>
					<?php echo \esc_html__( 'Stop', 'nvoos-content-graph-ai' ); ?>
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

			<!-- Debug -->
			<details id="nvoos-chat-debug" class="nvoos-chat-debug">
				<summary><?php echo \esc_html__( 'Debug log', 'nvoos-content-graph-ai' ); ?></summary>
				<pre id="nvoos-chat-debug-log" class="nvoos-chat-debug__log"></pre>
			</details>
		</div>
		<?php
	}
}
