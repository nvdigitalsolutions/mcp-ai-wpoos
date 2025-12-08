<?php
/**
 * Validation arguments for Create Assistant tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for create_assistant tool arguments.
 *
 * Defines validation rules for creating AI assistants.
 */
class CreateAssistantArguments {

	/**
	 * Title of the assistant.
	 *
	 * @var string
	 */
	#[Assert\NotBlank(message: 'Title is required.')]
	#[Assert\Length(
		min: 1,
		max: 200,
		minMessage: 'Title must be at least {{ limit }} character long.',
		maxMessage: 'Title cannot be longer than {{ limit }} characters.'
	)]
	#[Assert\Type(type: 'string')]
	public $title = '';

	/**
	 * Free-form description of assistant's purpose.
	 *
	 * @var string|null
	 */
	#[Assert\Length(
		max: 5000,
		maxMessage: 'Description cannot be longer than {{ limit }} characters.'
	)]
	#[Assert\Type(type: 'string')]
	public $description = null;

	/**
	 * Custom system prompt/instructions.
	 *
	 * @var string|null
	 */
	#[Assert\Length(
		max: 32000,
		maxMessage: 'System prompt cannot be longer than {{ limit }} characters.'
	)]
	#[Assert\Type(type: 'string')]
	public $system_prompt = null;

	/**
	 * Selected professions (up to 3).
	 *
	 * @var array
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\Count(
		max: 3,
		maxMessage: 'You can select up to {{ limit }} professions.'
	)]
	#[Assert\All(array(
		new Assert\Type('string'),
		new Assert\Choice(
			choices: array(
				'tax_advisor',
				'customs_broker',
				'compliance_officer',
				'trade_consultant',
				'import_export_specialist',
				'logistics_manager',
				'supply_chain_analyst',
				'freight_forwarder',
				'regulatory_affairs_specialist',
				'international_business_consultant',
				'tariff_specialist',
				'quality_assurance_manager',
				'product_certification_expert',
				'legal_advisor',
				'financial_advisor',
			),
			message: 'The profession "{{ value }}" is not valid.'
		),
	))]
	public $professions = array();

	/**
	 * Selected regions (up to 2).
	 *
	 * @var array
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\Count(
		max: 2,
		maxMessage: 'You can select up to {{ limit }} regions.'
	)]
	#[Assert\All(array(
		new Assert\Type('string'),
		new Assert\Choice(
			choices: array(
				'jamaica',
				'sri_lanka',
				'global',
				'caribbean',
				'south_asia',
				'european_union',
				'north_america',
				'latin_america',
				'middle_east',
				'africa',
				'asia_pacific',
			),
			message: 'The region "{{ value }}" is not valid.'
		),
	))]
	public $regions = array();

	/**
	 * Industry focus.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\Length(
		max: 100,
		maxMessage: 'Industry focus cannot be longer than {{ limit }} characters.'
	)]
	public $industry_focus = null;

	/**
	 * Attachment IDs for knowledge base files.
	 *
	 * @var array
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\Count(
		max: 20,
		maxMessage: 'You can attach up to {{ limit }} files.'
	)]
	#[Assert\All(array(
		new Assert\Type('integer'),
		new Assert\Positive(message: 'Attachment ID must be a positive integer.'),
	))]
	public $attachment_ids = array();

	/**
	 * Whether to execute assistant creation asynchronously.
	 *
	 * @var bool
	 */
	#[Assert\Type(type: 'bool')]
	public $async = false;

	/**
	 * Email address for async completion notification.
	 *
	 * @var string|null
	 */
	#[Assert\Email(message: 'The email "{{ value }}" is not a valid email address.')]
	#[Assert\Type(type: 'string')]
	public $notification_email = null;
}
