<?php
/**
 * Save Profession tool — creates or updates a profession.
 *
 * Mirrors WP_MCP_AI_Tool_Save_Profession: same slug, parameters and result
 * fields, so an assistant cannot tell which runtime answered. The write goes
 * through ProfessionWriteInterface, which only runtimes with a writable
 * catalogue implement (WordPress CPT). On a read-only runtime the tool is not
 * registered at all — it never reports a save that did not happen.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;

class SaveProfessionTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ProfessionWriteInterface $professions,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'save_profession';
	}

	public function getName(): string {
		return 'Save Profession';
	}

	public function getDescription(): string {
		return 'Creates a new profession or updates an existing one. Professions define roles that can be used when creating AI assistants, including their expertise areas, default tools, and knowledge base.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => 'Display name of the profession (e.g., "Data Scientist").',
					'maxLength'   => 200,
				),
				'slug'             => array(
					'type'        => 'string',
					'description' => 'Unique identifier (e.g., "data_scientist"). If updating, provide the existing slug.',
					'pattern'     => '^[a-z0-9_-]+$',
					'maxLength'   => 100,
				),
				'description'      => array(
					'type'        => 'string',
					'description' => 'Brief description of the profession.',
					'maxLength'   => 500,
				),
				'category'         => array(
					'type'        => 'string',
					'description' => 'Profession category.',
					'enum'        => array( 'advisory', 'creative', 'technical', 'healthcare', 'legal', 'financial', 'other' ),
				),
				'role_description' => array(
					'type'        => 'string',
					'description' => 'Role description used in assistant instructions.',
					'maxLength'   => 1000,
				),
				'expertise'        => array(
					'type'        => 'array',
					'description' => 'Expertise areas.',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 20,
				),
				'warnings'         => array(
					'type'        => 'array',
					'description' => 'Disclaimers the assistant should communicate.',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 10,
				),
				'knowledge_base'   => array(
					'type'        => 'string',
					'description' => 'Knowledge base content (supports markdown).',
					'maxLength'   => 10000,
				),
				'default_tools'    => array(
					'type'        => 'array',
					'description' => 'Default tool slugs for the profession.',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 50,
				),
			),
			'required'             => array( 'title', 'slug' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		// The WordPress implementation also declares `edit_posts` but enforces
		// `manage_options` at execution time; the effective gate is the
		// capability every adapter agrees on.
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$slug  = $this->stringParam( $arguments, 'slug' );
		$title = $this->stringParam( $arguments, 'title' );

		if ( '' === $slug || '' === $title ) {
			return $this->errors->validationFailed(
				'title and slug required.',
				array(
					'title' => array( 'Required.' ),
					'slug'  => array( 'Required.' ),
				)
			);
		}

		// Mirror the WordPress tool: only fields the caller actually sent are
		// written. Sending `category => ''` for an omitted field would clear an
		// existing value on update.
		$data = array(
			'slug'  => $slug,
			'title' => $title,
		);

		foreach ( array( 'description', 'category', 'role_description', 'knowledge_base' ) as $key ) {
			if ( isset( $arguments[ $key ] ) ) {
				$data[ $key ] = $this->stringParam( $arguments, $key );
			}
		}

		foreach ( array( 'expertise', 'warnings', 'default_tools' ) as $key ) {
			if ( isset( $arguments[ $key ] ) ) {
				$data[ $key ] = $this->stringList( $arguments, $key );
			}
		}

		$result = $this->professions->save( $data );

		if ( empty( $result['saved'] ) ) {
			return $this->errors->create(
				'profession_save_failed',
				(string) ( $result['error'] ?? 'The profession could not be saved.' )
			);
		}

		$action = 'updated' === ( $result['action'] ?? '' ) ? 'updated' : 'created';

		return $this->success(
			'updated' === $action ? 'Profession updated.' : 'Profession created.',
			array(
				'action'     => $action,
				'profession' => $result['profession'] ?? array(),
			)
		);
	}

	/**
	 * Read a string array, dropping non-strings and empty entries.
	 *
	 * @param ToolArgs $arguments Raw tool arguments.
	 *
	 * @return string[]
	 */
	private function stringList( array $arguments, string $key ): array {
		$values = $this->arrayParam( $arguments, $key );

		return array_values(
			array_filter(
				array_map(
					static fn ( mixed $value ): string => is_string( $value ) ? trim( $value ) : '',
					$values
				),
				static fn ( string $value ): bool => '' !== $value
			)
		);
	}
}
