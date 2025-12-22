# IGCSE Teams Structure Documentation

## Overview

The IGCSE teams have been restructured to provide **modular, extensive, and comprehensive** coverage of the Cambridge IGCSE curriculum. The new structure ensures:

1. ✅ **100% Profession Utilization** - All 13 IGCSE tutor professions are used
2. ✅ **Modular Design** - Teams are organized by subject area and specialization
3. ✅ **Full Curriculum Coverage** - All major IGCSE subjects are covered
4. ✅ **Flexible Deployment** - Teams can be used individually or in combination

---

## Team Structure

### 1. IGCSE Mathematics Team
**Slug:** `igcse_mathematics_team`  
**Specialization:** Mathematics (Core & Extended)

**Members (1):**
- IGCSE Mathematics Tutor

**Coverage:**
- Cambridge IGCSE Mathematics (0580/0607)
- Core tier (grades 5-1)
- Extended tier (grades 9-4)
- Exam techniques and problem-solving strategies

**Use Case:** Dedicated mathematics tutoring for students needing focused math support.

---

### 2. IGCSE Science Tutoring Team
**Slug:** `igcse_science_tutoring_team`  
**Specialization:** All Sciences

**Members (4):**
- IGCSE Sciences Tutor (general, for Combined/Double Award)
- IGCSE Biology Tutor
- IGCSE Chemistry Tutor
- IGCSE Physics Tutor

**Coverage:**
- Combined Science (0653) - Double Award
- Biology (0610)
- Chemistry (0620)
- Physics (0625)
- Practical skills and scientific investigation
- Core and Extended tiers

**Use Case:** Comprehensive science tutoring covering all three sciences or Combined Science students.

---

### 3. IGCSE Humanities Tutoring Team
**Slug:** `igcse_humanities_tutoring_team`  
**Specialization:** History, Geography, English Literature

**Members (3):**
- IGCSE History Tutor
- IGCSE Geography Tutor
- IGCSE English Tutor

**Coverage:**
- History (0470/0977)
- Geography (0460/0976)
- English Literature
- Critical thinking and essay writing
- Source analysis and exam techniques

**Use Case:** Humanities subjects requiring analytical and writing skills.

---

### 4. IGCSE Languages & Technology Team
**Slug:** `igcse_languages_technology_team`  
**Specialization:** English Language, Computer Science, Business Studies

**Members (3):**
- IGCSE English Tutor
- IGCSE Computer Science Tutor
- IGCSE Business Studies Tutor

**Coverage:**
- English Language
- Computer Science (0478/0984)
- Business Studies (0450/0986)
- Language skills
- Programming fundamentals
- Business concepts and practical applications

**Use Case:** Modern skills-focused subjects combining language, technology, and business.

---

### 5. IGCSE Year-Level Tutoring Team
**Slug:** `igcse_year_level_tutoring_team`  
**Specialization:** Year 9, 10, 11 Support

**Members (3):**
- IGCSE Year 9 Tutor
- IGCSE Year 10 Tutor
- IGCSE Year 11 Tutor

**Coverage:**
- Age-appropriate support across all subjects
- Study skills development
- Year-specific exam preparation
- Curriculum requirements for each year level

**Use Case:** Students needing general support tailored to their year level rather than subject-specific tutoring.

---

### 6. IGCSE Academic Support Team
**Slug:** `igcse_academic_support_team`  
**Specialization:** Comprehensive Multi-Subject Support

**Members (5):**
- IGCSE Mathematics Tutor
- IGCSE Sciences Tutor
- IGCSE English Tutor
- IGCSE Computer Science Tutor
- IGCSE Business Studies Tutor

**Coverage:**
- Broad subject coverage
- Multi-subject tutoring needs
- General academic support
- Core IGCSE subjects

**Use Case:** Students needing support across multiple subjects or general academic tutoring.

---

## Subject Coverage Matrix

