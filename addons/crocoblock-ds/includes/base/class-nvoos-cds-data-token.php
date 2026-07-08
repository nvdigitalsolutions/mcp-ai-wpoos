<?php
/**
 * NV oOS Crocoblock DS — Data Token (Value Object)
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing a single design token.
 *
 * Each token maps to a CSS custom property (`--cds-{group}-{id}`) and carries
 * metadata for the admin UI (label, description, input type).
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Data_Token {

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
	 * Token group (e.g. 'colors', 'spacing').
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
	 * Pattern: `--cds-{group|prefix}-{id}`
	 *
	 * @return string e.g. '--cds-color-surface'
	 */
	public function css_var() {
		$prefix = $this->group_prefix();
		return '--cds-' . $prefix . '-' . $this->id;
	}

	/**
	 * Derive a short group prefix from the full group name.
	 *
	 * Groups like 'colors' → 'color', 'borders' → 'border'.
	 *
	 * @return string
	 */
	private function group_prefix() {
		$map = array(
			'colors'      => 'color',
			'typography'  => 'font',
			'spacing'     => 'space',
			'borders'     => 'border',
			'shadows'     => 'shadow',
			'sizing'      => 'size',
			'transitions' => 'transition',
		);

		return isset( $map[ $this->group ] ) ? $map[ $this->group ] : $this->group;
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
