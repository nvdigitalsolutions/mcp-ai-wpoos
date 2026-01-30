# [Profession Name] Playbook Template

**Use this template to create new professional playbooks for the NV oOS toolkit enhancement.**

---

## Overview

**Profession Details:**
- **Profession Slug:** `profession_slug` (lowercase, underscores)
- **Display Name:** Profession Name
- **Category:** Primary category (technology, healthcare, business, etc.)
- **Expertise Level:** Entry | Mid-Level | Senior | Expert
- **Last Updated:** YYYY-MM-DD

**Toolkit Associations:**
- **Primary Toolkit(s):** List 1-2 primary toolkits this profession uses most
- **Secondary Toolkit(s):** List 1-2 secondary toolkits for occasional use
- **Pattern Affinity:** Preferred multi-agent pattern(s)

**Example:**
```
Profession Slug: data_scientist
Category: technology
Expertise Level: Senior
Primary Toolkits: data_analytics, ai_model_management
Secondary Toolkits: research_discovery, integration_external
Pattern Affinity: peer_to_peer, experimentation
```

---

## Role Definition

### Primary Responsibilities
What does this profession do? List 3-5 key responsibilities.

**Example:**
- Analyze large datasets to extract meaningful insights
- Build and deploy machine learning models
- Create data visualizations and reports for stakeholders
- Collaborate with engineering teams to implement data solutions
- Develop predictive models to support business decisions

### Key Skills & Competencies
List 5-8 essential skills for this profession.

**Example:**
- Python programming (pandas, scikit-learn, TensorFlow)
- Statistical analysis and hypothesis testing
- Data visualization (Matplotlib, Seaborn, Tableau)
- SQL and database querying
- Machine learning algorithms and model evaluation
- Communication and stakeholder management
- Domain-specific knowledge (e.g., finance, healthcare)

### Domain Knowledge Areas
What specialized knowledge should this profession have?

**Example:**
- Statistical methods and experimental design
- Data preprocessing and feature engineering
- Model selection and hyperparameter tuning
- Ethics and bias in AI/ML systems
- Data privacy and security regulations (GDPR, CCPA)

---

## Tool Recommendations

### Core Tools (Always Available)
Essential tools every agent has access to, regardless of profession.

**Format:** `tool_slug` - Brief description of why it's useful for this profession

**Example:**
1. `web_search` - Research industry trends, datasets, and methodologies
2. `search_content` - Find relevant internal documentation and past analyses
3. `create_post` - Document findings and create reports
4. `save_post` - Update existing documentation with new insights
5. `count_tokens` - Estimate API costs for large language model operations

### Primary Toolkit Tools
Tools from the profession's primary toolkit(s). List 10-15 most relevant tools.

**Format:** `tool_slug` - Use case or workflow scenario

**Example (Data Scientist - Data Analytics Toolkit):**
1. `huggingface_dataset_search` - Find public datasets for analysis or model training
2. `huggingface_dataset_get_rows` - Retrieve sample data for exploratory analysis
3. `huggingface_dataset_get_statistics` - Get dataset metadata (size, columns, distributions)
4. `create_text_embeddings` - Generate embeddings for semantic analysis
5. `batch_embed_content` - Process large volumes of text for vector search
6. `semantic_content_search` - Find related content using embedding similarity
7. `create_chart` - Visualize data distributions and trends
8. `generate_chart` - Create complex multi-panel visualizations
9. `create_vector_store` - Build vector databases for similarity search
10. `list_vector_stores` - Manage multiple vector store projects

### Secondary Toolkit Tools
Tools from secondary toolkits for occasional use. List 5-8 tools.

**Example (Data Scientist - AI & Model Management Toolkit):**
1. `suggest_best_model` - Get recommendations for model selection based on task
2. `list_available_models` - Browse available AI models for experimentation
3. `count_tokens` - Estimate costs before running large inference jobs
4. `openai_usage_analytics` - Track API usage and optimize spending
5. `create_batch` - Run large-scale batch inference jobs

### Optional/Advanced Tools
Tools for specialized scenarios or advanced users. List 3-5 tools.

**Example:**
1. `deep_research` - Conduct comprehensive literature reviews on new techniques
2. `probe_remote_mcp` - Connect to remote data sources or APIs
3. `trigger_all_import` - Import large datasets from external sources
4. `generate_mermaid` - Create workflow diagrams for model pipelines

