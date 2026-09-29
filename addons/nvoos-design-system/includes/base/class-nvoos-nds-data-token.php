<?php
/**
 * NV oOS Design System — Data Token (Value Object)
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing a single design token.
 *
 * Each token maps to a CSS custom property (`--nds-{group}-{id}`) and carries
 * metadata for the admin UI (label, description, input type).
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Data_Token {

	/**
	 * Unique token identifier (e.g. 'color_surface').
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Human-readable label (e.g. 'Surface Color').
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Token group (e.g. 'colors', 'spacing', 'emails').
	 *
	 * @var string
	 */
	public $group;

	/**
	 * Input type for admin UI: 'color', 'size', 'font', 'shadow', 'transition'.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Factory default value.
	 *
	 * @var string
	 */
	public $default;

	/**
	 * Current value (overridden via admin).
	 *
	 * @var string
	 */
	public $value;

	/**
	 * Optional help text shown in the admin UI.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Constructor.
	 *
	 * @param string $id          Unique token identifier.
	 * @param string $label       Human-readable label.
	 * @param string $group       Token group.
	 * @param string $type        Input type.
	 * @param string $default     Factory default value.
	 * @param string $value       Current value (defaults to factory default).
	 * @param string $description Optional help text.
	 */
	public function __construct(
		$id,
		$label,
		$group,
		$type,
		$default,
		$value = null,
		$description = ''
	) {
		$this->id          = (string) $id;
		$this->label       = (string) $label;
		$this->group       = (string) $group;
		$this->type        = (string) $type;
		$this->default     = (string) $default;
		$this->value       = null === $value ? $this->default : (string) $value;
		$this->description = (string) $description;
	}

	/**
	 * Get the fully-qualified CSS custom property name.
	 *
	 * Token IDs are self-describing (they already carry their group prefix,
	 * e.g. 'color_surface', 'email_page_bg'), so the variable name is the ID
	 * with underscores converted to hyphens.
	 *
	 * Pattern: `--nds-{id-with-hyphens}`
	 *
	 * @return string e.g. '--nds-color-surface', '--nds-email-page-bg'
	 */
	public function css_var() {
		return '--nds-' . str_replace( '_', '-', $this->id );
	}

	/**
	 * Reset this token's value to its factory default.
	 *
	 * @return void
	 */
	public function reset() {
		$this->value = $this->default;
	}

	/**
	 * Whether this token's value differs from the default.
	 *
	 * @return bool
	 */
	public function is_modified() {
		return $this->value !== $this->default;
	}
}
