# Hybrid Naming Migration Guide

**Recommended Approach**: Gradual modernization without breaking changes

---

## Overview

This guide demonstrates how to modernize the plugin's naming structure **WITHOUT** breaking existing code, by introducing a hybrid approach where:

1. ✅ Existing classes keep their current names (no breaking changes)
2. ✅ New features use modern PSR-4 namespaces
3. ✅ Gradual migration path for future
4. ✅ Full backward compatibility maintained

---

## 🏗️ Implementation Strategy

### Step 1: Add PSR-4 Autoloader (Non-Breaking)

#### Update composer.json

```json
{
  "name": "mcp-ai-wpoos/mcp-ai-wpoos",
  "description": "WordPress plugin integrating MCP tools with AI workflows.",
  "type": "wordpress-plugin",
  "autoload": {
    "psr-4": {
      "WP\\MCP\\AI\\": "includes/namespaced/"
    }
  },
  "require": {
    "rahul900day/tiktoken-php": "^1.0",
    "symfony/http-client": "^6.1|^7.0"
  }
}
```

Run:
```bash
composer dump-autoload
```

### Step 2: Create Namespaced Directory Structure

```
includes/
├── class-wp-mcp-ai-logger.php          # Existing (unchanged)
├── class-wp-mcp-ai-tool-registry.php   # Existing (unchanged)
├── class-openai-client.php             # Existing (unchanged)
└── namespaced/                         # NEW directory for PSR-4 classes
    ├── Traits/
    │   └── Logger_Trait.php
    ├── Interfaces/
    │   └── Service_Interface.php
    ├── Abstract/
    │   └── Controller.php
    ├── Services/
    │   └── New_Feature_Service.php
    └── Tools/
        └── New_Tool.php
```

### Step 3: Create New Classes with Namespaces

#### Example: New Service Class

**File**: `includes/namespaced/Services/Example_Service.php`

```php
<?php
/**
 * Example Service - Using Modern PSR-4 Naming
 *
 * @package WP_MCP_AI
 */

namespace WP\MCP\AI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Example Service Class
 *
 * Demonstrates modern PSR-4 autoloaded class.
 */
class Example_Service {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize service
	}

	/**
	 * Process something
	 *
	 * @param array $data Input data.
	 * @return array Result.
	 */
	public function process( array $data ): array {
		// Can still use old classes without issue
		\WP_MCP_AI_Logger::log_info( 'Processing in new service' );
		
		return array(
			'success' => true,
			'data'    => $data,
		);
	}
}
```

### Step 4: Use Namespaced Classes

```php
<?php
/**
 * Using new namespaced classes
 */

// Option 1: Full namespace
$service = new \WP\MCP\AI\Services\Example_Service();

// Option 2: With use statement (cleaner)
use WP\MCP\AI\Services\Example_Service;

$service = new Example_Service();
$result  = $service->process( array( 'test' => 'data' ) );
```

### Step 5: Create Class Aliases (Optional Migration Path)

For gradual migration, create aliases that point to old classes:

**File**: `includes/namespaced/aliases.php`

```php
<?php
/**
 * Class Aliases for Backward Compatibility
 *
 * Allows using both old and new class names during transition.
 *
 * @package WP_MCP_AI
 */

namespace WP\MCP\AI;

// Only create aliases for classes you're actively migrating
// Don't create for all classes at once

/**
 * Logger alias (example only - don't actually do this yet)
 *
 * Allows using both:
 * - \WP_MCP_AI_Logger::log_error() (old)
 * - \WP\MCP\AI\Logger::log_error() (new)
 */
if ( ! class_exists( 'WP\MCP\AI\Logger' ) ) {
	class Logger extends \WP_MCP_AI_Logger {
		// Inherits all functionality from parent
	}
}
```

**Load aliases** in main plugin file:

```php
// In mcp-ai-wpoos.php, after autoloader
if ( file_exists( WP_MCP_AI_PATH . 'includes/namespaced/aliases.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/namespaced/aliases.php';
}
```

---

## 📝 Practical Examples

### Example 1: New Tool with Namespace

**File**: `includes/namespaced/Tools/Example_Tool.php`