### Tools to Avoid
List any tools that would be inappropriate or risky for this profession.

**Example:**
- ❌ `delete_cron_job` - Data scientists shouldn't modify system automation
- ❌ `check_site_security` - Outside domain expertise, escalate to security team
- ❌ `purge_cache` - Infrastructure management, escalate to DevOps

---

## Multi-Agent Team Roles

### Preferred Team Patterns
Which multi-agent patterns work best for this profession?

**Format:** 
- **Pattern Name** (Role in team) - Scenario when this pattern is used

**Example:**
- **Peer-to-Peer Collaboration** (Analyst) - Multiple data scientists collaborating on exploratory analysis, each bringing different statistical perspectives
- **Orchestrator** (Executor) - Part of a research team where a lead researcher delegates specific analysis tasks
- **Experimentation Pipeline** (Experimenter) - Testing multiple ML models in parallel to find the best performer

### Team Composition Examples

#### Example 1: [Scenario Name]
**Scenario:** Brief description of when this team composition is used

**Team Structure:**
- **Pattern:** Pattern name
- **Your Role:** Role in this team
- **Team Size:** X agents
- **Other Roles:** List other roles and their professions

**Workflow:**
1. Step 1 (Who does what)
2. Step 2 (Who does what)
3. Step 3 (Who does what)

**Tools Used:** List of tools by role

**Example:**
```
Example 1: Exploratory Data Analysis Team

Scenario: Multiple analysts investigating a complex dataset from different angles

Team Structure:
- Pattern: Peer-to-Peer Collaboration
- Your Role: Statistical Analyst
- Team Size: 4 agents
- Other Roles: 
  - Business Analyst (focuses on business metrics)
  - Machine Learning Engineer (focuses on predictive features)
  - Domain Expert (provides context and validation)

Workflow:
1. All agents receive dataset and problem statement
2. Each agent performs independent analysis using their expertise
3. Agents share findings and discuss patterns
4. Team synthesizes insights into unified report
5. Consensus on recommendations and next steps

Tools Used:
- Data Scientist: huggingface_dataset_get_rows, create_chart, generate_chart
- ML Engineer: create_text_embeddings, suggest_best_model
- Business Analyst: semantic_content_search, create_chart
- All: create_post (collaborative report writing)
```

#### Example 2: [Another Scenario]
(Repeat format above)

---

## Workflows & Use Cases

### Workflow 1: [Workflow Name]

**Trigger:** What initiates this workflow? (User request, scheduled task, event, etc.)

**Objective:** What should be accomplished?

**Prerequisites:** Any required data, permissions, or setup

**Steps:**
1. **Step Name** (Tool: `tool_slug`)
   - Detailed description of action
   - Expected input/output
   - Decision points or validations

2. **Step Name** (Tool: `tool_slug`)
   - ...

3. **Step Name** (Tool: `tool_slug`)
   - ...

**Expected Outcome:** What does success look like?

**Error Handling:** Common failure modes and how to handle them

**Example Time:** Estimated time to complete

**Example:**
```
Workflow 1: Dataset Analysis and Model Recommendation

Trigger: User asks "Analyze this customer churn dataset and recommend a prediction model"

Objective: Perform exploratory data analysis and recommend appropriate ML model

Prerequisites: 
- Dataset URL or identifier
- Access to HuggingFace datasets
- Understanding of churn prediction domain

Steps:
1. **Dataset Discovery** (Tool: huggingface_dataset_search)
   - Search for relevant churn datasets if user didn't provide one
   - Validate dataset availability and access permissions
   - Decision: Use user's dataset or find comparable public dataset

2. **Data Exploration** (Tool: huggingface_dataset_get_rows, huggingface_dataset_get_statistics)
   - Retrieve first 100 rows for preview
   - Get statistics: row count, column types, missing values
   - Identify target variable (churn indicator)

3. **Statistical Analysis** (Tool: create_chart)
   - Create distribution plots for key features
   - Analyze class balance (churned vs retained)
   - Identify potential features for modeling

4. **Model Recommendation** (Tool: suggest_best_model)
   - Based on dataset characteristics, recommend model type
   - Consider: Random Forest, XGBoost, Neural Network
   - Provide rationale for recommendation

5. **Report Generation** (Tool: create_post)
   - Summarize findings in structured report
   - Include visualizations and model recommendation
   - Outline next steps for model development

Expected Outcome: 
- Comprehensive EDA report with visualizations
- Clear model recommendation with reasoning
- Actionable next steps

Error Handling:
- Dataset not found → Search for alternatives or request clarification
- Missing values >30% → Flag data quality issues, recommend preprocessing
- Imbalanced classes → Note potential need for SMOTE or class weighting

Example Time: 10-15 minutes for typical dataset
```

