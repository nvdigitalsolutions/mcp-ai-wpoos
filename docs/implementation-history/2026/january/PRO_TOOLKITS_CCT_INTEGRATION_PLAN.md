# Pro Toolkits CCT Integration Plan

## Overview
Use JetEngine Custom Content Types (CCT) for storing toolkit-related data instead of wp_options and custom post types. This provides:
- Better performance and scalability
- Consistent data structure
- Query optimization
- Integration with existing JetEngine tools

## CCT Strategy

### 1. Toolkit Settings Storage (Optional - Keep in wp_options for now)
**Decision**: Keep toolkit settings in wp_options as they are:
- Small amount of data per toolkit
- Infrequently accessed
- wp_options is appropriate for configuration data

### 2. Research & Add Data (HIGH PRIORITY - Use CCT)
Each toolkit with Research & Add functionality should have its own CCT:

#### E-commerce Toolkit CCTs:
- **`ecommerce_products`** - AI-generated product ideas
  - Fields: name, description, price, category, sku, image_url, variations, created_by_assistant
- **`ecommerce_customers`** - Customer profiles
  - Fields: name, email, phone, address, notes, tags, created_by_assistant
- **`ecommerce_orders`** - Manual order entries
  - Fields: customer_id, items, total, status, notes, created_by_assistant

#### Social Media Toolkit CCTs:
- **`social_content_calendar`** - Scheduled content
  - Fields: platform, content, scheduled_date, status, hashtags, media_urls, created_by_assistant
- **`social_post_templates`** - Reusable templates
  - Fields: name, template_content, platform, variables, created_by_assistant

#### Multilingual Toolkit CCTs:
- **`translation_memory`** - Translation pairs
  - Fields: source_language, target_language, source_text, translated_text, quality_score, created_by_assistant
- **`terminology_glossaries`** - Industry terms
  - Fields: term, definition, context, translations (repeater), created_by_assistant

#### Financial Planner Toolkit CCTs:
- **`budget_categories`** - Custom budget templates
  - Fields: name, type, amount, frequency, notes, created_by_assistant
- **`financial_goals`** - Goal templates
  - Fields: name, target_amount, deadline, strategy, notes, created_by_assistant

#### Calendar Booking Toolkit CCTs:
- **`booking_services`** - Service offerings
  - Fields: name, description, duration, price, availability_rules, created_by_assistant
- **`booking_staff`** - Staff members
  - Fields: name, email, services (relation), availability, created_by_assistant
- **`booking_time_slots`** - Custom availability
  - Fields: staff_id, day_of_week, start_time, end_time, is_available, created_by_assistant

#### DJ Management Toolkit CCTs:
- **`dj_equipment`** - Equipment inventory
  - Fields: name, type, quantity, condition, rental_price, notes, created_by_assistant
- **`dj_playlists`** - Preset playlists
  - Fields: name, genre, songs (repeater), duration, event_type, created_by_assistant
- **`dj_packages`** - Service packages
  - Fields: name, description, price, inclusions, duration, created_by_assistant

#### Media Toolkit CCTs:
- **Already exists**: `mcp_ai_media_tpl` (Custom Post Type)
- **Decision**: Keep as CPT or migrate to CCT
- **`media_collections`** - Organized media groups
  - Fields: name, description, media_ids (relation), created_by_assistant

#### AI Tool Builder Toolkit CCTs:
- **`tool_templates`** - Starter templates
  - Fields: name, description, code_scaffold, parameters_schema, test_template, created_by_assistant
- **`tool_schemas`** - Reusable parameter schemas
  - Fields: name, schema_json, description, examples, created_by_assistant

### 3. CCT Implementation Pattern

Each toolkit CCT class should follow this pattern:

```php
class WP_MCP_AI_{Toolkit}_{Entity}_CCT {
    const SLUG = '{toolkit}_{entity}';
    const FIELD_ID_BASE = {unique_base}; // Use different ranges per toolkit
    
    public static function bootstrap() {
        add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );
        add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );
    }
    
    public static function maybe_register_cct() {
        // Check if JetEngine is active
        // Check if CCT already exists
        // Register CCT with fields
    }
    
    // Helper methods for CRUD operations
}
```

### 4. Field ID Base Ranges (to avoid conflicts)

- **30000-30999**: Quiz System (already in use)
- **31000-31999**: E-commerce Toolkit
- **32000-32999**: Social Media Toolkit
- **33000-33999**: Multilingual Toolkit
- **34000-34999**: Financial Planner Toolkit
- **35000-35999**: Calendar Booking Toolkit
- **36000-36999**: DJ Management Toolkit
- **37000-37999**: Media Toolkit (if migrated to CCT)
- **38000-38999**: AI Tool Builder Toolkit
- **39000-39999**: Reserved for future toolkits

### 5. Benefits of CCT Approach

