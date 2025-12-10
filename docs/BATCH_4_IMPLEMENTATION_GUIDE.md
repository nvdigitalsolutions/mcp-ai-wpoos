# Batch 4 Implementation Guide

**Status**: 3/12 tools completed (25%)  
**Date**: December 10, 2025  
**Completed by**: Copilot Workspace

## Overview

This guide provides step-by-step instructions for implementing the remaining 9 validated tools in Batch 4 of the Symfony Validator Migration Plan. Each tool follows the same pattern established by the first 3 completed tools.

## Completed Tools ✓

1. **transcribe-openai-audio** (32 validation lines) ✓
2. **generate-image-alt-text** (43 validation lines) ✓
3. **generate-image-caption** (43 validation lines) ✓

## Remaining Tools (9)

### Group 1: Very High Complexity (120-101 validation lines)
4. **run-crawl4ai-job** (120 lines)
5. **scrape-product** (101 lines)

### Group 2: High Complexity (79-67 validation lines)
6. **edit-gemini-image** (79 lines)
7. **web-search** (78 lines)
8. **generate-openai-image** (78 lines)
9. **generate-veo-video** (74 lines)
10. **generate-gemini-image** (67 lines)

### Group 3: Medium Complexity (56-54 validation lines)
11. **generate-music** (56 lines)
12. **generate-openai-speech** (54 lines)

---

## Implementation Pattern

Each validated tool requires **3 files**:

1. **Argument Validation Class** - `includes/validators/arguments/class-[tool-name]-arguments.php`
2. **Validated Tool Class** - `includes/tools/class-wp-mcp-ai-tool-[tool-name]-validated.php`
3. **Test Class** - `tests/test-[tool-name]-validated-tool.php`

Plus **1 registration entry** in `includes/validators/validated-tools-init.php`

---

## Step-by-Step Implementation

### Step 1: Create Argument Validation Class

Location: `includes/validators/arguments/class-[tool-name]-arguments.php`

**Template:**
```php
<?php
/**
 * [Tool Name] Tool Arguments Validation
 *
 * Validation class for [tool_slug] tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class [ToolName]Arguments
 *
 * Defines validation rules for [tool_slug] tool arguments.
 */
class [ToolName]Arguments {

	/**
	 * [Parameter description]
	 *
	 * @var [type]
	 */
	#[Assert\NotBlank(message: '[Parameter] is required.')]
	#[Assert\Type(type: '[type]', message: '[Parameter] must be a [type].')]
	public $parameter_name = 'default_value';

	// Add all parameters with appropriate Symfony Validator constraints
}
```

**Common Constraints:**
- `#[Assert\NotBlank]` - Required fields
- `#[Assert\Type]` - Type validation (string, integer, bool, float, array)
- `#[Assert\Length]` - String length (min, max)
- `#[Assert\Positive]` - Positive integers
- `#[Assert\Range]` - Numeric range (min, max)
- `#[Assert\Choice]` - Enum validation
- `#[Assert\Url]` - URL validation
- `#[Assert\Regex]` - Pattern matching
- Custom: `#[WPPostExists]`, `#[WPCapability]`

### Step 2: Create Validated Tool Class

Location: `includes/tools/class-wp-mcp-ai-tool-[tool-name]-validated.php`

**Template:**
```php
<?php
/**
 * Tool for [description] (Validated version).
 *
 * This is the Symfony Validator version of the [tool_slug] tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-[tool-name]-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-[tool-name].php';

/**
 * [Description] using Symfony Validator.
 *
 * This class extends the original [tool_slug] tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_[ToolName]_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original [tool_slug] tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_[ToolName]
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_[ToolName]();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return '[tool_slug]_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( '[Tool Name] (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( '[Description] with Symfony Validator for argument validation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		// Use the same schema as the original tool.
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\[ToolName]Arguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\[ToolName]Arguments $validated_args Validated arguments object.
	 * @param array                                           $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'required_param' => $validated_args->required_param,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->optional_param ) {
			$arguments['optional_param'] = $validated_args->optional_param;
		}

		// Delegate to the original tool's execute method.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		// Delegate to the original tool.
		return $this->original_tool->get_capability_flags();
	}

	// Add other interface methods if tool implements them:
	// - get_model_requirements() for WP_MCP_AI_Tool_Model_Requirements_Interface
}
```

### Step 3: Create Test Class

Location: `tests/test-[tool-name]-validated-tool.php`

