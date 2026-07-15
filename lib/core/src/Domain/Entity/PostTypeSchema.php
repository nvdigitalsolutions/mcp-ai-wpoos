<?php
/**
 * Immutable post type schema entity.
 *
 * Framework-agnostic representation of a content type's registration
 * metadata — labels, capabilities, supported features, REST config.
 * Returned by SchemaStoreInterface::getPostType().
 *
 * @package Nvoos\Core
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class PostTypeSchema implements \JsonSerializable {

	/**
	 * @param string                $slug            Post type slug (e.g., 'post', 'page').
	 * @param string                $label           Human-readable label.
	 * @param string                $description     Optional description.
	 * @param bool                  $isPublic        Whether the post type is publicly visible.
	 * @param bool                  $isHierarchical  Whether the post type supports parent/child.
	 * @param bool                  $hasArchive      Whether the post type has an archive page.
	 * @param bool                  $showInRest      Whether exposed via the REST API.
	 * @param string|null           $restBase        REST API base slug (null = not exposed).
	 * @param array<string, string> $labels          Label map (singular_name, add_new_item, etc.).
	 * @param array<string, string> $capabilities    Capability map (edit_post, delete_post, etc.).
	 * @param string[]              $supports        Supported features (title, editor, thumbnail, etc.).
	 * @param string[]              $statuses        Available post statuses for this type.
	 * @param array<string, mixed>  $metaFields      Custom meta field definitions (may be empty).
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public string $description = '',
		public bool $isPublic = true,
		public bool $isHierarchical = false,
		public bool $hasArchive = false,
		public bool $showInRest = false,
		public ?string $restBase = null,
		public array $labels = array(),
		public array $capabilities = array(),
		public array $supports = array(),
		public array $statuses = array(),
		public array $metaFields = array(),
	) {}

	/**
	 * Whether the post type supports a specific feature.
	 */
	public function supports( string $feature ): bool {
		return in_array( $feature, $this->supports, true );
	}

	public function jsonSerialize(): array {
		return array(
			'slug'            => $this->slug,
			'label'           => $this->label,
			'description'     => $this->description,
			'is_public'       => $this->isPublic,
			'is_hierarchical' => $this->isHierarchical,
			'has_archive'     => $this->hasArchive,
			'show_in_rest'    => $this->showInRest,
			'rest_base'       => $this->restBase,
			'labels'          => $this->labels,
			'capabilities'    => $this->capabilities,
			'supports'        => $this->supports,
			'statuses'        => $this->statuses,
			'meta_fields'     => $this->metaFields,
		);
	}
}
