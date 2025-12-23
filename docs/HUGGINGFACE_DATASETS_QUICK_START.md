# HuggingFace Datasets - Quick Start Guide

## Overview

Access 50+ top free HuggingFace datasets directly from WordPress. No downloads required - query datasets via API instantly!

## Enabling Datasets

1. Go to **WP oOS → Settings → Providers**
2. Scroll to **HuggingFace Dataset Viewer** section
3. Check **"Enable tools for querying HuggingFace datasets"**
4. (Optional) Add API token for private datasets
5. Click **Save Changes**

## Browse Datasets

1. Go to **WP oOS → HF Datasets** in WordPress admin
2. Browse the visual dataset catalog
3. Filter by:
   - **Category**: NLP, Vision, Audio, Multimodal
   - **Priority**: Critical, High, Medium
   - **Search**: Name, tags, or use case
4. Click **Preview** to see dataset details and sample data
5. Click **Copy Code** to get the query command
6. Click **View on HF** to see the full dataset on HuggingFace Hub

## Using in AI Assistants

### Example 1: Get Sentiment Analysis Examples
```
Please give me examples of sentiment analysis from the IMDB dataset

[Assistant will use: huggingface_dataset_preview_rows(dataset="stanfordnlp/imdb", split="train", limit=5)]
```

### Example 2: Find Datasets for My Use Case
```
What datasets would be good for comment moderation on my WordPress site?

[Assistant will use: huggingface_recommended_datasets(use_case="comment moderation", limit=5)]
```

### Example 3: Search Dataset Content
```
Search the SQuAD dataset for questions about "artificial intelligence"

[Assistant will use: huggingface_dataset_search(dataset="rajpurkar/squad", split="train", query="artificial intelligence", limit=10)]
```

### Example 4: Get Paginated Dataset Rows
```
Get rows 100-110 from the IMDB training set

[Assistant will use: huggingface_dataset_get_rows(dataset="stanfordnlp/imdb", split="train", offset=100, length=10)]
```

### Example 5: Filter Dataset by Criteria
```
Find all positive reviews (label = 1) in the IMDB dataset sorted by length

[Assistant will use: huggingface_dataset_filter(dataset="stanfordnlp/imdb", split="train", where="label = 1", orderby="length DESC", length=20)]
```

### Example 6: Get Dataset Statistics
```
What are the statistics for the SQuAD training set?

[Assistant will use: huggingface_dataset_get_statistics(dataset="rajpurkar/squad", split="train")]
```

## Available Tools

### 1. `huggingface_recommended_datasets`
**Get smart recommendations** based on your use case
```php
Parameters:
  - use_case: "comment moderation", "blog summarization", etc.
  - category: "nlp", "vision", "audio", "multimodal", "all"
  - limit: Number of recommendations (default: 5)
```

### 2. `huggingface_dataset_preview_rows`
**Preview dataset rows** for quick inspection
```php
Parameters:
  - dataset: Dataset name (e.g., "squad", "imdb")
  - split: "train", "test", "validation"
  - config: Configuration name (default: "default")
  - limit: Number of rows (1-100, default: 10)
```

### 3. `huggingface_dataset_search`
**Search within dataset** content
```php
Parameters:
  - dataset: Dataset name
  - split: Split name
  - query: Search text
  - config: Configuration (optional)
  - offset: Starting row (for pagination)
  - limit: Number of results (1-100)
```

### 4. `huggingface_dataset_is_valid`
**Validate dataset** exists
```php
Parameters:
  - dataset: Dataset name to check
```

### 5. `huggingface_dataset_list_splits`
**List available splits** (train/test/validation)
```php
Parameters:
  - dataset: Dataset name
```

### 6. `huggingface_dataset_get_info`
**Get metadata and description**
```php
Parameters:
  - dataset: Dataset name
```

### 7. `huggingface_dataset_get_size`
**Get size information** (rows, bytes)
```php
Parameters:
  - dataset: Dataset name
```

### 8. `huggingface_dataset_get_rows`
**Get paginated rows** with offset and length control
```php
Parameters:
  - dataset: Dataset name
  - split: Split name
  - config: Configuration (optional)
  - offset: Starting row (0-based, default: 0)
  - length: Number of rows (1-100, default: 10)
```

### 9. `huggingface_dataset_filter`
**Filter dataset rows** using SQL-like expressions
```php
Parameters:
  - dataset: Dataset name
  - split: Split name
  - where: Filter expression (e.g., "label = 1", "score > 0.5")
  - config: Configuration (optional)
  - orderby: Sort column (optional, e.g., "score DESC")
  - offset: Starting row (for pagination)
  - length: Number of results (1-100)
```