**Template:**
```php
<?php
/**
 * Tests for [Tool Name] Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_[ToolName]_Validated
 *
 * Tests for the validated [tool_slug] tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_[ToolName]_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_[ToolName]_Validated
	 */
	private $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if PHP < 8.0 (Symfony Validator attributes require PHP 8.0+).
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			$this->markTestSkipped( 'Symfony Validator requires PHP 8.0+' );
		}

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-[tool-name]-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-[tool-name]-validated.php';

		// Create test user with appropriate role/capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor', // Adjust based on tool requirements
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_[ToolName]_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( '[tool_slug]_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
	}

	/**
	 * Test parameter schema is inherited from original tool.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
	}

	// Add 6-8 validation test methods:
	// - test_validation_fails_with_invalid_[param]
	// - test_validation_fails_with_missing_required_[param]
	// - test_validation_fails_with_[param]_out_of_range
	// - test_validation_passes_with_valid_data
	// etc.

	/**
	 * Test capability flags are delegated to original tool.
	 */
	public function test_capability_flags_delegation() {
		if ( method_exists( $this->tool, 'get_capability_flags' ) ) {
			$flags = $this->tool->get_capability_flags();
			$this->assertIsArray( $flags );
		}
	}
}
```

### Step 4: Register the Tool

Add entry to `includes/validators/validated-tools-init.php`:

```php
// Batch 4 - December 10, 2025.
'WP_MCP_AI_Tool_[ToolName]_Validated' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-[tool-name]-validated.php',
```

---

## Tool-Specific Implementation Notes

### 4. run-crawl4ai-job (120 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php`

**Key Parameters:**
- `urls` (array of URLs) - `#[Assert\All([new Assert\Url()])]`
- `url` (single URL) - `#[Assert\Url]`
- `priority` (0-100) - `#[Assert\Range(min: 0, max: 100)]`
- `options` (object) - Array validation
- `wait_for_completion` (boolean) - `#[Assert\Type('bool')]`
- `poll_interval` (0-30 seconds) - `#[Assert\Range(min: 0, max: 30)]`
- `timeout` (0-600 seconds) - `#[Assert\Range(min: 0, max: 600)]`

**Special Considerations:**
- URL validation with network safety checks (already in original tool)
- Complex options object (keep as array, validate structure)
- Multiple URL sources (urls vs url parameter)

### 5. scrape-product (101 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-scrape-product.php`

**Key Parameters:**
- `url` - `#[Assert\Url]`
- `selectors` (object) - Array with CSS selector validation
- `timeout` - `#[Assert\Range(min: 1, max: 300)]`
- `user_agent` (string) - `#[Assert\Length(max: 500)]`

**Special Considerations:**
- CSS selector validation in `selectors` object
- Product-specific field extraction

### 6. edit-gemini-image (79 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php`

**Key Parameters:**
- `image_url` - `#[Assert\Url]`
- `mask_url` - `#[Assert\Url]`
- `prompt` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 1000)]`
- `model` - `#[Assert\Choice(['imagen-3.0-generate-001', ...])]`
- `number_of_images` - `#[Assert\Range(min: 1, max: 4)]`

### 7. web-search (78 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-web-search.php`

**Key Parameters:**
- `query` - `#[Assert\NotBlank]`, `#[Assert\Length(min: 1, max: 500)]`
- `num_results` - `#[Assert\Range(min: 1, max: 100)]`
- `search_depth` - `#[Assert\Choice(['basic', 'advanced'])]`
- `include_images` - `#[Assert\Type('bool')]`

### 8. generate-openai-image (78 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php`

**Key Parameters:**
- `prompt` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 4000)]`
- `model` - `#[Assert\Choice(['dall-e-2', 'dall-e-3'])]`
- `size` - `#[Assert\Choice(['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792'])]`
- `quality` - `#[Assert\Choice(['standard', 'hd'])]`
- `style` - `#[Assert\Choice(['vivid', 'natural'])]`
- `n` - `#[Assert\Range(min: 1, max: 10)]`

### 9. generate-veo-video (74 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

**Key Parameters:**
- `prompt` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 1000)]`
- `reference_image_url` - `#[Assert\Url]`
- `duration_seconds` - `#[Assert\Range(min: 1, max: 60)]`
- `aspect_ratio` - `#[Assert\Choice(['9:16', '16:9', '1:1'])]`

### 10. generate-gemini-image (67 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php`

**Key Parameters:**
- `prompt` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 2048)]`
- `negative_prompt` - `#[Assert\Length(max: 2048)]`
- `number_of_images` - `#[Assert\Range(min: 1, max: 8)]`
- `model` - `#[Assert\Choice(['imagen-3.0-generate-001', ...])]`
- `aspect_ratio` - `#[Assert\Choice(['1:1', '3:4', '4:3', '9:16', '16:9'])]`