```php
<?php
/**
 * Example Tool - Modern PSR-4 Style
 *
 * @package WP_MCP_AI
 */

namespace WP\MCP\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Example Tool
 *
 * Demonstrates new tool using namespaces while staying compatible.
 */
class Example_Tool implements \WP_MCP_AI_Tool_Interface {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'example_tool';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                => 'Example Tool',
			'description'         => 'Example of modern namespaced tool',
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool result.
	 */
	public function execute( array $arguments, array $context ): array {
		// Can use old classes without issue
		\WP_MCP_AI_Logger::log_info( 'Executing new tool' );
		
		return array(
			'success' => true,
			'message' => 'Tool executed successfully',
		);
	}
}
```

**Register the tool**:

```php
// In tools-init.php or plugin initialization
add_action(
	'plugins_loaded',
	function() {
		// Use full namespace
		$tool = new \WP\MCP\AI\Tools\Example_Tool();
		\WP_MCP_AI_Tool_Registry::get_instance()->register_tool( $tool );
	},
	25
);
```

### Example 2: New Interface

**File**: `includes/namespaced/Interfaces/Service_Interface.php`

```php
<?php
/**
 * Service Interface
 *
 * @package WP_MCP_AI
 */

namespace WP\MCP\AI\Interfaces;

/**
 * Service Interface
 *
 * Base interface for all services.
 */
interface Service_Interface {

	/**
	 * Initialize the service
	 *
	 * @return void
	 */
	public function init(): void;

	/**
	 * Get service name
	 *
	 * @return string
	 */
	public function get_name(): string;
}
```

### Example 3: New Trait

**File**: `includes/namespaced/Traits/Logger_Trait.php`

```php
<?php
/**
 * Logger Trait
 *
 * @package WP_MCP_AI
 */

namespace WP\MCP\AI\Traits;

/**
 * Logger Trait
 *
 * Provides logging functionality to classes.
 */
trait Logger_Trait {

	/**
	 * Log an info message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	protected function log_info( string $message, array $context = array() ): void {
		// Still uses the old logger class
		\WP_MCP_AI_Logger::log_info( $message, $context );
	}

	/**
	 * Log an error message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	protected function log_error( string $message, array $context = array() ): void {
		\WP_MCP_AI_Logger::log_error( $message, $context );
	}
}
```

**Using the trait**:

```php
<?php
namespace WP\MCP\AI\Services;

use WP\MCP\AI\Traits\Logger_Trait;

class My_Service {
	use Logger_Trait;

	public function process() {
		$this->log_info( 'Processing started' );
		
		// Do work...
		
		$this->log_info( 'Processing completed' );
	}
}
```

---

## 🎯 Migration Timeline (Gradual Approach)

### Phase 1: Setup (1 day)
- ✅ Add PSR-4 autoloader to composer.json
- ✅ Create `includes/namespaced/` directory structure
- ✅ Test autoloader works
- ✅ Document the new structure

### Phase 2: New Features Only (Ongoing)
- ✅ All NEW features use namespaces
- ✅ Existing code remains unchanged
- ✅ No breaking changes
- ✅ Gradual adoption

### Phase 3: Optional Aliasing (As Needed)
- ✅ Create aliases for frequently used classes
- ✅ Allow dual access (old + new names)
- ✅ Maintain full compatibility
- ✅ Document available aliases

### Phase 4: Long-term Migration (2.0 or later)
- ✅ In a future major version (2.0+)
- ✅ After extensive deprecation period
- ✅ With comprehensive migration guide
- ✅ With automated migration tools

---

## ✅ Benefits of Hybrid Approach

1. **Zero Breaking Changes**: Existing code continues working
2. **Modern Structure**: New code uses best practices
3. **Gradual Migration**: Move at your own pace
4. **Full Compatibility**: Old and new work together
5. **Low Risk**: No "big bang" migration
6. **Developer Choice**: Use what makes sense
7. **Testing Isolation**: Test new structure separately
8. **Rollback Easy**: Can revert if issues arise

---

## 📋 Checklist for Implementation

### Step 1: Preparation
- [ ] Review this guide
- [ ] Discuss with team
- [ ] Plan directory structure
- [ ] Update composer.json

