<?php
/**
 * Validation arguments for Create Chart tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for create_chart tool arguments.
 *
 * Defines validation rules for creating Chart.js charts.
 */
class CreateChartArguments {

	/**
	 * Chart type.
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\NotBlank( message: 'Chart type is required.' )]
	#[Assert\Choice(
		choices: array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea', 'scatter', 'bubble' ),
		message: 'Chart type must be one of: {{ choices }}.'
	)]
	public $type = '';

	/**
	 * Chart data object with labels and datasets.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\NotBlank( message: 'Chart data is required.' )]
	public $data = array();

	/**
	 * Chart.js options object for customizing the chart.
	 *
	 * @var array|null
	 */
	#[Assert\Type( type: 'array' )]
	public $options = null;

	/**
	 * Chart title (optional).
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Length(
		max: 200,
		maxMessage: 'Chart title cannot exceed {{ limit }} characters.'
	)]
	public $title = null;

	/**
	 * Chart canvas width in pixels.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 100,
		max: 2000,
		notInRangeMessage: 'Chart width must be between {{ min }} and {{ max }} pixels.'
	)]
	public $width = 800;

	/**
	 * Chart canvas height in pixels.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 100,
		max: 2000,
		notInRangeMessage: 'Chart height must be between {{ min }} and {{ max }} pixels.'
	)]
	public $height = 400;

	/**
	 * Whether to save the chart as an HTML file attachment.
	 *
	 * @var bool
	 */
	#[Assert\Type( type: 'bool' )]
	public $save_as_attachment = false;

	/**
	 * Optional base file name for the saved HTML attachment.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Length(
		max: 100,
		maxMessage: 'File name cannot exceed {{ limit }} characters.'
	)]
	public $file_name = null;
}
