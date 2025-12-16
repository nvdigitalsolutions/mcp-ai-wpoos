# Hybrid Directory Structure Example

This document shows the recommended hybrid directory structure that maintains backward compatibility while allowing modern PSR-4 autoloaded classes.

---

## 📁 Complete Directory Structure

```
mcp-ai-wpoos/
│
├── mcp-ai-wpoos.php                # Main plugin file (unchanged)
├── composer.json                   # Updated with PSR-4 autoload
├── vendor/                         # Composer dependencies
│
├── includes/                       # EXISTING CODE (keep as-is)
│   ├── class-wp-mcp-ai-logger.php             # Old style - DO NOT RENAME
│   ├── class-wp-mcp-ai-tool-registry.php      # Old style - DO NOT RENAME
│   ├── class-openai-client.php                # Old style - DO NOT RENAME
│   ├── class-admin-settings.php               # Old style - DO NOT RENAME
│   ├── class-resource-manager.php             # Old style - DO NOT RENAME
│   ├── ...                                    # 280+ other existing files
│   │
│   ├── admin/                      # Existing admin classes
│   │   └── class-wp-mcp-ai-*.php
│   │
│   ├── tools/                      # Existing tool classes
│   │   └── class-wp-mcp-ai-tool-*.php
│   │
│   ├── services/                   # Existing service classes
│   │   └── class-wp-mcp-ai-*-service.php
│   │
│   └── namespaced/                 # ✨ NEW: PSR-4 Autoloaded Classes
│       ├── README.md               # Documentation for new structure
│       │
│       ├── Traits/                 # Modern traits
│       │   ├── Logger_Trait.php
│       │   ├── Cache_Trait.php
│       │   └── Validator_Trait.php
│       │
│       ├── Interfaces/             # Modern interfaces
│       │   ├── Service_Interface.php
│       │   ├── Tool_Interface.php
│       │   └── Repository_Interface.php
│       │
│       ├── Abstract/               # Modern abstract classes
│       │   ├── Controller.php
│       │   ├── Service.php
│       │   └── Repository.php
│       │
│       ├── Services/               # New services (PSR-4)
│       │   ├── Example_Service.php
│       │   └── Advanced_Feature_Service.php
│       │
│       ├── Tools/                  # New tools (PSR-4)
│       │   ├── Example_Tool.php
│       │   └── Advanced_Tool.php
│       │
│       ├── Repositories/           # New repositories (PSR-4)
│       │   └── Example_Repository.php
│       │
│       ├── Controllers/            # New controllers (PSR-4)
│       │   └── Example_Controller.php
│       │
│       ├── Helpers/                # New helper classes
│       │   └── Utility_Functions.php
│       │
│       └── Exceptions/             # Custom exceptions
│           └── Example_Exception.php
│
├── assets/
│   ├── js/
│   └── css/
│
├── tests/                          # PHPUnit tests (update as needed)
└── docs/                           # Documentation
```

---

## 📝 File Naming Conventions

### Existing Files (Do NOT change)
```
includes/class-wp-mcp-ai-logger.php         → class WP_MCP_AI_Logger
includes/class-openai-client.php            → class WP_MCP_AI_OpenAI_Client
includes/admin/class-wp-mcp-ai-settings.php → class WP_MCP_AI_Settings
```

### New Files (PSR-4 Standard)
```
includes/namespaced/Services/Example_Service.php
    → namespace WP\MCP\AI\Services
    → class Example_Service

includes/namespaced/Traits/Logger_Trait.php
    → namespace WP\MCP\AI\Traits
    → trait Logger_Trait

includes/namespaced/Interfaces/Service_Interface.php
    → namespace WP\MCP\AI\Interfaces
    → interface Service_Interface
```

---

## 🔍 Directory Purposes

### includes/ (Root)
**Purpose**: All existing legacy code  
**Naming**: WordPress-style (`class-wp-mcp-ai-*.php`)  
**Loading**: Manual `require_once` statements  
**Status**: ✅ Keep unchanged - DO NOT MODIFY

### includes/namespaced/
**Purpose**: All NEW PSR-4 autoloaded code  
**Naming**: PSR-4 standard (`Category/Class_Name.php`)  
**Loading**: Composer autoloader  
**Status**: ✨ New directory for modern code

### includes/namespaced/Traits/
**Purpose**: Reusable traits for modern classes  
**Examples**:
- `Logger_Trait.php` - Logging functionality
- `Cache_Trait.php` - Caching functionality
- `Validator_Trait.php` - Validation helpers

### includes/namespaced/Interfaces/
**Purpose**: Interface definitions for contracts  
**Examples**:
- `Service_Interface.php` - Service contract
- `Tool_Interface.php` - Tool contract (could extend existing)
- `Repository_Interface.php` - Repository contract

### includes/namespaced/Abstract/
**Purpose**: Abstract base classes for inheritance  
**Examples**:
- `Controller.php` - Base controller
- `Service.php` - Base service
- `Repository.php` - Base repository

### includes/namespaced/Services/
**Purpose**: New business logic services  
**Examples**:
- `Example_Service.php` - Example service
- `Advanced_Feature_Service.php` - Advanced features

### includes/namespaced/Tools/
**Purpose**: New tool implementations  
**Examples**:
- `Example_Tool.php` - Example tool
- `Advanced_Tool.php` - Advanced tool

---

## 📋 Migration Rules

### Rule 1: Old Code Stays Put
```
✅ DO: Keep all existing files in current locations
❌ DON'T: Move or rename existing files
❌ DON'T: Change existing class names
❌ DON'T: Add namespaces to existing classes
```

