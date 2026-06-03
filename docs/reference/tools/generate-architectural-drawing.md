# Architectural Drawing Generation Tool

## Overview

The **Generate Architectural Drawing** tool is a Pro feature that generates professional architectural drawings using OpenAI DALL-E or Gemini Imagen APIs. It supports 10 drawing types, 6 presentation styles, and professional architectural specifications.

## Tool Slug

`generate_architectural_drawing`

## Drawing Types

The tool supports 10 different types of architectural drawings:

1. **floor_plan** - Layout view from above with walls, doors, windows, and room labels
2. **elevation** - Exterior view of building facade with accurate proportions
3. **section** - Vertical cut through building revealing interior structure
4. **detail** - Enlarged view of specific building component with precise details
5. **site_plan** - Building placement on site with property boundaries and landscaping
6. **reflected_ceiling_plan** - Ceiling layout from below with lighting and HVAC
7. **roof_plan** - Roof layout from above with slopes and drainage
8. **3d_axonometric** - 3D view with parallel projection showing multiple faces
9. **isometric** - 3D isometric projection at 30-degree angles
10. **construction_detail** - Precise construction assembly details with material layers

## Presentation Styles

The tool supports 6 different presentation styles:

1. **technical** - Precise line weights, architectural symbols, professional drafting
2. **sketched** - Hand-drawn sketch style with loose linework
3. **rendered** - Realistic rendering with materials, lighting, and shadows
4. **line_drawing** - Clean, uniform line work without shading
5. **annotated** - Technical style with extensive annotations and dimensions
6. **schematic** - Simplified diagrammatic representation

## Best Practices

1. **Be Specific**: Provide detailed architectural requirements in the prompt
2. **Use Dimensions**: Include dimensional specifications for accuracy
3. **Specify Materials**: List materials for better visual representation
4. **Choose Appropriate Style**: Use `technical` or `annotated` for construction documents
5. **SVG for Scalability**: Use SVG output for drawings that need to scale
6. **Building Codes**: Reference applicable codes for compliance
7. **Scale Notation**: Always include scale for technical drawings

## Tool Category

**Pro Tool** - Available only in the Pro/Full version of the plugin
