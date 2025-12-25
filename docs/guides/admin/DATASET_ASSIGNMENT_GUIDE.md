# Dataset Assignment Guide for WP oOS Assistants

## Overview

This guide explains how to assign preferred HuggingFace datasets to AI assistants in WP oOS, enabling them to make smarter dataset recommendations tailored to your specific use cases.

## What Are Preferred Datasets?

Preferred datasets are pre-configured datasets that an assistant should prioritize when:
- Recommending datasets for a use case
- Suggesting training data examples
- Finding relevant benchmark datasets

When you assign preferred datasets to an assistant, those datasets receive a significant boost in relevance scoring (+50 points), ensuring they appear at the top of recommendations.

## Why Use Preferred Datasets?

### 1. **Consistency**
Your assistant always recommends the same reliable datasets for your domain, ensuring consistent advice across conversations.

### 2. **Domain Specialization**
Tailor assistants to specific domains:
- **Content Moderation**: Prefer toxicity and civility datasets
- **E-commerce**: Prefer product and review datasets
- **Healthcare**: Prefer medical Q&A and clinical datasets
- **Education**: Prefer academic and instructional datasets

### 3. **Time Savings**
Users don't have to specify which datasets to use - the assistant already knows your preferences.

### 4. **Quality Control**
Pre-vet datasets for quality, relevance, and appropriateness before they're recommended to users.

## How to Assign Datasets

### Step 1: Enable HuggingFace Datasets Integration

1. Go to **WP oOS → Settings → Providers**
2. Scroll to **HuggingFace Dataset Viewer**
3. Check **"Enable tools for querying HuggingFace datasets"**
4. Click **Save Changes**

### Step 2: Edit or Create an Assistant

1. Go to **Assistants → All Assistants**
2. Click **Edit** on an existing assistant, or click **Add New**
3. Configure the assistant's basic settings (name, provider, model, etc.)

### Step 3: Assign Preferred Datasets

1. Scroll down to the **Preferred Datasets** metabox
2. Use the filters to find datasets:
   - **Category Filter**: NLP, Vision, Audio, Multimodal
   - **Search Box**: Search by name, description, or tags
3. Check the boxes next to datasets you want to assign (max 10)
4. Click **Update** to save

### Step 4: Test the Assistant

1. Open a chat with the assistant
2. Ask: "What datasets would you recommend for [your use case]?"
3. Verify that preferred datasets appear with priority

## Available Datasets

The metabox includes 20 curated datasets across 4 categories:

### NLP Datasets
- **SQuAD** - Question answering (100K Q&A pairs)
- **IMDB Movie Reviews** - Sentiment analysis (50K reviews)
- **CNN/DailyMail** - Text summarization (300K articles)
- **XSum** - Single-sentence summaries (227K articles)
- **AG News** - News classification (127K articles)
- **Yelp Reviews** - 5-star reviews (650K reviews)
- **Jigsaw Toxic Comments** - Content moderation (160K comments)
- **Civil Comments** - Nuanced moderation (2M comments)
- **mC4** - Multilingual corpus (101 languages)
- **MedQA** - Medical Q&A (60K+ pairs)
- **Financial PhraseBank** - Financial sentiment (4.8K sentences)
- **SciQ** - Science education (13K questions)

### Vision Datasets
- **COCO** - Object detection (330K images)
- **Fashion MNIST** - Fashion classification (70K images)
- **Food-101** - Food classification (101K images)

### Multimodal Datasets
- **Flickr30k** - Image captioning (31K images)
- **MS COCO Captions** - Image-text pairs (330K images)

### Audio Datasets
- **LibriSpeech** - Speech recognition (1000 hours)
- **Common Voice** - Multilingual speech (100+ languages)

## Use Case Examples

### Example 1: Content Moderation Assistant

**Goal**: Create an assistant specialized in moderating comments and user-generated content.

**Assigned Datasets**:
- ✓ Jigsaw Toxic Comments
- ✓ Civil Comments
- ✓ IMDB Movie Reviews (for general sentiment)

**Result**:
```
User: "Help me moderate these comments"
Assistant: "I'll analyze these using patterns from Jigsaw Toxic Comments 
and Civil Comments datasets, which contain millions of moderated examples."
```

### Example 2: E-commerce Product Assistant

**Goal**: Help with product categorization, description writing, and review analysis.

**Assigned Datasets**:
- ✓ Fashion MNIST (for clothing products)
- ✓ Food-101 (for food products)
- ✓ Yelp Reviews (for review analysis)

**Result**:
```
User: "How should I categorize my new clothing line?"
Assistant: "Based on Fashion MNIST patterns, I recommend these categories..."
```

### Example 3: Multilingual Content Assistant

**Goal**: Support creating and translating content in multiple languages.

**Assigned Datasets**:
- ✓ mC4 (101 languages)
- ✓ Common Voice (multilingual speech)

**Result**:
```
User: "Help me translate this content to Spanish"
Assistant: "Using mC4 multilingual patterns, here's the Spanish version..."
```

### Example 4: Educational Q&A Assistant

**Goal**: Answer student questions across science and general knowledge.

**Assigned Datasets**:
- ✓ SQuAD (general Q&A)
- ✓ SciQ (science questions)
- ✓ MedQA (medical/health questions)

**Result**:
```
User: "What datasets help with science homework?"
Assistant: "SciQ contains 13K science questions perfect for educational use..."
```

### Example 5: SEO & Accessibility Assistant

**Goal**: Generate alt text and image descriptions for better SEO.

**Assigned Datasets**:
- ✓ Flickr30k (image captions)
- ✓ MS COCO Captions (image descriptions)

**Result**:
```
User: "Generate alt text for product images"
Assistant: "Using Flickr30k and COCO patterns, here are SEO-optimized descriptions..."
```