### 11. generate-music (56 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-generate-music.php`

**Key Parameters:**
- `prompt` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 500)]`
- `duration` - `#[Assert\Range(min: 5, max: 120)]`
- `temperature` - `#[Assert\Range(min: 0, max: 1)]`
- `genre` - `#[Assert\Length(max: 100)]`

### 12. generate-openai-speech (54 lines)

**Original**: `includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php`

**Key Parameters:**
- `text` - `#[Assert\NotBlank]`, `#[Assert\Length(max: 4096)]`
- `voice` - `#[Assert\Choice(['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'])]`
- `model` - `#[Assert\Choice(['tts-1', 'tts-1-hd'])]`
- `speed` - `#[Assert\Range(min: 0.25, max: 4.0)]`
- `response_format` - `#[Assert\Choice(['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'])]`

---

## Testing Requirements

Each tool must have **minimum 8 test methods**:

1. `test_tool_metadata()` - Verify slug, name, description
2. `test_parameters_schema()` - Verify schema structure
3-6. Test validation failures (4+ methods for different invalid inputs)
7-8. Test validation passes (2+ methods for valid inputs)
9. `test_capability_flags_delegation()` - Verify capability delegation

**Example Test Cases:**
- Invalid type (string instead of integer)
- Out of range values
- Invalid enum choice
- Missing required parameters
- Too long strings
- Invalid URLs
- Negative numbers where positive required

---

## Validation After Implementation

For each tool:

1. **Syntax Check**: Ensure PHP 8.0+ attributes compile
2. **File Check**: All 3 files exist with correct naming
3. **Registration**: Tool added to `validated-tools-init.php`
4. **Test Execution**: Run `vendor/bin/phpunit tests/test-[tool-name]-validated-tool.php`
5. **Code Style**: Run `composer run lint` (if available)

---

## Progress Tracking

Update `docs/SYMFONY_VALIDATOR_MIGRATION_PLAN.md` after each tool:

- Update "Current Progress" count
- Mark tool as completed (✅)
- Update completion percentage

---

## Estimated Time per Tool

- **Very High Complexity** (120-101 lines): 20-25 hours each
- **High Complexity** (79-67 lines): 15-18 hours each
- **Medium Complexity** (56-54 lines): 10-12 hours each

**Total Batch 4 Remaining**: ~140-170 hours

---

## Common Patterns & Best Practices

### 1. Optional vs Required Parameters

```php
// Required
#[Assert\NotBlank(message: 'Title is required.')]
public $title = '';

// Optional (nullable)
#[Assert\Type(type: 'string', message: 'Description must be a string.')]
public $description = null;
```

### 2. Multiple Parameter Sources

For tools with `attachment_id`, `file_id`, `url` alternatives:

```php
public $attachment_id = null;
public $file_id = null;
public $url = null;

// In execute_validated():
if ( null !== $validated_args->attachment_id ) {
	$arguments['attachment_id'] = $validated_args->attachment_id;
}
// Repeat for file_id, url
```

### 3. Delegation to Original Tool

Always delegate complex business logic to the original tool:

```php
return $this->original_tool->execute( $arguments, $context );
```

### 4. Interface Implementation

If original tool implements interfaces, validated tool must too:

- `WP_MCP_AI_Tool_Capability_Flags_Interface` → `get_capability_flags()`
- `WP_MCP_AI_Tool_Model_Requirements_Interface` → `get_model_requirements()`
- Delegate to `$this->original_tool->[method]()`

### 5. Type Coercion

Some parameters accept multiple types (`'integer'|'string'`). Handle in arguments class:

```php
#[Assert\Type(type: ['integer', 'string'])]
public $flexible_param;
```

---

## Next Steps

1. Choose next tool to implement (recommendation: start with simpler tools)
2. Follow the 4-step implementation pattern
3. Test thoroughly
4. Commit with message: `"Add [tool-name] validated tool (Batch 4 - Tool X/12)"`
5. Update progress in this document
6. Repeat for remaining tools

---

## Support Resources

- **Symfony Validator Docs**: https://symfony.com/doc/current/validation.html
- **Existing Examples**: See completed tools 1-3 for reference
- **Test Patterns**: `tests/test-*-validated-tool.php`
- **Original Tools**: `includes/tools/class-wp-mcp-ai-tool-*.php`

---

**Last Updated**: December 10, 2025  
**Status**: 3/12 completed (25%)