### Rule 2: New Code Uses Namespaces
```
✅ DO: Put all new features in includes/namespaced/
✅ DO: Use PSR-4 naming for new classes
✅ DO: Use namespaces for new code
✅ DO: Follow PSR-4 directory structure
```

### Rule 3: Both Styles Work Together
```
✅ DO: Use old classes from new code (it works!)
✅ DO: Use new classes from old code (it works!)
✅ DO: Mix and match as needed
✅ DO: Test both old and new functionality
```

---

## 🎯 Implementation Examples

### Example 1: New Service in Namespaced Directory

**File**: `includes/namespaced/Services/Advanced_Analytics_Service.php`

```php
<?php
/**
 * Advanced Analytics Service
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

namespace WP\MCP\AI\Services;

use WP\MCP\AI\Traits\Logger_Trait;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced Analytics Service
 *
 * Provides advanced analytics functionality.
 */
class Advanced_Analytics_Service {
	use Logger_Trait;

	/**
	 * Process analytics
	 *
	 * @param array $data Analytics data.
	 * @return array Results.
	 */
	public function process_analytics( array $data ): array {
		$this->log_info( 'Processing analytics' );
		
		// Can use old classes without issue
		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		
		// Process data...
		
		return array(
			'success' => true,
			'data'    => $data,
		);
	}
}
```

### Example 2: Using New Service from Old Code

**File**: `includes/class-wp-mcp-ai-admin-dashboard.php` (existing file)

```php
<?php
/**
 * Admin Dashboard (existing class)
 */

class WP_MCP_AI_Admin_Dashboard {

	/**
	 * Show analytics
	 */
	public function show_analytics() {
		// Can use new namespaced class!
		$service = new \WP\MCP\AI\Services\Advanced_Analytics_Service();
		$results = $service->process_analytics( array( 'period' => 'week' ) );
		
		// Display results...
	}
}
```

### Example 3: New Tool Using Both Old and New

**File**: `includes/namespaced/Tools/Advanced_Search_Tool.php`

```php
<?php
/**
 * Advanced Search Tool
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

namespace WP\MCP\AI\Tools;

use WP\MCP\AI\Traits\Logger_Trait;
use WP\MCP\AI\Services\Advanced_Analytics_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced Search Tool
 */
class Advanced_Search_Tool implements \WP_MCP_AI_Tool_Interface {
	use Logger_Trait;

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'advanced_search';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'        => 'Advanced Search',
			'description' => 'Advanced search with analytics',
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
		// Use old class
		\WP_MCP_AI_Logger::log_info( 'Executing advanced search' );
		
		// Use new class
		$analytics_service = new Advanced_Analytics_Service();
		$analytics         = $analytics_service->process_analytics( $arguments );
		
		// Use trait
		$this->log_info( 'Search completed' );
		
		return array(
			'success'   => true,
			'results'   => array(),
			'analytics' => $analytics,
		);
	}
}
```

---

## ✅ Checklist for Setup

### Initial Setup
- [ ] Create `includes/namespaced/` directory
- [ ] Create subdirectories (Traits, Interfaces, Abstract, etc.)
- [ ] Update `composer.json` with PSR-4 autoload
- [ ] Run `composer dump-autoload`
- [ ] Test autoloader works

### First Implementation
- [ ] Create one example class in namespaced structure
- [ ] Test it loads via autoloader
- [ ] Test it works with existing code
- [ ] Document the pattern

### Documentation
- [ ] Update README.md with hybrid structure info
- [ ] Update ARCHITECTURE.md
- [ ] Create examples for team
- [ ] Update contribution guidelines

### Testing
- [ ] Test old code still works
- [ ] Test new code loads properly
- [ ] Test old and new work together
- [ ] Run full test suite

---

## 📚 Additional Files to Create

### includes/namespaced/README.md

```markdown
# Namespaced Classes

This directory contains PSR-4 autoloaded classes using modern PHP namespaces.

**Namespace Root**: `WP\MCP\AI\`

## Structure

- `Traits/` - Reusable traits
- `Interfaces/` - Interface definitions
- `Abstract/` - Abstract base classes
- `Services/` - Business logic services
- `Tools/` - Tool implementations
- `Repositories/` - Data access repositories
- `Controllers/` - Request controllers
- `Helpers/` - Utility classes
- `Exceptions/` - Custom exceptions

## Usage

```php
use WP\MCP\AI\Services\Example_Service;

$service = new Example_Service();
```

## Compatibility

These namespaced classes work seamlessly with existing legacy classes.
You can use both old and new classes in the same file.
```

---

## 🎓 Best Practices

### For New Features
1. ✅ Always use `includes/namespaced/` directory
2. ✅ Follow PSR-4 structure
3. ✅ Use proper namespace
4. ✅ Test autoloading works

### For Existing Code
1. ✅ Keep in current location
2. ✅ Don't rename classes
3. ✅ Don't add namespaces
4. ✅ Can use new classes if needed

### For Testing
1. ✅ Test old code still works
2. ✅ Test new code loads
3. ✅ Test interoperability
4. ✅ Run full test suite

---

## 🚀 Benefits

1. **Zero Breaking Changes**: All existing code continues working
2. **Modern Structure**: New code uses best practices
3. **Gradual Migration**: Move at your own pace
4. **Full Compatibility**: Old and new work together
5. **Clean Organization**: Clear separation of old vs new
6. **Easy to Understand**: Clear directory purpose

---

**Document Version**: 1.0  
**Last Updated**: 2025-12-16  
**Status**: Recommended Structure ✅