### Step 2: Setup
- [ ] Create `includes/namespaced/` directory
- [ ] Create subdirectories (Traits, Interfaces, etc.)
- [ ] Run `composer dump-autoload`
- [ ] Test autoloader loads files

### Step 3: First Implementation
- [ ] Create one new namespaced class
- [ ] Test it loads and works
- [ ] Document the pattern
- [ ] Share with team

### Step 4: Ongoing Development
- [ ] Use namespaces for all NEW features
- [ ] Keep existing code unchanged
- [ ] Update documentation as you go
- [ ] Monitor for any issues

---

## 🔍 Testing the Hybrid Approach

### Test 1: Autoloader Works

```php
// Test file: test-hybrid-autoloader.php

// Old style still works
$logger = new WP_MCP_AI_Logger();
$logger->log_info( 'Old style works' );

// New style works
use WP\MCP\AI\Services\Example_Service;
$service = new Example_Service();
$service->process( array( 'test' => true ) );

echo "Both old and new styles work together!\n";
```

### Test 2: No Conflicts

```php
// Verify no naming conflicts

// Old class exists
var_dump( class_exists( 'WP_MCP_AI_Logger' ) ); // true

// New class exists (if created)
var_dump( class_exists( 'WP\MCP\AI\Services\Example_Service' ) ); // true

// Both can be instantiated
$old = new WP_MCP_AI_Logger();
$new = new \WP\MCP\AI\Services\Example_Service();

echo "No conflicts!\n";
```

---

## 📚 Naming Conventions for Hybrid Structure

### Old Style (Keep Unchanged)
```php
// File: includes/class-wp-mcp-ai-something.php
class WP_MCP_AI_Something {
	// Old WordPress style
}
```

### New Style (For New Code)
```php
// File: includes/namespaced/Category/Class_Name.php
namespace WP\MCP\AI\Category;

class Class_Name {
	// Modern PSR-4 style
}
```

### Constants
```php
// Both old and new can use same style
namespace WP\MCP\AI\Services;

class Example_Service {
	const OPTION_KEY = 'wp_mcp_ai_option_name';  // Keep wp_mcp_ai prefix
	const VERSION    = '1.0.0';
}
```

### Functions
```php
// Global functions keep old prefix
function wp_mcp_ai_new_helper_function() {
	// Helper function
}

// Or put in namespaced class as static
namespace WP\MCP\AI\Helpers;

class Functions {
	public static function helper() {
		// Helper method
	}
}
```

---

## 🎓 Best Practices

### DO ✅
- Use namespaces for ALL new features
- Keep existing code unchanged
- Document both old and new patterns
- Test thoroughly
- Use PSR-4 directory structure for new code
- Maintain backward compatibility

### DON'T ❌
- Don't rename existing classes (breaking change)
- Don't move existing files (breaks requires)
- Don't force migration of working code
- Don't create aliases unless needed
- Don't mix old and new in same file
- Don't break external integrations

---

## 📖 Documentation Updates

Update these sections to reflect hybrid approach:

1. **README.md**: Mention both styles are supported
2. **ARCHITECTURE.md**: Document namespaced structure
3. **CONTRIBUTING.md**: Show examples of both styles
4. **API docs**: Clarify which classes use namespaces

Example addition to README:

```markdown
## Code Structure

WP oOS supports a hybrid naming structure:

### Legacy Classes (Pre-1.2)
- Use WordPress-style class names: `WP_MCP_AI_Logger`
- Located in `includes/` with `class-*.php` naming
- Loaded via `require_once` statements

### Modern Classes (1.2+)
- Use PSR-4 namespaces: `WP\MCP\AI\Services\Example`
- Located in `includes/namespaced/` with PSR-4 structure
- Loaded via Composer autoloader

Both styles work together seamlessly.
```

---

## 🚀 Conclusion

The hybrid approach allows you to:

1. ✅ **Modernize gradually** without breaking changes
2. ✅ **Use best practices** for new code
3. ✅ **Maintain compatibility** with existing integrations
4. ✅ **Reduce risk** of migration bugs
5. ✅ **Choose when to migrate** existing code (or never)

**Recommended Action**: Implement the hybrid approach starting with your next new feature.

---

**Document Version**: 1.0  
**Last Updated**: 2025-12-16  
**Status**: Recommended Approach ✅
