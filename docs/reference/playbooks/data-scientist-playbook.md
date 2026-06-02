# Data Scientist Professional Playbook

## Overview

**Profession:** Data Scientist  
**Primary Toolkit:** Data & Analytics  
**Recommended Pattern:** Peer-to-Peer Collaboration  
**Risk Tolerance:** Standard  
**Team Size:** 3-4 agents  

## Description

Data Scientists analyze complex datasets, build predictive models, and extract actionable insights from data. This playbook provides AI-assisted workflows for data analysis, visualization, statistical modeling, and reporting.

## Primary Tools (14 Tools)

### Data Analysis & Processing
- `create_chart` - Generate visualizations from data
- `create_vector_store` - Store and index data vectors
- `semantic_content_search` - Semantic data search
- `semantic_context_search` - Context-based search
- `batch_embed_content` - Batch embedding operations
- `create_text_embeddings` - Create text embeddings

### AI & Model Operations
- `huggingface_dataset_search` - Search ML datasets
- `list_available_models` - Browse AI models
- `suggest_best_model` - Get model recommendations
- `get_model_information` - Model metadata
- `count_tokens` - Token counting for LLMs

### Analysis & Reporting
- `client_analyze_sentiment` - Sentiment analysis
- `client_extract_entities` - Entity extraction
- `client_question_answering` - Q&A from data

## Recommended Multi-Agent Pattern

### Peer-to-Peer Collaboration

**Why This Pattern:**
- Data science often requires multiple perspectives
- Collaborative analysis produces better insights
- No single "correct" approach to many problems
- Democratic decision-making for model selection

**Team Structure:**
```
Data Analyst Agent ←→ ML Engineer Agent
         ↕                    ↕
Statistical Agent ←→ Visualization Agent
```

## Common Use Cases

### 1. Exploratory Data Analysis (EDA)
- Load and profile data
- Generate visualizations
- Statistical analysis
- Document findings

**Time Estimate:** 30-45 minutes

### 2. Predictive Model Development
- Dataset selection
- Model exploration
- Feature engineering
- Model evaluation
- Documentation

**Time Estimate:** 1-2 hours

### 3. Data Insight Report Generation
- Data querying
- Sentiment analysis
- Entity extraction
- Visualization creation
- Report assembly

**Time Estimate:** 45-60 minutes

## Best Practices

1. **Data Quality First** - Always profile data before analysis
2. **Reproducibility** - Document all steps in reports
3. **Collaborative Analysis** - Use peer-to-peer pattern for complex problems
4. **Visualization Standards** - Use consistent color schemes
5. **Model Selection** - Compare multiple models systematically

## Success Metrics

- **Analysis Completion Time:** < 1 hour per dataset
- **Insight Quality:** Actionable recommendations
- **Visualization Clarity:** Executive-ready charts
- **Documentation:** Complete and reproducible

---

**Version:** 1.0  
**Date:** January 30, 2026  
**Status:** Production Ready