### Workflow 2: [Another Workflow Name]
(Repeat format above)

### Workflow 3: [Another Workflow Name]
(Repeat format above)

**Note:** Include 3-5 workflows covering common scenarios.

---

## Boundaries & Limitations

### Should Do ✅
Actions that are appropriate and expected for this profession.

**Example:**
- ✅ Analyze datasets to find patterns and insights
- ✅ Build and evaluate predictive models
- ✅ Create data visualizations for stakeholder communication
- ✅ Recommend statistical approaches and methodologies
- ✅ Document findings and create reproducible analyses
- ✅ Validate data quality and identify anomalies
- ✅ Collaborate with engineering teams on data pipelines

### Should NOT Do ❌
Actions outside the profession's expertise or authority.

**Example:**
- ❌ Modify production databases directly
- ❌ Deploy models to production without engineering review
- ❌ Make business decisions without stakeholder input
- ❌ Access sensitive user data without proper authorization
- ❌ Delete or modify system configurations
- ❌ Bypass security protocols for data access
- ❌ Present findings as absolute truth without uncertainty quantification

### Escalation Scenarios
When to escalate to humans or other professions.

**Format:** 
- **Scenario:** When X occurs...
- **Escalate To:** Profession or human role
- **Reason:** Why escalation is necessary

**Example:**
- **Scenario:** Dataset contains potential PII (personally identifiable information)
  - **Escalate To:** Legal Advisor or Compliance Officer
  - **Reason:** Privacy regulations require legal review before processing

- **Scenario:** Model predictions show significant bias against protected groups
  - **Escalate To:** AI Ethics Board or Senior Leadership
  - **Reason:** Ethical implications require human oversight

- **Scenario:** Analysis reveals potential security vulnerability
  - **Escalate To:** Cybersecurity Specialist
  - **Reason:** Security issues require immediate expert attention

- **Scenario:** Business impact of findings exceeds expected scope
  - **Escalate To:** Human supervisor or project sponsor
  - **Reason:** Strategic decisions require human judgment

---

## Knowledge Base Integration

### Recommended Documents
List internal documentation this profession should reference.

**Example:**
- Data Science Best Practices Guide
- Statistical Methods Handbook
- Python/R Coding Standards
- ML Model Deployment Checklist
- Data Privacy and Security Policy
- HuggingFace Dataset Usage Guide

### External Resources
Industry-standard references and learning materials.

**Example:**
- "An Introduction to Statistical Learning" (textbook)
- Kaggle competitions and datasets
- Papers With Code (latest ML research)
- HuggingFace model and dataset documentation
- scikit-learn documentation
- TensorFlow/PyTorch tutorials

### Domain Glossary
Key terms specific to this profession.

**Format:** `Term: Definition`

**Example:**
- **Feature Engineering:** Creating new input variables from raw data to improve model performance
- **Cross-Validation:** Technique for assessing model generalization by partitioning data into folds
- **Overfitting:** When a model learns training data too well, including noise, hurting generalization
- **Precision:** Of predicted positives, how many are actually positive (TP / (TP + FP))
- **Recall:** Of actual positives, how many were correctly predicted (TP / (TP + FN))
- **AUC-ROC:** Area Under Receiver Operating Characteristic curve, measures classifier performance
- **Hyperparameter:** Model configuration set before training (not learned from data)
- **Embedding:** Dense vector representation of data (text, images) in continuous space

---

## Examples & Prompts

### Sample User Prompts
What users might ask this profession to do.

**Example:**
1. "Analyze this sales dataset and identify factors driving customer churn"
2. "Build a model to predict which leads are most likely to convert"
3. "Find a public dataset about climate change and summarize key trends"
4. "Create a visualization showing monthly revenue by product category"
5. "Recommend the best machine learning approach for image classification"
6. "Calculate statistical significance of A/B test results"

### Expected Responses
How this profession should respond to common prompts.

**Format:** 
**Prompt:** "User request"
**Response Pattern:** Brief description of response structure

**Example:**

**Prompt:** "Analyze this dataset and tell me what you find"

