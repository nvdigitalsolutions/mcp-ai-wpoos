<?php
/**
 * General Settings section — platform-wide configuration.
 *
 * Powers the "General" tab on the NV Platform dashboard with
 * form-table fields for default provider, agent limits, log
 * level, and other cross-cutting settings.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Sections
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Sections;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;
use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * General platform settings section.
 */
final class GeneralSection extends PlatformSection {

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id(): string {
		return 'platform_general';
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title(): string {
		return __( 'General Settings', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_tab(): string {
		return 'general';
	}

	/**
	 * @since 1.0.0
	 * @return int
	 */
	public function get_priority(): int {
		return 1;
	}

	/**
	 * Define the fields rendered in the general settings form-table.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string,mixed>>
	 */
	public function get_fields(): array {
		return array(
			'default_provider'       => array(
				'type'        => 'select',
				'label'       => __( 'Default AI Provider', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'The AI provider used when no specific provider is configured for an agent.', 'nvoos-content-graph-ai-platform' ),
				'options'     => $this->getAvailableProviders(),
				'default'     => 'openai',
			),
			'max_agents'             => array(
				'type'        => 'number',
				'label'       => __( 'Maximum Agents', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Limit the total number of agents that can be created. Set to 0 for unlimited.', 'nvoos-content-graph-ai-platform' ),
				'min'         => 0,
				'max'         => 500,
				'default'     => 50,
			),
			'enable_agent_auto_seed' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-seed default agents', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'When enabled, a set of starter agents is created on first activation.', 'nvoos-content-graph-ai-platform' ),
			),
			'log_level'              => array(
				'type'        => 'select',
				'label'       => __( 'Log Level', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Controls the verbosity of platform logging.', 'nvoos-content-graph-ai-platform' ),
				'options'     => array(
					'debug'   => __( 'Debug — all messages', 'nvoos-content-graph-ai-platform' ),
					'info'    => __( 'Info — general operations', 'nvoos-content-graph-ai-platform' ),
					'warning' => __( 'Warning — potential issues', 'nvoos-content-graph-ai-platform' ),
					'error'   => __( 'Error — only failures', 'nvoos-content-graph-ai-platform' ),
				),
				'default'     => 'warning',
			),
		);
	}

	/**
	 * Get available AI providers from the AI addon.
	 *
	 * Falls back to a static list if the AI addon isn't loaded.
	 *
	 * @since 1.0.0
	 * @return array<string,string>
	 */
	private function getAvailableProviders(): array {
		// Try the AI addon's provider list first.
		if ( class_exists( 'NvoosContentGraphAi\\Settings' ) && method_exists( 'NvoosContentGraphAi\\Settings', 'getProviders' ) ) {
			$providers = \NvoosContentGraphAi\Settings::getProviders();
			if ( is_array( $providers ) && ! empty( $providers ) ) {
				return $providers;
			}
		}

		// Fallback: common providers.
		return array(
			'openai'     => 'OpenAI',
			'google'     => 'Google Gemini',
			'anthropic'  => 'Anthropic Claude',
			'deepseek'   => 'DeepSeek',
			'openrouter' => 'OpenRouter',
			'ollama'     => 'Ollama (local)',
			'lmstudio'   => 'LM Studio (local)',
		);
	}

	/**
	 * Render the general settings form-table with a save button.
	 *
	 * The form tag and nonce are provided by the parent dashboard renderer;
	 * this method only outputs the section heading + fields + submit button.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_slug The settings page slug.
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>
		<?php if ( $this->get_description() ) : ?>
			<p><?php echo esc_html( $this->get_description() ); ?></p>
		<?php endif; ?>

		<table class="form-table">
			<tbody>
				<?php $this->render(); ?>
			</tbody>
		</table>
		<?php
	}
}
