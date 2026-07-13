<?php
/**
 * Get Post Type Schema tool — framework-agnostic implementation.
 *
 * Returns the registration metadata of a content type: labels,
 * capabilities, supported features, registered taxonomies,
 * available statuses, and optional custom meta field definitions.
 *
 * Injects SchemaStoreInterface instead of calling WordPress
 * functions directly. The WordPress adapter wraps get_post_type_object(),
 * get_object_taxonomies(), get_post_stati(), and post_type_supports().
 *
 * @package Nvoos\Core
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\TaxonomySchema;

class GetPostTypeSchemaTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SchemaStoreInterface $schema,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_post_type_schema';
	}

	public function getName(): string {
		return 'Get Post Type Schema';
	}

	public function getDescription(): string {
		return 'Returns the schema of a registered content type: labels, capabilities, supported features, registered taxonomies, available statuses, and custom meta field definitions.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'           => array(
					'type'        => 'string',
					'description' => 'The post type slug to describe (e.g., "post", "page", "mcp_ai_task").',
				),
				'include_meta_schema' => array(
					'type'        => 'boolean',
					'description' => 'Whether to include custom meta field schema. Defaults to true.',
					'default'     => true,
				),
			),
			'required'             => array( 'post_type' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$postType = $this->stringParam( $arguments, 'post_type' );

		if ( '' === $postType ) {
			return $this->errors->validationFailed( 'post_type is required.' );
		}

		$schema = $this->schema->getPostType( $postType );

		if ( null === $schema ) {
			return $this->errors->notFound(
				sprintf( 'The post type "%s" is not registered.', $postType ),
			);
		}

		// Get registered taxonomies for this post type.
		$taxonomies = $this->schema->listTaxonomies( $postType );
		$taxData    = array();
		foreach ( $taxonomies as $tax ) {
			$taxData[ $tax->slug ] = array(
				'label'        => $tax->label,
				'hierarchical' => $tax->isHierarchical,
				'public'       => $tax->isPublic,
			);
		}

		$result = $schema->jsonSerialize();
		unset( $result['meta_fields'] ); // Will be added conditionally below.

		$result['taxonomies'] = $taxData;

		$includeMeta = $arguments['include_meta_schema'] ?? true;
		if ( $includeMeta && array() !== $schema->metaFields ) {
			$result['meta_schema'] = $schema->metaFields;
		}

		$result['message'] = sprintf( 'Schema retrieved for post type: %s', $schema->label );
		$result['summary'] = $result['message'];

		return $this->success( $result['message'], $result );
	}
}
