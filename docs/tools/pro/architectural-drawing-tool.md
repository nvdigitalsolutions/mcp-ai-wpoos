# Architectural Drawing Tool (Pro)

## Overview

The **Generate Architectural Drawing** tool is a Pro AI-enhanced feature that uses artificial intelligence to create advanced architectural vector drawings including floor plans, elevations, sections, and construction details. It's similar to the Graphic Editor Suite but specifically designed for architectural drafting professionals.

## Tool Information

- **Slug**: `generate_architectural_drawing`
- **Category**: Pro Tools - Architectural Drawing Suite
- **API**: OpenAI DALL-E, Google Gemini Imagen
- **Capability Flags**: `pro`, `write`, `external-api`, `requires-api-key`, `requires-capability`, `rate-limited`, `costs-money`

## Features

### Drawing Types (10 Types)

1. **floor_plan** - Architectural floor plans with walls, doors, windows, room layouts
2. **site_plan** - Site plans showing building location, property lines, parking, landscaping
3. **elevation** - Building elevations showing exterior facade, materials, windows
4. **section** - Building sections showing floor-to-floor heights and interior spaces
5. **detail** / **construction_detail** - Detailed construction details with precise connections
6. **reflected_ceiling_plan** - RCPs showing ceiling grid, lighting, MEP elements
7. **roof_plan** - Roof plans with geometry, slopes, drainage
8. **3d_axonometric** - 3D axonometric views showing spatial relationships
9. **isometric** - Isometric architectural drawings
10. **construction_detail** - Detailed construction assembly drawings

### Style Options (6 Styles)

1. **technical** - Clean, precise technical drawing style (default)
2. **sketched** - Hand-drawn architectural sketch style
3. **rendered** - Fully rendered with materials and shadows
4. **line_drawing** - Pure line work, no fills
5. **annotated** - With dimensions, notes, and annotations
6. **schematic** - Simplified schematic diagram style

## Usage Examples

### Example 1: Generate a Floor Plan

```json
{
  "drawing_type": "floor_plan",
  "description": "A 2-bedroom residential home with open-concept kitchen and living room, master bedroom with ensuite bathroom, second bedroom, and guest bathroom",
  "building_type": "residential",
  "style": "technical",
  "dimensions": {
    "width": "50 feet",
    "length": "40 feet"
  },
  "annotations": true,
  "scale": "1/4\"=1'-0\""
}
```

### Example 2: Generate a Building Elevation

```json
{
  "drawing_type": "elevation",
  "description": "Modern commercial building with large glass curtain wall system, concrete structural columns, and metal panel cladding",
  "building_type": "commercial",
  "style": "rendered",
  "materials": ["glass", "concrete", "metal panels"],
  "dimensions": {
    "width": "120 feet",
    "height": "45 feet"
  },
  "code_requirements": "IBC"
}
```

## Requirements

- **WordPress Capability**: `upload_files`
- **API Key**: OpenAI API key or Google Gemini API key
- **Pro License**: Requires Pro addon activation

## Best Practices

1. **Be Specific**: Provide detailed descriptions with specific requirements
2. **Include Dimensions**: Always specify dimensions for scale reference
3. **Use Technical Language**: Use proper architectural terminology
4. **Specify Materials**: List all materials to be shown
5. **Choose Appropriate Style**: Technical for construction docs, rendered for presentations

For complete documentation, see the full tool reference in the repository.
