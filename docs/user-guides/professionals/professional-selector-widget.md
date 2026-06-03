# Professional Selector Widget Documentation

## Overview

The Professional Selector widget allows users to select a professional, AI provider, and model from the frontend before starting a chat session. This provides a flexible way to let users choose their own configuration dynamically.

## Available Implementations

### 1. Shortcode

**Basic Usage:**
```
[mcp_ai_professional_selector]
```

**With Options:**
```
[mcp_ai_professional_selector 
    default_professional="123" 
    default_provider="openai" 
    default_model="gpt-4o"
    show_temperature="true"
    allow_guests="true"
    save_transcript="true"
    enable_streaming="true"
    template="classic"]
```

**Shortcode Attributes:**

- `default_professional` - Pre-select a professional (post ID)
- `default_provider` - Pre-select a provider (openai, gemini, ollama, lm_studio, anthropic, huggingface)
- `default_model` - Pre-select a model ID
- `show_temperature` - Show temperature control (true/false)
- `allow_guests` - Allow non-logged-in users (true/false)
- `save_transcript` - Save chat transcripts to JetEngine CCT (true/false)
- `enable_streaming` - Enable SSE streaming for responses (true/false)
- `allow_sensitive_tools` - Allow sensitive tools that modify content (true/false)
- `template` - Chat UI template (classic, speech-bubbles, compact, sidebar)

### 2. Elementor Widget

The **NV oOS Professional Selector** widget is available in the Elementor editor under the "General" category.

**Widget Controls:**

**Professional Selector Settings:**
- Default Professional - Dropdown to pre-select a professional
- Default Provider - Dropdown to pre-select a provider
- Default Model - Text field for model ID
- Show Temperature Control - Toggle to show/hide temperature adjustment

**Chat Settings:**
- Allow Guests - Enable guest access
- Save transcripts to JetEngine - Toggle transcript saving
- Enable SSE Streaming - Toggle streaming responses
- Allow Sensitive Tools - Toggle sensitive tool access
- Chat Template - Choose UI template

### 3. Gutenberg Block

Search for "Professional Selector Chat" in the block inserter.

**Block Attributes:**
- Default Professional
- Default Provider
- Default Model
- Show Temperature
- Allow Guests
- Save Transcript
- Enable Streaming
- Allow Sensitive Tools
- Template

## How It Works

1. **Selection Phase**: User sees a form with three dropdowns:
   - Professional (from mcp_ai_profession CPT)
   - AI Provider (OpenAI, Gemini, Ollama, etc.)
   - Model (dynamically loaded based on provider)
   
2. **Model Loading**: When a provider is selected, available models are fetched via AJAX and populated in the model dropdown.

3. **Professional Defaults**: When a professional is selected, their default provider, model, and temperature are automatically filled (if configured).

4. **Chat Initialization**: After clicking "Start Chat", the chat interface appears with the selected configuration.

5. **Configuration Display**: The selected professional, provider, and model are displayed above the chat with a "Change Selection" button.

## Professional Configuration

To set defaults for a professional:

1. Go to **Professions** in the WordPress admin
2. Edit a profession
3. Set the following meta fields:
   - `_wp_mcp_ai_profession_provider`
   - `_wp_mcp_ai_profession_model`
   - `_wp_mcp_ai_profession_temperature`

These defaults will auto-populate when the professional is selected in the frontend widget.

## Frontend User Experience

1. User lands on page with professional selector
2. Selects their desired professional
3. Chooses AI provider
4. Picks a model from dynamically loaded list
5. (Optional) Adjusts temperature if enabled
6. Clicks "Start Chat"
7. Chat interface appears with their configuration
8. Can click "Change Selection" to start over

## Styling

The widget includes responsive CSS styling with:
- Modern card-based design
- Gradient buttons
- Loading spinners
- Error messaging
- Mobile-friendly layout

Custom styling can be added by targeting these classes:
- `.wp-mcp-ai-professional-selector`
- `.wp-mcp-ai-professional-selector__form`
- `.wp-mcp-ai-professional-selector__select`
- `.wp-mcp-ai-professional-selector__button`
- `.wp-mcp-ai-professional-selector__chat-container`

## JavaScript API

The professional selector exposes a JavaScript API:

```javascript
// Access the configuration
const config = jQuery('[data-wp-mcp-ai-professional-selector]').data('selector-config');

// Access the current state
const state = jQuery('[data-wp-mcp-ai-professional-selector]').data('selector-state');
```

## Security

- All AJAX requests are nonce-protected
- Professional selector supports guest access when enabled
- Model fetching works for both logged-in and guest users
- Chat permissions are enforced based on professional/assistant configuration

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS plugin active
- At least one published Professional (mcp_ai_profession CPT)
- Configured AI providers in plugin settings

## Troubleshooting

**Models not loading:**
- Check that the provider is configured in plugin settings
- Verify API keys are entered correctly
- Check browser console for JavaScript errors

**Professional not found:**
- Ensure the professional post is published
- Check that the post type is `mcp_ai_profession`

**Chat not starting:**
- Verify all three fields (professional, provider, model) are selected
- Check that the assistant associated with the professional exists
- Review browser console for errors

## Example Use Cases

1. **Customer Support Portal**: Let customers choose between different support specialists
2. **Educational Platform**: Allow students to select subject-specific tutors
3. **Multi-brand Website**: Let users choose brand-specific AI assistants
4. **Testing Environment**: Enable users to test different AI model configurations

## API Endpoints

The widget uses these AJAX endpoints:

- `wp_mcp_ai_get_professional_config` - Fetch professional defaults
- `wp_mcp_ai_get_models_for_provider` - Get models for selected provider

Both endpoints support `wp_ajax` and `wp_ajax_nopriv` for logged-in and guest access.
