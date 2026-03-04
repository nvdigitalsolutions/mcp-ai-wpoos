<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * WordPress Post Exists Constraint
 *
 * Custom Symfony Validator constraint for WordPress post existence.
 *
 * @package WP_MCP_AI
 */


namespace WP_MCP_AI\Validators\Constraints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use Symfony\Component\Validator\Constraint;

/**
 * Class WPPostExists
 *
 * Validates that a post ID exists in WordPress.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute( \Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE )]
class WPPostExists extends Constraint {

	/**
	 * Error message template.
	 *
	 * @var string
	 */
	public $message = 'Post with ID {{ post_id }} does not exist.';

	/**
	 * Optional post type to check.
	 *
	 * @var string|null
	 */
	public $post_type = null;

	/**
	 * Constructor.
	 *
	 * @param mixed $options Options array.
	 * @param array $groups  Validation groups.
	 * @param mixed $payload Payload.
	 */
	public function __construct( $options = null, array $groups = null, $payload = null ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Method kept for forward-compatibility; allows future extension without interface changes.
		parent::__construct( $options, $groups, $payload );
	}
}
