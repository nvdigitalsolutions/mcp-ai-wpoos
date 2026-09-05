<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\Admin\Settings\AiSection;
use NvoosContentGraphAi\Security\CredentialStore;

/**
 * API Keys section for the AI Providers tab.
 *
 * Stores API keys, base URLs, account IDs, and model
 * overrides for all 13 supported AI providers.
 *
 * Secret fields (ai_api_key_*) are encrypted at rest via the
 * CredentialStore and are never written into the general
 * nvoos_content_graph_settings option, never rendered back into the
 * form (a masked placeholder is shown instead), and never wiped by a
 * save from an unrelated tab.
 *
 * @since 1.0.0
 */
class ApiKeys extends AiSection {

	public function get_id(): string {
		return 'ai_api_keys';
	}

	public function get_title(): string {
		return __( 'API Keys & Endpoints', 'nvoos-content-graph-ai' );
	}

	public function get_tab(): string {
		return 'ai_providers';
	}

	public function get_priority(): int {
		return 20;
	}

	public function get_description(): string {
		return __( 'API keys are encrypted at rest and stored outside the general settings option. A stored key is shown as a mask — enter a new key to replace it, or clear the field to remove it.', 'nvoos-content-graph-ai' );
	}

	public function get_fields(): array {
		return array(
			// ─── OpenAI ──────────────────────────────────────────
			'ai_api_key_openai'        => array(
				'type'        => 'password',
				'label'       => __( 'OpenAI API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your OpenAI API key (sk-…).', 'nvoos-content-graph-ai' ),
			),

			// ─── Google Gemini ───────────────────────────────────
			'ai_api_key_gemini'        => array(
				'type'        => 'password',
				'label'       => __( 'Google Gemini API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Google AI Studio API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── Anthropic Claude ────────────────────────────────
			'ai_api_key_anthropic'     => array(
				'type'        => 'password',
				'label'       => __( 'Anthropic API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Anthropic API key (sk-ant-…).', 'nvoos-content-graph-ai' ),
			),

			// ─── Ollama (Local) ──────────────────────────────────
			'ai_api_key_ollama'        => array(
				'type'        => 'password',
				'label'       => __( 'Ollama API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Optional. Most local Ollama setups do not require an API key.', 'nvoos-content-graph-ai' ),
			),
			'ollama_base_url'          => array(
				'type'        => 'text',
				'label'       => __( 'Ollama Base URL', 'nvoos-content-graph-ai' ),
				'description' => __( 'The base URL of your Ollama instance.', 'nvoos-content-graph-ai' ),
				'default'     => 'http://localhost:11434',
			),
			'ollama_model'             => array(
				'type'        => 'text',
				'label'       => __( 'Ollama Model', 'nvoos-content-graph-ai' ),
				'description' => __( 'Model name as known to Ollama (e.g. llama3.3, mistral).', 'nvoos-content-graph-ai' ),
				'default'     => 'llama3.3',
			),

			// ─── DeepSeek ────────────────────────────────────────
			'ai_api_key_deepseek'      => array(
				'type'        => 'password',
				'label'       => __( 'DeepSeek API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your DeepSeek API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── OpenRouter ──────────────────────────────────────
			'ai_api_key_openrouter'    => array(
				'type'        => 'password',
				'label'       => __( 'OpenRouter API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your OpenRouter API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── Hugging Face ────────────────────────────────────
			'ai_api_key_huggingface'   => array(
				'type'        => 'password',
				'label'       => __( 'Hugging Face API Token', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Hugging Face API token (hf_…).', 'nvoos-content-graph-ai' ),
			),
			'huggingface_endpoint_url' => array(
				'type'        => 'text',
				'label'       => __( 'Hugging Face Endpoint URL', 'nvoos-content-graph-ai' ),
				'description' => __( 'The inference endpoint URL.', 'nvoos-content-graph-ai' ),
				'default'     => 'https://api-inference.huggingface.co',
			),
			'huggingface_model'        => array(
				'type'        => 'text',
				'label'       => __( 'Hugging Face Model', 'nvoos-content-graph-ai' ),
				'description' => __( 'Model identifier on Hugging Face.', 'nvoos-content-graph-ai' ),
				'default'     => 'meta-llama/Llama-3.3-70B-Instruct',
			),

			// ─── Cloudflare Workers AI ───────────────────────────
			'ai_api_key_cloudflare'    => array(
				'type'        => 'password',
				'label'       => __( 'Cloudflare API Token', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Cloudflare API token with Workers AI access.', 'nvoos-content-graph-ai' ),
			),
			'cloudflare_account_id'    => array(
				'type'        => 'text',
				'label'       => __( 'Cloudflare Account ID', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Cloudflare account identifier.', 'nvoos-content-graph-ai' ),
			),
			'cloudflare_model'         => array(
				'type'        => 'text',
				'label'       => __( 'Cloudflare Model', 'nvoos-content-graph-ai' ),
				'description' => __( 'Model identifier for Cloudflare Workers AI.', 'nvoos-content-graph-ai' ),
				'default'     => '@cf/meta/llama-3.3-70b-instruct',
			),

			// ─── LM Studio (Local) ───────────────────────────────
			'ai_api_key_lmstudio'      => array(
				'type'        => 'password',
				'label'       => __( 'LM Studio API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Optional. Set only if you have configured an API key in LM Studio.', 'nvoos-content-graph-ai' ),
			),
			'lmstudio_base_url'        => array(
				'type'        => 'text',
				'label'       => __( 'LM Studio Base URL', 'nvoos-content-graph-ai' ),
				'description' => __( 'The base URL of your LM Studio server.', 'nvoos-content-graph-ai' ),
				'default'     => 'http://localhost:1234/v1',
			),
			'lmstudio_model'           => array(
				'type'        => 'text',
				'label'       => __( 'LM Studio Model', 'nvoos-content-graph-ai' ),
				'description' => __( 'Model identifier for LM Studio.', 'nvoos-content-graph-ai' ),
				'default'     => 'local-model',
			),

			// ─── NVIDIA NIM ──────────────────────────────────────
			'ai_api_key_nvidia'        => array(
				'type'        => 'password',
				'label'       => __( 'NVIDIA NIM API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your NVIDIA NIM API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── DigitalOcean ────────────────────────────────────
			'ai_api_key_digitalocean'  => array(
				'type'        => 'password',
				'label'       => __( 'DigitalOcean API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your DigitalOcean API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── Kimi (Moonshot) ─────────────────────────────────
			'ai_api_key_kimi'          => array(
				'type'        => 'password',
				'label'       => __( 'Kimi API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Kimi (Moonshot) API key.', 'nvoos-content-graph-ai' ),
			),

			// ─── Baseten ─────────────────────────────────────────
			'ai_api_key_baseten'       => array(
				'type'        => 'password',
				'label'       => __( 'Baseten API Key', 'nvoos-content-graph-ai' ),
				'description' => __( 'Your Baseten API key.', 'nvoos-content-graph-ai' ),
			),
		);
	}

	/**
	 * Sanitize submitted values for this section.
	 *
	 * Secret fields are encrypted into the CredentialStore as a side
	 * effect and never returned into the settings merge, so they never
	 * reach nvoos_content_graph_settings:
	 *
	 *  - New value → encrypted + stored.
	 *  - Masked placeholder → keep the stored key.
	 *  - Blank → delete the stored key.
	 *  - Absent → untouched.
	 *
	 * Non-secret fields (base URLs, account IDs, model names) are
	 * sanitized and returned as usual.
	 *
	 * @param array<string,mixed> $input Raw submitted values keyed by setting key.
	 * @return array<string,mixed> Sanitized non-secret values.
	 */
	public function sanitize( array $input ): array {
		$sanitized = array();

		foreach ( $this->get_fields() as $key => $field ) {
			$value = $input[ $key ] ?? null;
			if ( null === $value ) {
				continue;
			}

			if ( self::isSecretField( $key ) ) {
				$clean = \sanitize_text_field( (string) $value );

				if ( '' === $clean ) {
					CredentialStore::delete( self::suffixForField( $key ) );
				} elseif ( CredentialStore::MASKED_PLACEHOLDER !== $clean ) {
					CredentialStore::set( self::suffixForField( $key ), $clean );
				}

				continue;
			}

			$sanitized[ $key ] = $this->sanitize_field_value( $key, $value, $field );
		}

		return $sanitized;
	}

	/**
	 * Render a single field, masking secret values.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	protected function render_field( string $key, array $field ): void {
		if ( self::isSecretField( $key ) ) {
			$this->renderSecretField( $key, $field );
			return;
		}

		parent::render_field( $key, $field );
	}

	/**
	 * Render the section plus a per-provider credential status table.
	 *
	 * @param string $page_slug The settings page slug.
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		parent::render_wrapper( $page_slug );
		$this->renderStatusTable();
	}

	/**
	 * Render a secret password field with a masked value and a status hint.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	private function renderSecretField( string $key, array $field ): void {
		$suffix   = self::suffixForField( $key );
		$provider = CredentialStore::SUFFIX_TO_PROVIDER[ $suffix ] ?? $suffix;
		$stored   = CredentialStore::has( $suffix );
		$value    = $stored ? CredentialStore::MASKED_PLACEHOLDER : '';

		echo '<tr><th scope="row">' . esc_html( $field['label'] ?? '' ) . '</th><td>';
		printf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="new-password">',
			esc_attr( \NvoosContentGraph\Schema::OPTION_SETTINGS . '[' . $key . ']' ),
			esc_attr( $value )
		);

		if ( $stored ) {
			echo '<p class="description">' . esc_html__( 'Key stored (encrypted at rest). Enter a new key to replace it, or clear the field to remove it.', 'nvoos-content-graph-ai' ) . '</p>';
		} else {
			$source = CredentialResolver::getKeySource( $provider );

			if ( 'none' !== $source ) {
				echo '<p class="description">';
				printf(
					/* translators: %s: credential source name (e.g. "NV oOS plugin") */
					esc_html__( 'Using a key from %s. Enter one here to override it.', 'nvoos-content-graph-ai' ),
					'<strong>' . esc_html( self::sourceLabel( $source ) ) . '</strong>'
				);
				echo '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'No key configured for this provider.', 'nvoos-content-graph-ai' ) . '</p>';
			}
		}

		$desc = $field['description'] ?? '';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Render the per-provider credential status table.
	 *
	 * @return void
	 */
	private function renderStatusTable(): void {
		?>
		<h3><?php esc_html_e( 'Credential Status', 'nvoos-content-graph-ai' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Provider', 'nvoos-content-graph-ai' ); ?></th>
					<th><?php esc_html_e( 'Encrypted key stored', 'nvoos-content-graph-ai' ); ?></th>
					<th><?php esc_html_e( 'Active credential source', 'nvoos-content-graph-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( CredentialStore::SUFFIX_TO_PROVIDER as $suffix => $provider ) : ?>
					<tr>
						<td><?php echo esc_html( $provider ); ?></td>
						<td><?php echo CredentialStore::has( $suffix ) ? esc_html__( 'Yes', 'nvoos-content-graph-ai' ) : esc_html__( 'No', 'nvoos-content-graph-ai' ); ?></td>
						<td><?php echo esc_html( self::sourceLabel( CredentialResolver::getKeySource( $provider ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			esc_html_e( 'Keys are encrypted with AES-256-GCM and stored in a separate non-autoload option — never in the general settings, never re-displayed, and never included in settings exports. Manage them from the command line with wp nvoos-cg-ai key-status and wp nvoos-cg-ai migrate-keys.', 'nvoos-content-graph-ai' );
			?>
		</p>
		<?php
	}

	/**
	 * Whether a settings key is a secret provider key field.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	private static function isSecretField( string $key ): bool {
		return 0 === strpos( $key, 'ai_api_key_' );
	}

	/**
	 * Convert a secret settings key to a provider settings suffix.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function suffixForField( string $key ): string {
		return substr( $key, strlen( 'ai_api_key_' ) );
	}

	/**
	 * Human-readable label for a credential source.
	 *
	 * @param string $source Source returned by CredentialResolver::getKeySource().
	 * @return string
	 */
	private static function sourceLabel( string $source ): string {
		$labels = array(
			'credential_store' => __( 'Content Graph AI (encrypted)', 'nvoos-content-graph-ai' ),
			'base_plugin'      => __( 'NV oOS plugin', 'nvoos-content-graph-ai' ),
			'env_var'          => __( 'Environment variable', 'nvoos-content-graph-ai' ),
			'constant'         => __( 'PHP constant', 'nvoos-content-graph-ai' ),
			'none'             => __( 'Not configured', 'nvoos-content-graph-ai' ),
		);

		return $labels[ $source ] ?? $source;
	}
}
