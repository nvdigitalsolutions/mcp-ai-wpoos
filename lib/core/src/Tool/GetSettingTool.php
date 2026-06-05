<?php
/**
 * Get Setting tool — reads a single plugin setting.
 *
 * Uses SettingsStoreInterface to read any stored configuration value.
 * Framework-agnostic — works with WordPress options, Laravel config,
 * or any other SettingsStore implementation.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GetSettingTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_setting';
	}

	public function getName(): string {
		return 'Get Setting';
	}

	public function getDescription(): string {
		return 'Reads a single plugin or application setting by key. Use list_settings to discover available keys.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array(
					'type'        => 'string',
					'description' => 'The setting key to retrieve (e.g., default_provider, default_model).',
				),
				'default' => array(
					'description' => 'Default value returned when the key is not set.',
				),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->stringParam( $arguments, 'key' );
		if ( '' === $key ) {
			return $this->errors->validationFailed(
				'key is required.',
				array( 'key' => array( 'A setting key is required.' ) ),
			);
		}

		$default = $arguments['default'] ?? null;
		$value   = $this->settings->get( $key, $default );

		if ( null === $value ) {
			return $this->success(
				sprintf( 'Setting "%s" is not configured.', $key ),
				array(
					'key'   => $key,
					'value' => null,
					'found' => false,
				),
			);
		}

		return $this->success(
			sprintf( 'Setting "%s" retrieved.', $key ),
			array(
				'key'   => $key,
				'value' => $value,
				'found' => true,
			),
		);
	}
}