1. **Performance**: CCT uses optimized database tables
2. **Scalability**: Can handle large datasets better than post meta
3. **Consistency**: All toolkit data follows same pattern
4. **Queries**: Better query performance with indexed fields
5. **Relations**: Easy relationships between CCT entries
6. **Integration**: Works with JetEngine widgets and forms
7. **Import/Export**: Built-in CSV import/export

### 6. Migration Strategy

#### Phase 1: Create CCT Classes (Week 3-4)
- Create base CCT class for toolkit data
- Implement CCT classes for each toolkit
- Add auto-registration on plugin activation

#### Phase 2: Update Toolkit Base Class (Week 4)
- Add CCT support to toolkit settings base
- Add helpers for CCT CRUD operations
- Update Research & Add tab to use CCT

#### Phase 3: Build Research & Add UI (Week 5-6)
- Create forms for adding CCT entries
- Build list views with filtering/search
- Add bulk import/export functionality
- AI-assisted content generation

#### Phase 4: Frontend Integration (Week 7-8)
- Elementor widgets for displaying CCT data
- Shortcodes for public access
- REST API endpoints for CCT queries

### 7. Example: E-commerce Products CCT

```php
class WP_MCP_AI_Ecommerce_Products_CCT {
    const SLUG = 'ecommerce_products';
    const FIELD_ID_BASE = 31000;
    
    public static function maybe_register_cct() {
        // Check JetEngine
        if ( ! function_exists( 'jet_engine' ) ) {
            return;
        }
        
        $cct_module = self::get_cct_module();
        if ( ! $cct_module ) {
            return;
        }
        
        // Check if already registered
        if ( $cct_module->manager->get_content_types( self::SLUG ) ) {
            return;
        }
        
        // Register CCT
        $args = array(
            'slug' => self::SLUG,
            'name' => 'E-commerce Products',
            'show_edit_link' => true,
            'hide_field_names' => false,
            'fields' => array(
                array(
                    'id' => self::FIELD_ID_BASE + 1,
                    'name' => 'product_name',
                    'title' => 'Product Name',
                    'type' => 'text',
                    'width' => '100%',
                    'is_required' => true,
                ),
                array(
                    'id' => self::FIELD_ID_BASE + 2,
                    'name' => 'description',
                    'title' => 'Description',
                    'type' => 'textarea',
                    'width' => '100%',
                ),
                array(
                    'id' => self::FIELD_ID_BASE + 3,
                    'name' => 'price',
                    'title' => 'Price',
                    'type' => 'number',
                    'width' => '50%',
                ),
                array(
                    'id' => self::FIELD_ID_BASE + 4,
                    'name' => 'category',
                    'title' => 'Category',
                    'type' => 'text',
                    'width' => '50%',
                ),
                array(
                    'id' => self::FIELD_ID_BASE + 5,
                    'name' => 'sku',
                    'title' => 'SKU',
                    'type' => 'text',
                    'width' => '50%',
                ),
                array(
                    'id' => self::FIELD_ID_BASE + 6,
                    'name' => 'created_by_assistant',
                    'title' => 'Created by Assistant',
                    'type' => 'text',
                    'width' => '50%',
                ),
            ),
        );
        
        $cct_module->data->set_request( $args );
        $cct_module->data->edit_item( false );
    }
}
```

### 8. Integration with Toolkit Settings Base Class

Update `class-wp-mcp-ai-toolkit-settings-base.php`:

```php
protected $cct_entities = array(); // Array of CCT entity types for this toolkit

// Add method to get CCT data
protected function get_cct_items( $entity_type, $args = array() ) {
    // Query CCT items
}

// Add method for Research & Add tab
protected function render_research_tab() {
    if ( empty( $this->cct_entities ) ) {
        parent::render_research_tab();
        return;
    }
    
    // Render CCT-based research UI
    foreach ( $this->cct_entities as $entity ) {
        $this->render_cct_entity_section( $entity );
    }
}
```

## Implementation Priority

1. **High Priority** (Implement First):
   - E-commerce Products CCT
   - Social Media Content Calendar CCT
   - Financial Goals CCT

2. **Medium Priority**:
   - Calendar Booking Services CCT
   - DJ Equipment CCT
   - Translation Memory CCT

3. **Low Priority** (Can be added later):
   - AI Tool Builder Templates CCT
   - Media Collections CCT (if migrating from CPT)

## Next Steps

1. Create base CCT class for toolkit entities
2. Implement first 3 high-priority CCTs
3. Update toolkit settings base class to support CCT
4. Build Research & Add UI components
5. Test with one toolkit (E-commerce recommended)
6. Roll out to other toolkits

## Notes

- CCT requires JetEngine plugin to be active
- Provide graceful fallbacks when JetEngine is not available
- Document CCT schema for each toolkit
- Add filters for developers to extend CCT fields
- Consider using JetEngine Forms for Research & Add UI