## Best Practices

### Do's

✅ **Choose Related Datasets**: Select datasets directly relevant to your assistant's purpose  
✅ **Limit to 5-7 Datasets**: More isn't always better - focus on quality over quantity  
✅ **Mix Priorities**: Include both critical and high-priority datasets  
✅ **Test Recommendations**: Verify the assistant prioritizes your selections  
✅ **Update Periodically**: Review and adjust as new datasets become available

### Don'ts

❌ **Don't Mix Unrelated Domains**: Avoid combining e-commerce and medical datasets unless truly needed  
❌ **Don't Exceed 10 Datasets**: The UI enforces a 10-dataset limit to prevent decision paralysis  
❌ **Don't Assign Random Datasets**: Each dataset should serve a specific purpose  
❌ **Don't Forget to Enable Integration**: Datasets won't work without HuggingFace integration enabled

## Technical Details

### How Scoring Works

When `huggingface_recommended_datasets` tool is called:

1. **Base Score Calculation** (0-100 points)
   - Tag matches: +20 points each
   - Use case matches: +15 points each
   - Description matches: +10 points
   - Priority boost: Critical (+30), High (+20), Medium (+10)

2. **Preference Boost** (+50 points)
   - Preferred datasets get +50 additional points
   - This usually ensures top-3 placement

3. **Sorting**
   - Datasets sorted by total score (descending)
   - Top N returned (default: 5, max: 20)

### Example Scoring

**Query**: "comment moderation"

**Non-preferred dataset (Yelp Reviews)**:
- Tag match "review": +20
- Use case match "review analysis": +15  
- Priority high: +20
- **Total: 55 points**

**Preferred dataset (Jigsaw Toxic Comments)**:
- Tag match "moderation": +20
- Use case match "comment moderation": +15
- Priority critical: +30
- **Preference boost: +50**
- **Total: 115 points** ✓ Top recommendation

### Data Storage

Preferred datasets are stored as:
- **Post Meta Key**: `_wp_mcp_ai_preferred_datasets`
- **Data Structure**: Array of objects
  ```json
  [
    {
      "dataset": "jigsaw_toxicity_pred",
      "name": "Jigsaw Toxic Comments",
      "category": "nlp",
      "priority": "critical"
    }
  ]
  ```
- **Max Items**: 10 datasets per assistant
- **Sanitization**: Automatic via `sanitize_preferred_datasets_meta()`

### REST API Integration

The `preferred_datasets` array is automatically included in:
- `get_assistant_configuration()` response
- Assistant context during chat execution
- Tool execution context

## Troubleshooting

### Metabox Not Visible

**Problem**: The Preferred Datasets metabox doesn't appear on the assistant edit screen.

**Solutions**:
1. Verify HuggingFace Datasets integration is enabled in **Settings → Providers**
2. Check that you're editing an assistant post, not a different post type
3. Ensure screen options (top right) hasn't hidden the metabox

### Datasets Not Prioritized

**Problem**: Assigned datasets don't appear first in recommendations.

**Solutions**:
1. Verify datasets were saved (check after page refresh)
2. Ensure the query is somewhat relevant to the dataset
3. Remember: +50 boost doesn't guarantee #1 if base score is very low
4. Try more specific queries related to the dataset's domain

### Search Not Finding Datasets

**Problem**: Can't find a specific dataset in the search.

**Solutions**:
1. Try searching by category instead (use category filter)
2. Search uses name, description, and tags - try different keywords
3. Only 20 curated datasets are in the metabox (full catalog has 50+)

### Changes Not Saving

**Problem**: Selections disappear after clicking Update.

**Solutions**:
1. Check for JavaScript errors in browser console
2. Verify you have permission to edit the assistant
3. Ensure nonce validation isn't failing
4. Try disabling other plugins temporarily

## FAQ

**Q: Can I add custom datasets not in the list?**  
A: The metabox shows 20 curated datasets. The full tool catalog has 50+ datasets. Custom datasets would require code modifications.

**Q: Do preferred datasets affect other HuggingFace tools?**  
A: Currently, only the `huggingface_recommended_datasets` tool uses preferences. Other tools (preview_rows, search, etc.) work independently.

**Q: Can multiple assistants share the same preferences?**  
A: Yes, but you must configure each assistant separately. There's no "template" feature yet.

**Q: What happens if I deactivate HuggingFace integration?**  
A: The Preferred Datasets metabox disappears, but saved preferences remain in the database. They'll work again if you re-enable integration.

**Q: Can users override assistant preferences?**  
A: Users can't see or change assistant preferences directly. They can still ask for specific datasets by name.

**Q: Are there API limits?**  
A: HuggingFace Dataset Viewer API has rate limits (60 requests/hour for free tier). Caching helps minimize requests.

## Related Documentation

- **[HuggingFace Datasets Quick Start](../../features/ai-providers/huggingface/HUGGINGFACE_DATASETS_QUICK_START.md)** - Overview and basic usage
- **[HuggingFace Top Datasets](../../features/ai-providers/huggingface/HUGGINGFACE_TOP_DATASETS.md)** - Complete dataset catalog  
- **[Tool Reference](../../reference/tools/tool-reference.md)** - All HuggingFace dataset tools
- **<!-- Assistant Configuration guide coming soon -->** - General assistant setup

## Support

Need help? Check:
1. **Admin UI**: Tooltips and descriptions in the Preferred Datasets metabox
2. **Logs**: Enable logging in WP oOS settings to see tool execution
3. **GitHub Issues**: Report bugs or request features
4. **Documentation Index**: Browse all available docs

---

**Last Updated**: 2025-12-23  
**Version**: 1.0.0  
**Status**: Production Ready ✅
