# Profession Dataset Assignment Methodology

This document describes how HuggingFace datasets are assigned to professions in the WP oOS plugin.

## Overview

Each profession in the system can have up to 10 preferred datasets assigned. These datasets are used by the `huggingface_recommended_datasets` tool to provide contextually relevant dataset recommendations when the AI assistant is operating in a specific profession mode.

## Dataset Categories

Datasets are organized into four main categories:

1. **NLP (Natural Language Processing)**: Text-based datasets for language tasks
2. **Vision**: Image-based datasets for visual tasks
3. **Audio**: Sound and speech-based datasets
4. **Multimodal**: Datasets combining multiple data types (e.g., image + text)

## Assignment Strategy

Datasets are assigned to professions based on their typical use cases and expertise areas:

### NLP Datasets

**SQuAD (Question Answering)**
- Assigned to: Legal advisors, lawyers, data scientists, research professions
- Use case: Question-answering systems, chatbots, assistants

**CNN/DailyMail & XSum (Summarization)**
- Assigned to: Content creators, medical writers, researchers, healthcare advisors
- Use case: Document summarization, content condensation

**IMDB Reviews & Yelp Reviews (Sentiment Analysis)**
- Assigned to: Marketing consultants, UX designers, film directors
- Use case: Review analysis, sentiment classification, user feedback

**AG News (Classification)**
- Assigned to: Marketing consultants, crisis communications managers
- Use case: Content categorization, news classification

**Jigsaw Toxic Comments & Civil Comments (Moderation)**
- Assigned to: Content creators, HR consultants, community managers
- Use case: Content moderation, safety, community management

### Vision Datasets

**COCO (Object Detection)**
- Assigned to: Graphic designers, photographers, cinematographers, data scientists
- Use case: Object recognition, visual analysis, image understanding

**Fashion MNIST**
- Assigned to: E-commerce, fashion, product photography
- Use case: Product classification, fashion analysis

**Food-101**
- Assigned to: Restaurant consultants, culinary professions
- Use case: Food recognition, menu analysis

### Multimodal Datasets

**Flickr30k & MS COCO Captions**
- Assigned to: Video producers, photographers, graphic designers
- Use case: Image captioning, alt text generation, accessibility

### Audio Datasets

**LibriSpeech & Common Voice**
- Assigned to: Sound designers, audio professionals
- Use case: Speech recognition, audio transcription, accessibility

## Implementation

### File Structure

```
includes/professions/
├── profession-dataset-mappings.php  # Dataset mappings
└── class-wp-mcp-ai-profession-seeder.php  # Loads and applies mappings
```

### Mapping Format

```php
'profession_slug' => array(
    array(
        'dataset'  => 'owner/dataset-name',
        'name'     => 'Human Readable Name',
        'category' => 'nlp|vision|audio|multimodal',
        'priority' => 'critical|high|medium|low',
    ),
    // ... up to 10 datasets
)
```

### Priority Levels

- **critical**: Essential datasets for the profession's core tasks
- **high**: Important datasets frequently relevant to the profession
- **medium**: Useful datasets for secondary tasks
- **low**: Optional datasets for specialized cases

## Profession to Dataset Assignments

### Data Science & AI Professions

- **data_scientist**: SQuAD, CNN/DailyMail, COCO, IMDB (comprehensive ML toolkit)
- **computer_scientist**: SQuAD, CNN/DailyMail (NLP research focus)
- **research_scientist**: CNN/DailyMail, XSum (research & summarization)
- **statistician**: CNN/DailyMail (data analysis)

### Healthcare & Medical

- **healthcare_advisor**: CNN/DailyMail, SQuAD (patient education, Q&A)
- **medical_researcher**: CNN/DailyMail, XSum (literature review, research)
- **pharmacist**: SQuAD (medication Q&A)
- **pharmaceutical_researcher**: CNN/DailyMail (research literature)

### Creative Professions

