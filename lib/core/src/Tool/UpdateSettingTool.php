<?php
/**
 * Update Setting tool — modifies a plugin setting.
 *
 * Uses SettingsStoreInterface::set() to persist configuration changes.
 * Requires manage_options capability. Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class UpdateSettingTool extends AbstractTool {

	/**
	 * Keys that may be updated through this tool.
	 *
	 * Sensitive keys (API keys, secrets) are excluded and must be
	 * configured through the admin UI or config files.
	 *
	 * @var string[]
	 */
	private const ALLOWED_KEYS = array(
		'default_provider',
		'default_model',
		'default_gemini_model',
		'enable_rate_limiting',
		'enable_high_token_model_switch',
		'enable_multi_agent_teams',
		'enable_chat_memory',
		'rest_enable_assistant_list',
	);

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'update_setting';
	}

	public function getName(): string {
		return 'Update Setting';
	}

	public function getDescription(): string {
		return 'Updates a single plugin or application setting. Only non-sensitive keys are allowed. Use list_settings to discover available keys.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array(
					'type'        => 'string',
					'description' => 'The setting key to update.',
				),
				'value' => array(
					'description' => 'The new value to set.',
				),
			),
			'required'             => array( 'key', 'value' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key   = $this->stringParam( $arguments, 'key' );
		$value = $arguments['value'] ?? null;

		if ( '' === $key ) {
			return $this->errors->validationFailed(
				'key is required.',
				array( 'key' => array( 'A setting key is required.' ) ),
			);
		}

		if ( null === $value ) {
			return $this->errors->validationFailed(
				'value is required.',
				array( 'value' => array( 'A value is required.' ) ),
			);
		}

		if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
			return $this->errors->create(
				'forbidden_key',
				sprintf(
					'Setting "%s" cannot be modified through this tool. Allowed keys: %s.',
					$key,
					implode( ', ', self::ALLOWED_KEYS ),
				),
				array( 'key' => $key, 'allowed_keys' => self::ALLOWED_KEYS ),
			);
		}

		$this->settings->set( $key, $value );

		return $this->success(
			sprintf( 'Setting "%s" updated.', $key ),
			array(
				'key'   => $key,
				'value' => $value,
			),
		);
	}
}
