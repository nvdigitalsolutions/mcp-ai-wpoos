# IGCSE Tutor Professions

This directory contains comprehensive tutor profession playbooks for supporting students through the IGCSE (International General Certificate of Secondary Education) programme.

## Overview

Added 6 IGCSE tutor professions to support students across Years 9-11 and core academic subjects.

## Professions Added

### Year-Level Tutors

1. **IGCSE Year 9 Tutor** (`igcse_year_9_tutor.txt`)
   - Transition support from Key Stage 3 to IGCSE
   - Subject exploration and selection guidance
   - Foundation skills building
   - Preparation for Year 10 IGCSE start
   - Subject fair and options process support

2. **IGCSE Year 10 Tutor** (`igcse_year_10_tutor.txt`)
   - First year IGCSE programme support
   - Study skills and time management development
   - Multi-subject coordination
   - Assessment preparation
   - Foundation building for Year 11

3. **IGCSE Year 11 Tutor** (`igcse_year_11_tutor.txt`)
   - Final year exam preparation
   - Intensive revision strategies
   - Past paper practice mastery
   - Coursework completion support
   - Exam stress management
   - Grade optimization techniques

### Subject-Specific Tutors

4. **IGCSE Mathematics Tutor** (`igcse_mathematics_tutor.txt`)
   - Core and Extended tier support (Grades 9-1)
   - Cambridge IGCSE Mathematics (0580/0607)
   - Diagnostic assessments and personalized plans
   - Topic-by-topic mastery (Number, Algebra, Geometry, etc.)
   - Exam technique for calculator and non-calculator papers
   - Mole calculations, equations, problem-solving

5. **IGCSE English Tutor** (`igcse_english_tutor.txt`)
   - English Language (0500/0990) and English Literature (0486/0992)
   - Reading comprehension strategies
   - Writing across genres (narrative, descriptive, argumentative, analytical)
   - Literary analysis and critical response
   - Set text mastery
   - Exam technique for unseen texts

6. **IGCSE Sciences Tutor** (`igcse_sciences_tutor.txt`)
   - Combined Science (Double Award 0653)
   - Triple Science: Biology (0610), Chemistry (0620), Physics (0625)
   - Core and Extended tier support
   - Practical skills development
   - Mathematical skills in science
   - Topic coverage across all three sciences

## Features

Each tutor profession includes:

### Content Structure
- **Role Boundaries**: Clear do's, don'ts, and escalation guidance
- **Quick Intake Questions**: 3-8 questions to understand student needs
- **Default Workflow**: Step-by-step tutoring process
- **Deliverables**: Templates, assessments, reports, plans
- **Quality Checklist**: Comprehensive standards for effective tutoring
- **Templates**: Paste-ready forms and worksheets
- **Examples**: Real-world tutoring session transcripts
- **Vocabulary**: Key terms and standards

### Assessment Tools
- Diagnostic assessments for each subject/year
- Progress tracking templates
- Mock exam analysis frameworks
- Academic status reviews
- Readiness checklists

### Planning Resources
- Personalized learning plans
- Revision timetables (daily, weekly, term-long)
- Study schedules
- Exam preparation roadmaps
- Transition planning documents

### Student Support
- Study skills development
- Time management strategies
- Exam technique guides
- Stress management approaches
- Motivation and confidence building

### Communication
- Parent update templates
- Progress reports (termly/monthly)
- Session notes
- Assessment feedback

## Usage in WP oOS

These professions integrate with the existing profession playbook system:

1. **Profession Creation**: Use the profession slug when creating an AI assistant
2. **Playbook Assembly**: System automatically assembles:
   - Global guidelines (`global.txt`)
   - Category guidelines (`categories/other.txt`)
   - Profession-specific content (e.g., `professions/igcse_mathematics_tutor.txt`)
3. **Memory Attachment**: Generated playbook attached to assistant as memory file
4. **AI Context**: Assistant has full tutoring guidance in context

## Educational Context

### IGCSE Programme
- International qualification for ages 14-16 (Years 10-11)
- Year 9 is preparation/transition year
- Equivalent to UK GCSE
- Cambridge Assessment International Education primary provider
- Recognized globally for university entrance

### Grading Systems
- **9-1 Scale** (newer): Grade 9 (highest) to Grade 1 (lowest), Grade 4 = pass
- **A*-G Scale** (traditional): Grade A* (highest) to Grade G (lowest), Grade C = pass

### Typical Subject Load
- 8-10 IGCSEs per student
- Core: English Language, Mathematics, Sciences
- Options: Languages, Humanities, Arts, Technology, etc.

### Examination Format
- Final examinations in May/June of Year 11
- Multiple papers per subject
- Some subjects include coursework/controlled assessments
- Past paper practice essential for success

## File Statistics

| Profession | File Size | Lines | Focus |
|------------|-----------|-------|-------|
| Mathematics Tutor | 27KB | 704 | Core/Extended tiers, calculations, exam technique |
| English Tutor | 22KB | 592 | Language & Literature, reading, writing, analysis |
| Sciences Tutor | 24KB | 645 | Biology, Chemistry, Physics (Combined/Triple) |
| Year 9 Tutor | 23KB | 673 | Transition, subject selection, preparation |
| Year 10 Tutor | 25KB | 668 | Foundations, study skills, multi-subject support |
| Year 11 Tutor | 27KB | 755 | Exam preparation, revision, grade optimization |
| **Total** | **~147KB** | **4,037 lines** | Complete IGCSE support Years 9-11 |

## Alignment with Existing System

These IGCSE tutors follow the established profession playbook patterns:

- ✅ Standard header format (Title, Slug, Category, Intent)
- ✅ 8-section structure (boundaries, intake, workflow, deliverables, checklist, templates, examples, vocabulary)
- ✅ Categorized as "other" (educational/tutoring)
- ✅ Compatible with playbook loader and seeder
- ✅ UTF-8 encoded text files
- ✅ Registered in `manifest.json`

## Updating Playbooks

To update IGCSE tutor content:

1. Edit the relevant `.txt` file in this directory
2. Go to **WP Admin → Settings → WP oOS → Advanced**
3. Click **"Reseed Professions"**
4. Choose **"Update"** (not "Replace")
5. Wait for completion
6. Playbooks will regenerate with new content

## Future Enhancements

Potential additions:
- Subject-specific tutors (History, Geography, Computer Science, etc.)
- A-Level tutor professions (Year 12-13)
- International Baccalaureate (IB) tutors
- Language-specific tutors (French, Spanish, Mandarin)
- Exam board variants (Edexcel, Pearson)

## Change Log

### December 2024
- Initial creation of 6 IGCSE tutor professions
- Comprehensive coverage of Years 9-11
- Core subjects: Mathematics, English, Sciences
- Aligned with Cambridge IGCSE syllabuses
- ~147KB of tutoring guidance content

## Resources

### Examination Boards
- Cambridge Assessment International Education: https://www.cambridgeinternational.org
- Pearson Edexcel International: https://qualifications.pearson.com/en/qualifications/edexcel-international-gcses.html

### Student Resources
- Save My Exams: https://www.savemyexams.co.uk
- Physics & Maths Tutor: https://www.physicsandmathstutor.com
- BBC Bitesize: https://www.bbc.co.uk/bitesize

### Professional Resources
- Teaching resources and subject-specific guides
- Past papers and mark schemes from examination boards
- IGCSE textbooks by subject

---

**Note**: These profession playbooks are designed for AI assistants to provide high-quality, contextually appropriate tutoring support. They include safeguards, escalation protocols, and ethical boundaries appropriate for educational contexts.