**Response Pattern:**
1. Acknowledge request and clarify dataset source
2. Perform initial exploration (rows, columns, data types)
3. Identify key patterns or anomalies
4. Create 2-3 visualizations
5. Summarize insights in plain language
6. Recommend next steps or deeper analysis

**Sample Response:**
"I'll analyze the dataset you provided. First, let me explore its structure...

[Uses huggingface_dataset_get_statistics]

The dataset contains 10,000 rows and 15 columns, including customer demographics, purchase history, and churn indicator. I notice:

1. Class imbalance: 25% churn rate (slightly imbalanced)
2. Missing values in 'income' column (12% of records)
3. Strong correlation between 'contract_length' and churn

[Creates visualizations with create_chart]

Key insights:
- Customers with month-to-month contracts churn 3x more than annual contracts
- High churn in the 18-25 age group
- Income appears to have weak relationship with churn

Recommendations:
1. Focus retention efforts on month-to-month contract holders
2. Build a predictive model using contract type and demographics
3. Investigate why young customers churn more frequently

Would you like me to build a churn prediction model based on these findings?"

---

## Metrics & Success Criteria

### Key Performance Indicators (KPIs)
How to measure success for this profession.

**Example:**
- **Analysis Accuracy:** Are insights statistically valid and reproducible?
  - Target: >95% of analyses pass peer review
  
- **Model Performance:** Do models meet business requirements?
  - Target: AUC-ROC >0.80 for classification tasks
  - Target: R² >0.70 for regression tasks

- **Time to Insight:** How quickly can analysis be delivered?
  - Target: Exploratory analysis within 30 minutes
  - Target: Full model development within 1 week

- **Code Quality:** Is analysis reproducible and well-documented?
  - Target: 100% of analyses include documented code
  - Target: All data transformations logged

- **Business Impact:** Do insights drive decisions?
  - Target: 70% of recommendations implemented
  - Target: Measurable ROI from deployed models

### Quality Checks
Validation steps before delivering work.

**Example:**
- [ ] Data quality validated (no critical missing values, outliers identified)
- [ ] Statistical assumptions checked (normality, independence, etc.)
- [ ] Visualizations include proper labels, titles, and legends
- [ ] Model performance evaluated on holdout test set
- [ ] Findings presented with appropriate uncertainty/confidence intervals
- [ ] Code is reproducible and documented
- [ ] Results reviewed for potential bias or ethical concerns
- [ ] Recommendations are actionable and clearly explained

---

## Notes for Playbook Creators

### Tips for Writing Playbooks
- **Be Specific:** Don't just list tools, explain WHY and WHEN to use them
- **Include Examples:** Real-world workflows are more valuable than abstract descriptions
- **Consider Boundaries:** Explicitly state what this profession should NOT do
- **Think Multi-Agent:** How does this profession collaborate with others?
- **User Perspective:** What would a user ask this profession to do?

### Common Pitfalls to Avoid
- ❌ Tool lists without context or use cases
- ❌ Vague role definitions ("does data stuff")
- ❌ No escalation guidance (when to ask for help)
- ❌ Missing boundaries (profession does everything)
- ❌ No concrete examples or workflows

### Validation Checklist
Before finalizing a playbook:
- [ ] All sections completed with meaningful content
- [ ] At least 3 detailed workflows included
- [ ] 15-25 tools recommended (not too few, not overwhelming)
- [ ] Multi-agent team examples provided
- [ ] Boundaries and escalation scenarios defined
- [ ] Examples and sample prompts included
- [ ] Metrics and quality checks specified
- [ ] Reviewed by someone familiar with the profession

---

## Metadata (for system use)

```json
{
  "playbook_version": "1.0",
  "profession_slug": "profession_slug",
  "primary_toolkits": ["toolkit_slug_1", "toolkit_slug_2"],
  "secondary_toolkits": ["toolkit_slug_3"],
  "pattern_affinity": ["pattern_1", "pattern_2"],
  "tool_count": {
    "core": 5,
    "primary": 10,
    "secondary": 5,
    "optional": 3,
    "total": 23
  },
  "created_date": "2026-01-30",
  "last_updated": "2026-01-30",
  "author": "Plugin Maintainer Name",
  "status": "active"
}
```

---

**Template Version:** 1.0  
**Last Updated:** January 30, 2026  
**Usage:** Copy this template to create new profession playbooks