### 10. `huggingface_dataset_get_statistics`
**Get statistical information** about dataset split
```php
Parameters:
  - dataset: Dataset name
  - split: Split name
  - config: Configuration (optional)
```

### 11. `huggingface_dataset_get_parquet`
**Get Parquet file URLs** for efficient bulk data access
```php
Parameters:
  - dataset: Dataset name
```

## Top Datasets by Use Case

### Content Creation
- **CNN/DailyMail** (`abisee/cnn_dailymail`) - Article summarization
- **XSum** (`EdinburghNLP/xsum`) - Single-sentence summaries
- **arXiv** (`arxiv_dataset`) - Scientific papers

### E-Commerce (WooCommerce)
- **Yelp Reviews** (`yelp_review_full`) - Review analysis
- **Fashion MNIST** (`zalando-datasets/fashion_mnist`) - Product classification
- **Food-101** (`ethz/food101`) - Food product categorization

### Community Management
- **Civil Comments** (`google/civil_comments`) - Comment moderation and discussion quality
- **Ubuntu Dialogs** (`ubuntu_dialogs_corpus`) - Support conversations

### SEO & Accessibility
- **Flickr30k** (`nlphuji/flickr30k`) - Image captions for alt text
- **COCO Captions** (`yerevann/coco-captions`) - Image descriptions
- **LibriSpeech** (`librispeech_asr`) - Audio transcription

### Multilingual Sites
- **mC4** (`mc4`) - 101 languages
- **Common Voice** (`mozilla-foundation/common_voice_*`) - Speech in 100+ languages
- **XNLI** (`facebook/xnli`) - Cross-lingual understanding

### Specialized Domains
- **MedQA** (`bigbio/med_qa`) - Medical Q&A
- **Financial PhraseBank** (`financial_phrasebank`) - Financial sentiment
- **MultiLegalPile** (`legal_pile`) - Legal documents
- **SciQ** (`allenai/sciq`) - Science education

## Tips & Best Practices

### Performance
- Start with `limit=5` to minimize token usage
- Use caching (enabled by default, 1 hour)
- Preview before full queries
- Filter by category to narrow results

### Token Management
- Each dataset query uses ~500-2000 tokens
- Preview rows: ~100-500 tokens per row
- Search results: ~200-1000 tokens per result
- Adjust `limit` parameter to control token usage

### Security
- Public datasets work without API token
- Private/gated datasets require HuggingFace token
- Tokens are stored securely (password field)
- Rate limiting: 60 requests/hour per user (free tier)

### Troubleshooting
**"Dataset not found"**
- Check spelling and case sensitivity
- Verify dataset exists on HuggingFace Hub
- Try accessing via web first

**"Rate limit exceeded"**
- Wait 60 minutes for rate limit reset
- Upgrade to HuggingFace Pro ($9/month)
- Enable caching to reduce requests

**"Configuration not found"**
- Use `list_splits` tool to see available configs
- Try `config="default"` if unsure
- Check dataset documentation on HuggingFace

## Examples

### WordPress Use Cases

#### 1. Improve Comment Moderation
```
Assistant, analyze my pending comments and suggest which ones might need moderation 
based on the Civil Comments dataset patterns.
```

#### 2. Generate Alt Text for Images
```
Using the Flickr30k dataset as examples, generate alt text for 
the images I uploaded to my media library.
```

#### 3. Summarize Long Articles
```
Summarize this blog post draft using the summarization style 
from the CNN/DailyMail dataset.
```

#### 4. Categorize Products
```
Help me categorize these new WooCommerce products using 
examples from the Fashion MNIST dataset.
```

#### 5. Multilingual Content
```
Show me examples of how to structure content in Spanish 
using the Common Voice dataset.
```

## Advanced Usage

### Chaining Tools
```
1. First, find relevant dataset:
   huggingface_recommended_datasets(use_case="product reviews")

2. Then, preview it:
   huggingface_dataset_preview_rows(dataset="yelp_review_full", split="train", limit=5)

3. Finally, search for specific examples:
   huggingface_dataset_search(dataset="yelp_review_full", query="excellent service", limit=10)
```

### Custom Workflows
```
Create a workflow that:
1. Searches datasets for examples
2. Analyzes patterns
3. Applies to WordPress content
4. Generates structured output
```

## Resources

- **Full Catalog**: See `docs/HUGGINGFACE_TOP_DATASETS.md`
- **HuggingFace Hub**: https://huggingface.co/datasets
- **Dataset Viewer API**: https://huggingface.co/docs/dataset-viewer
- **WP oOS Docs**: `/docs/DOCUMENTATION_INDEX.md`

## Support

Need help? Check:
1. Dataset preview in admin UI
2. Example code on each card
3. HuggingFace dataset page
4. WP oOS documentation

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-23  
**Status**: Production Ready ✅