- **graphic_designer**: COCO, Flickr30k, MS COCO Captions (visual design)
- **photographer**: COCO, Flickr30k (image understanding, captioning)
- **video_producer**: MS COCO Captions, Flickr30k (video captioning)
- **sound_designer**: LibriSpeech, Common Voice (audio processing)
- **film_director**: IMDB Reviews, MS COCO Captions (storytelling, reviews)

### Content & Writing

- **content_creator**: CNN/DailyMail, XSum, Jigsaw Toxic (content creation, moderation)
- **screenwriter**: IMDB Reviews, CNN/DailyMail (storytelling, review analysis)
- **medical_writer**: CNN/DailyMail, XSum (medical writing, summarization)

### Marketing & Business

- **marketing_consultant**: IMDB Reviews, Yelp Reviews, AG News (sentiment, trends)
- **business_consultant**: CNN/DailyMail, Yelp Reviews (market analysis)
- **restaurant_consultant**: Yelp Reviews, Food-101 (reviews, food recognition)

### Legal & Financial

- **lawyer**: SQuAD, CNN/DailyMail (legal Q&A, document review)
- **legal_advisor**: SQuAD (legal guidance)
- **accountant**: SQuAD (financial Q&A)
- **financial_advisor**: CNN/DailyMail (financial news, trends)

### Community & HR

- **hr_consultant**: Jigsaw Toxic, Civil Comments (workplace moderation)

### Emergency & Communications

- **crisis_communications_manager**: AG News, CNN/DailyMail (news monitoring)

## Usage in Code

### Automatic Assignment During Seeding

```php
// In profession seeder
if ( ! isset( $profession_data['preferred_datasets'] ) && isset( $profession_data['slug'] ) ) {
    $datasets = wp_mcp_ai_get_profession_dataset_recommendations( $profession_data['slug'] );
    if ( ! empty( $datasets ) ) {
        $profession_data['preferred_datasets'] = $datasets;
    }
}
```

### Retrieving Datasets

```php
// Get datasets for a specific profession
$datasets = wp_mcp_ai_get_profession_dataset_recommendations( 'data_scientist' );

// Get all profession mappings
$all_mappings = wp_mcp_ai_get_all_profession_dataset_mappings();
```

### Storing in WordPress

Datasets are stored as post meta on profession CPT:
- Meta key: `_wp_mcp_ai_profession_preferred_datasets`
- Format: Serialized array of dataset objects
- Limit: Maximum 10 datasets per profession

## Adding New Mappings

To add datasets for a new profession:

1. Open `includes/professions/profession-dataset-mappings.php`
2. Add a new entry to the array returned by `wp_mcp_ai_get_all_profession_dataset_mappings()`
3. Follow the format:
   ```php
   'new_profession_slug' => array(
       array(
           'dataset'  => 'huggingface/dataset-id',
           'name'     => 'Dataset Display Name',
           'category' => 'nlp', // or vision, audio, multimodal
           'priority' => 'high', // or critical, medium, low
       ),
   ),
   ```
4. Consider the profession's typical use cases
5. Assign datasets that will genuinely help the profession's AI assistant
6. Prioritize based on frequency of use

## Testing

Tests are located in `tests/test-profession-dataset-mappings.php`:

- Validates dataset file exists and loads
- Checks dataset structure (required keys)
- Verifies category assignments (creative → vision, etc.)
- Tests sanitization and limits (max 10 datasets)
- Validates all mappings have proper structure

## Future Enhancements

Potential improvements:

1. **Dynamic recommendations**: Use profession expertise areas to suggest datasets
2. **UI for dataset management**: Admin interface to add/remove datasets per profession
3. **Dataset usage tracking**: Monitor which datasets are most useful
4. **Custom dataset support**: Allow users to define their own dataset mappings
5. **Context-aware filtering**: Filter datasets based on conversation context

## References

- [HuggingFace Datasets Hub](https://huggingface.co/datasets)
- [WP oOS Tool Reference](reference/tools/tool-reference.md)
- [Profession CPT Documentation](../README.md)