| Subject Area | Specialized Team | Also Available In |
|-------------|------------------|-------------------|
| **Mathematics** | IGCSE Mathematics Team | IGCSE Academic Support Team |
| **Combined Science** | IGCSE Science Tutoring Team | IGCSE Academic Support Team |
| **Biology** | IGCSE Science Tutoring Team | - |
| **Chemistry** | IGCSE Science Tutoring Team | - |
| **Physics** | IGCSE Science Tutoring Team | - |
| **English** | IGCSE Humanities Team<br>IGCSE Languages & Technology Team | IGCSE Academic Support Team |
| **History** | IGCSE Humanities Team | - |
| **Geography** | IGCSE Humanities Team | - |
| **Computer Science** | IGCSE Languages & Technology Team | IGCSE Academic Support Team |
| **Business Studies** | IGCSE Languages & Technology Team | IGCSE Academic Support Team |
| **Year 9 Support** | IGCSE Year-Level Team | - |
| **Year 10 Support** | IGCSE Year-Level Team | - |
| **Year 11 Support** | IGCSE Year-Level Team | - |

---

## Modularity & Extensibility

### Design Principles

1. **Single Responsibility**: Each team has a clear focus (subject area or year level)
2. **Reusability**: Common professions (e.g., English tutor) appear in multiple teams
3. **Scalability**: Easy to add new subject-specific teams as needed
4. **Flexibility**: Teams can be used individually or combined based on student needs

### Adding New IGCSE Subjects

To add support for additional IGCSE subjects (e.g., Art & Design, Music, Languages):

1. Create profession playbook in `includes/knowledge-base/profession-playbooks/professions/`
2. Add profession to `manifest.json`
3. Either:
   - Add to existing team if related (e.g., Art → Humanities Team)
   - Create new specialized team in `education-extended-teams.json`

---

## Provider Configuration

All IGCSE teams use:
- **Provider:** OpenAI
- **Model:** GPT-4
- **Temperature:** 0.4 (balanced between creativity and accuracy)

The temperature setting of 0.4 provides:
- ✅ Accurate subject matter explanations
- ✅ Consistent exam technique guidance
- ✅ Appropriate creativity for problem-solving approaches
- ✅ Reliable curriculum alignment

---

## Testing & Validation

The IGCSE teams structure includes comprehensive test coverage:

- **JSON Validation**: Structure and syntax
- **Profession References**: All members reference valid professions
- **Coverage Testing**: 100% utilization of IGCSE professions
- **Load Testing**: All teams load without errors
- **Integration Testing**: Teams work correctly in the WordPress plugin

Test file: `tests/test-enhanced-team-loading.php`

---

## Cambridge IGCSE Curriculum Alignment

All team descriptions reference official Cambridge IGCSE syllabus codes:

- Mathematics: 0580/0607
- Combined Science: 0653
- Biology: 0610
- Chemistry: 0620
- Physics: 0625
- History: 0470/0977
- Geography: 0460/0976
- Computer Science: 0478/0984
- Business Studies: 0450/0986

This ensures alignment with Cambridge Assessment International Education standards.

---

## Usage Examples

### Example 1: Student Needs Math Help
**Recommended Team:** IGCSE Mathematics Team
- Focused, specialized mathematics tutoring
- Covers both Core and Extended tiers
- Exam technique and problem-solving strategies

### Example 2: Student Taking Triple Science
**Recommended Team:** IGCSE Science Tutoring Team
- Has individual biology, chemistry, and physics tutors
- Covers all practical and theoretical requirements
- Supports both Core and Extended tiers

### Example 3: Year 10 Student Struggling Across Subjects
**Recommended Team:** IGCSE Year-Level Tutoring Team or IGCSE Academic Support Team
- Year 10 tutor provides age-appropriate support
- Academic Support Team offers multi-subject coverage
- Can combine with specialized teams as needed

### Example 4: Student Preparing for Humanities Exams
**Recommended Team:** IGCSE Humanities Tutoring Team
- History, geography, and English literature support
- Essay writing and critical thinking skills
- Source analysis and exam techniques

---

## Summary

The restructured IGCSE teams provide:

✅ **Complete Coverage**: All 13 IGCSE professions utilized  
✅ **Modular Architecture**: 6 specialized teams with clear focus  
✅ **Subject Flexibility**: Students can get subject-specific or general support  
✅ **Curriculum Alignment**: References official Cambridge syllabus codes  
✅ **Scalable Design**: Easy to extend with new subjects or specializations  
✅ **Quality Assurance**: Comprehensive test coverage validates structure  

This structure ensures that IGCSE students receive appropriate, specialized support aligned with Cambridge IGCSE curriculum requirements.
