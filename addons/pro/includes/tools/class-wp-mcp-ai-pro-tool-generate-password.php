<?php
/**
 * Tool: Generate Password
 *
 * Generates cryptographically secure random passwords with customizable options.
 * Provides password strength analysis.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tools
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Password Tool
 */
class WP_MCP_AI_Pro_Tool_Generate_Password {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'generate_password';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'generate_password',
			'description'         => 'Generate cryptographically secure random passwords with customizable strength and character sets. Returns password with strength analysis. Use when you need to create strong passwords for new accounts or password rotation.',
			'category'            => 'password_vault',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'length'          => array(
						'type'        => 'integer',
						'default'     => 16,
						'minimum'     => 12,
						'maximum'     => 128,
						'description' => 'Password length (12-128 characters, default: 16)',
					),
					'uppercase'       => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include uppercase letters A-Z',
					),
					'lowercase'       => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include lowercase letters a-z',
					),
					'numbers'         => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include numbers 0-9',
					),
					'symbols'         => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Include symbols !@#$%^&*',
					),
					'avoid_ambiguous' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Avoid ambiguous characters (0OIl1)',
					),
					'count'           => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'maximum'     => 10,
						'description' => 'Number of passwords to generate (1-10, default: 1)',
					),
				),
			),
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$length          = isset( $arguments['length'] ) ? absint( $arguments['length'] ) : 16;
		$uppercase       = isset( $arguments['uppercase'] ) ? (bool) $arguments['uppercase'] : true;
		$lowercase       = isset( $arguments['lowercase'] ) ? (bool) $arguments['lowercase'] : true;
		$numbers         = isset( $arguments['numbers'] ) ? (bool) $arguments['numbers'] : true;
		$symbols         = isset( $arguments['symbols'] ) ? (bool) $arguments['symbols'] : true;
		$avoid_ambiguous = isset( $arguments['avoid_ambiguous'] ) ? (bool) $arguments['avoid_ambiguous'] : false;
		$count           = isset( $arguments['count'] ) ? absint( $arguments['count'] ) : 1;

		// Validate length.
		if ( $length < 12 || $length > 128 ) {
			return array(
				'success' => false,
				'error'   => 'Password length must be between 12 and 128 characters',
			);
		}

		// Validate count.
		if ( $count < 1 || $count > 10 ) {
			return array(
				'success' => false,
				'error'   => 'Count must be between 1 and 10',
			);
		}

		// Validate at least one character set is selected.
		if ( ! $uppercase && ! $lowercase && ! $numbers && ! $symbols ) {
			return array(
				'success' => false,
				'error'   => 'At least one character set must be enabled (uppercase, lowercase, numbers, or symbols)',
			);
		}

		$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
		$passwords          = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$password = $encryption_service->generate_password(
				$length,
				$uppercase,
				$lowercase,
				$numbers,
				$symbols,
				$avoid_ambiguous
			);

			if ( ! $password ) {
				return array(
					'success' => false,
					'error'   => 'Failed to generate password',
				);
			}

			$strength = $encryption_service->calculate_password_strength( $password );

			$passwords[] = array(
				'password' => $password,
				'strength' => $strength,
				'length'   => strlen( $password ),
			);
		}

		// Return single password if count is 1, otherwise return array.
		if ( $count === 1 ) {
			return array_merge(
				array( 'success' => true ),
				$passwords[0],
				array(
					'options' => array(
						'length'          => $length,
						'uppercase'       => $uppercase,
						'lowercase'       => $lowercase,
						'numbers'         => $numbers,
						'symbols'         => $symbols,
						'avoid_ambiguous' => $avoid_ambiguous,
					),
				)
			);
		}

		return array(
			'success'   => true,
			'passwords' => $passwords,
			'count'     => count( $passwords ),
			'options'   => array(
				'length'          => $length,
				'uppercase'       => $uppercase,
				'lowercase'       => $lowercase,
				'numbers'         => $numbers,
				'symbols'         => $symbols,
				'avoid_ambiguous' => $avoid_ambiguous,
			),
		);
	}
}
