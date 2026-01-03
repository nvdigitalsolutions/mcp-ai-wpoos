# Quiz Tools Documentation

## Overview

The Quiz Tools provide a complete assessment system for tutors and educators to create, manage, grade quizzes, and visualize analytics. This toolkit includes 9 tools that enable full quiz lifecycle management with Chart.js visualizations.

**⚠️ Important: This is a Full Version feature that must be enabled before use.**

**Requirements:**
- **Full Version** mode (not Base Version)
- Feature must be enabled in settings

To enable the quiz system:
1. Ensure you are NOT in Base Version mode (do not define `WP_MCP_AI_BASE_VERSION` constant)
2. Go to **WP Admin → Settings → NV oOS → Tools & Features**
3. Navigate to the **Features** subtab
4. Check **"Enable Quiz System"**
5. Save changes

Once enabled, the system will:
- Register quiz and submission custom post types (CPT)
- Load all 9 quiz management tools
- Enable automatic JetEngine CCT synchronization (when available)

**Storage Architecture:**
- **CPT (Custom Post Type)**: Primary storage for quizzes and submissions
- **CCT (Custom Content Type)**: Optional JetEngine synchronization for REST API access
- When JetEngine is active (Full Version), quiz data automatically syncs to CCT endpoints

## Features

- **Multiple Question Types**: Support for multiple choice, true/false, and short answer questions
- **Timer Support**: Optional time limits for quiz completion
- **Flexible Grading**: Manual grading with per-question feedback
- **Result Tracking**: Comprehensive results and submission management
- **Permission Control**: Role-based access for tutors and students
- **Quiz Editing**: Update existing quizzes with new questions or settings
- **Analytics & Visualization**: Chart.js powered analytics with 5 chart types
- **JetEngine Integration**: Automatic CCT synchronization for advanced queries and REST API access

## Storage & REST API Access

### Custom Post Types (CPT) - Always Available
- `mcp_ai_quiz` - Quiz definitions stored as WordPress posts
- `mcp_ai_submission` - User submissions stored as WordPress posts
- Available in both Base and Full versions
- Primary data source for all quiz tools

### JetEngine CCT Sync (Full Version Only)
When JetEngine is active, quiz data is automatically synchronized to:
- `quizzes` CCT - Available at `/wp-json/jet-cct/quizzes`
- `quiz_submissions` CCT - Available at `/wp-json/jet-cct/quiz_submissions`

**New CCT Fields (v1.1):**
- `started_at` - ISO 8601 timestamp when quiz was started
- `completion_time` - Time taken to complete quiz in minutes

This enables:
- Advanced filtering and queries via JetEngine REST API
- Frontend queries in JetEngine listings
- Integration with JetEngine forms and workflows

## Tools

### 1. create_quiz

Creates a new quiz with questions.

**Slug**: `create_quiz`

**Parameters**:
- `title` (string, required): Title of the quiz
- `description` (string, optional): Quiz instructions or description
- `time_limit` (integer, default: 0): Time limit in minutes (0 = no limit)
- `questions` (array, required): Array of question objects
  - `question` (string, required): The question text
  - `type` (string, required): Question type: `multiple_choice`, `true_false`, or `short_answer`
  - `options` (array, required for multiple_choice): Answer options
  - `correct_answer` (string, optional): The correct answer for grading reference
  - `points` (integer, default: 1): Points awarded for correct answer
- `passing_score` (integer, default: 70): Minimum percentage to pass (0-100)

**Returns**:
- `quiz_id`: ID of the created quiz
- `title`: Quiz title
- `question_count`: Number of questions
- `total_points`: Total possible points

**Example**:
```json
{
  "title": "JavaScript Fundamentals",
  "description": "Test your knowledge of JavaScript basics",
  "time_limit": 30,
  "passing_score": 75,
  "questions": [
    {
      "question": "What is the output of typeof []?",
      "type": "multiple_choice",
      "options": ["array", "object", "undefined"],
      "correct_answer": "object",
      "points": 2
    },
    {
      "question": "JavaScript is case-sensitive",
      "type": "true_false",
      "correct_answer": "true",
      "points": 1
    }
  ]
}
```

### 2. update_quiz

Updates an existing quiz with new questions or settings.

**Slug**: `update_quiz`

**Parameters**:
- `quiz_id` (integer, required): ID of the quiz to update
- `title` (string, optional): New title for the quiz
- `description` (string, optional): New description or instructions
- `time_limit` (integer, optional): New time limit in minutes (0 = no limit)
- `questions` (array, optional): New array of questions (replaces all existing questions)
  - Same structure as create_quiz questions
- `passing_score` (integer, optional): New minimum percentage to pass (0-100)

**Returns**:
- `quiz_id`: Quiz ID
- `title`: Updated quiz title
- `description`: Updated description
- `time_limit`: Updated time limit
- `question_count`: Number of questions
- `total_points`: Total possible points
- `passing_score`: Passing percentage
- `updated_fields`: Array of fields that were updated
- `updated_at`: Timestamp of update

**Permissions**: Only the quiz author or users with `edit_others_posts` capability can update quizzes.

**Example**:
```json
{
  "quiz_id": 123,
  "title": "JavaScript Fundamentals - Updated",
  "time_limit": 45,
  "questions": [
    {
      "question": "What is the output of typeof null?",
      "type": "multiple_choice",
      "options": ["null", "object", "undefined"],
      "correct_answer": "object",
      "points": 3
    }
  ]
}
```

**Notes**:
- At least one field must be provided to update
- Updating questions replaces all existing questions
- CCT synchronization is automatically triggered on update

### 3. get_quiz

Retrieves details of a specific quiz.

**Slug**: `get_quiz`

**Parameters**:
- `quiz_id` (integer, required): The quiz ID
- `include_answers` (boolean, default: false): Include correct answers (requires edit permission)

**Returns**:
- `quiz_id`: Quiz ID
- `title`: Quiz title
- `description`: Quiz description
- `time_limit`: Time limit in minutes
- `questions`: Array of question objects
- `total_points`: Total possible points
- `passing_score`: Passing percentage

### 4. list_quizzes

Lists available quizzes with pagination.

**Slug**: `list_quizzes`

**Parameters**:
- `author_id` (integer, optional): Filter by quiz author
- `per_page` (integer, default: 10): Results per page
- `page` (integer, default: 1): Page number

**Returns**:
- `quizzes`: Array of quiz objects
- `total`: Total number of quizzes
- `page`: Current page
- `total_pages`: Total pages

### 5. submit_quiz_answer

Submits answers for a quiz with optional time tracking.

**Slug**: `submit_quiz_answer`

**Parameters**:
- `quiz_id` (integer, required): The quiz ID
- `answers` (array, required): Array of answer objects
  - `question_index` (integer, required): Zero-based question index
  - `answer` (string, required): The submitted answer
- `user_id` (integer, optional): User ID submitting (defaults to current user)
- `started_at` (string, optional): ISO 8601 timestamp when quiz was started. Required for quizzes with time limits.

**Returns**:
- `submission_id`: ID of the created submission
- `quiz_id`: Quiz ID
- `status`: Submission status (pending)
- `time_limit`: Time limit in minutes (if quiz has one)
- `started_at`: When quiz was started (if provided)
- `completion_time_minutes`: Time taken to complete (if started_at provided)

**Notes**: 
- Each user can only submit once per quiz.
- For quizzes with time limits, `started_at` is required and submission will be rejected if time limit is exceeded.
- A 1-minute grace period is allowed for submission processing.

### 6. grade_quiz

Grades a quiz submission.

**Slug**: `grade_quiz`

**Parameters**:
- `submission_id` (integer, required): The submission ID to grade
- `grades` (array, required): Array of grade objects
  - `question_index` (integer, required): Zero-based question index
  - `points_earned` (number, required): Points earned for this question
  - `feedback` (string, optional): Feedback for this question
- `overall_feedback` (string, optional): Overall submission feedback

**Returns**:
- `earned_points`: Total points earned
- `total_points`: Total possible points
- `percentage`: Percentage score
- `passed`: Whether the student passed

**Permissions**: Only the quiz author or users with `edit_others_posts` capability can grade.

### 7. get_quiz_submissions

Retrieves all submissions for a quiz.

**Slug**: `get_quiz_submissions`

**Parameters**:
- `quiz_id` (integer, required): The quiz ID
- `status` (string, default: 'all'): Filter by status: `pending`, `graded`, or `all`
- `per_page` (integer, default: 10): Results per page
- `page` (integer, default: 1): Page number

**Returns**:
- `submissions`: Array of submission objects with student info
- `total`: Total submissions
- `page`: Current page
- `total_pages`: Total pages

**Permissions**: Only the quiz author or users with `edit_others_posts` capability can view.

### 8. get_quiz_results

Retrieves detailed results for a graded submission.

**Slug**: `get_quiz_results`

**Parameters**:
- `submission_id` (integer, required): The submission ID

**Returns**:
- `submission_id`: Submission ID
- `quiz_id`: Quiz ID
- `student_id`: Student user ID
- `status`: Submission status
- `detailed_results`: Array of question results with answers and grades
- `earned_points`: Total points earned (if graded)
- `percentage`: Percentage score (if graded)
- `passed`: Pass/fail status (if graded)
- `overall_feedback`: Tutor feedback (if provided)

**Permissions**: Students can view their own results, quiz authors can view all results.

### 9. get_quiz_analytics

Generates Chart.js visualization data for quiz analytics.

**Slug**: `get_quiz_analytics`

**Parameters**:
- `quiz_id` (integer, required): ID of the quiz to analyze
- `chart_types` (array, optional): Types of charts to generate. Options:
  - `score_distribution` - Bar chart showing distribution of scores
  - `pass_fail_rate` - Doughnut chart showing pass/fail percentages
  - `completion_times` - Bar chart showing time taken to complete
  - `question_performance` - Bar chart showing success rate per question
  - `submission_timeline` - Line chart showing submissions over time

**Returns**:
- `quiz_id`: Quiz ID
- `quiz_title`: Quiz title
- `total_submissions`: Number of graded submissions analyzed
- `passing_score`: Passing score percentage
- `charts`: Object containing requested Chart.js configurations
  - Each chart includes `type`, `data`, and `options` for Chart.js
- `stats`: Summary statistics
  - `average_score`: Average percentage score
  - `median_score`: Median percentage score
  - `pass_rate`: Percentage of students who passed
  - `average_completion`: Average completion time in minutes

**Chart.js Integration**:
```javascript
// Example: Render score distribution chart
const ctx = document.getElementById('scoreChart').getContext('2d');
const chartConfig = result.charts.score_distribution;
new Chart(ctx, chartConfig);
```

**Example Response**:
```json
{
  "quiz_id": 123,
  "total_submissions": 25,
  "charts": {
    "score_distribution": {
      "type": "bar",
      "data": {
        "labels": ["0-10%", "11-20%", ...],
        "datasets": [{
          "label": "Number of Students",
          "data": [0, 1, 2, 5, 8, 6, 3, 0, 0, 0],
          "backgroundColor": "rgba(54, 162, 235, 0.6)"
        }]
      },
      "options": { ... }
    },
    "pass_fail_rate": {
      "type": "doughnut",
      "data": {
        "labels": ["Passed", "Failed"],
        "datasets": [{
          "data": [18, 7],
          "backgroundColor": [
            "rgba(75, 192, 192, 0.6)",
            "rgba(255, 99, 132, 0.6)"
          ]
        }]
      }
    }
  },
  "stats": {
    "average_score": 74.5,
    "median_score": 76.0,
    "pass_rate": 72.0,
    "average_completion": 12.3
  }
}
```

**Permissions**: Only quiz author or users with `edit_others_posts` capability.

**Notes**:
- Requires at least one graded submission
- All Chart.js configurations are ready to use
- Statistics exclude pending submissions

## Workflow Example

### 1. Tutor Creates Quiz
```
Tutor uses create_quiz to create a new quiz with questions, time limit, and passing score.
```

### 2. Student Takes Quiz
```
Student uses list_quizzes to find available quizzes
Student uses get_quiz to view questions (without answers)
Student uses submit_quiz_answer to submit their responses
```

### 3. Tutor Grades Quiz
```
Tutor uses get_quiz_submissions to see all submissions
Tutor uses grade_quiz to score each question and provide feedback
```

### 4. Student Views Results
```
Student uses get_quiz_results to see their score, answers, and feedback
```

### 5. Tutor Analyzes Performance
```
Tutor uses get_quiz_analytics to generate Chart.js visualizations
Renders charts showing:
- Score distribution across all students
- Pass/fail rates
- Time spent on quiz
- Performance by question
- Submission timeline
```

## Custom Post Types

The quiz system uses two custom post types with optional JetEngine CCT synchronization:

### mcp_ai_quiz
Stores quiz definitions with metadata:
- `_mcp_ai_quiz_description`: Quiz description
- `_mcp_ai_quiz_time_limit`: Time limit in minutes
- `_mcp_ai_quiz_questions`: Array of question objects
- `_mcp_ai_quiz_total_points`: Total possible points
- `_mcp_ai_quiz_passing_score`: Passing percentage
- `_wp_mcp_ai_quiz_cct_item_id`: Link to CCT item (when JetEngine active)

**CCT Fields (when synced):**
- `title`, `description`, `author_id`, `time_limit`, `question_count`, `total_points`, `passing_score`, `cpt_post_id`

### mcp_ai_submission
Stores user submissions with metadata:
- `_mcp_ai_submission_quiz_id`: Associated quiz ID
- `_mcp_ai_submission_answers`: Array of user answers
- `_mcp_ai_submission_status`: `pending` or `graded`
- `_mcp_ai_submission_total_points`: Total possible points (copied from quiz)
- `_mcp_ai_submission_grades`: Array of grades (when graded)
- `_mcp_ai_submission_earned_points`: Total points earned
- `_mcp_ai_submission_percentage`: Percentage score
- `_mcp_ai_submission_passed`: Pass/fail boolean
- `_mcp_ai_submission_graded_by`: User ID who graded
- `_mcp_ai_submission_graded_at`: Grading timestamp
- `_mcp_ai_submission_submitted_at`: Submission timestamp
- `_mcp_ai_submission_started_at`: ISO 8601 timestamp when quiz started (if tracked)
- `_mcp_ai_submission_completion_time`: Time taken in minutes (if tracked)
- `_wp_mcp_ai_submission_cct_item_id`: Link to CCT item (when JetEngine active)

**CCT Fields (when synced):**
- `quiz_id`, `student_id`, `status`, `earned_points`, `total_points`, `percentage`, `passed`, `graded_by`, `cpt_post_id`

## JetEngine REST API Endpoints

When JetEngine is active (Full Version), the following REST endpoints are available:

### Quizzes
- **GET** `/wp-json/jet-cct/quizzes` - List all quizzes
- **GET** `/wp-json/jet-cct/quizzes/{id}` - Get specific quiz
- **POST** `/wp-json/jet-cct/quizzes` - Create quiz (syncs to CPT)
- **PUT** `/wp-json/jet-cct/quizzes/{id}` - Update quiz (syncs to CPT)
- **DELETE** `/wp-json/jet-cct/quizzes/{id}` - Delete quiz (removes from CPT)

### Submissions
- **GET** `/wp-json/jet-cct/quiz_submissions` - List all submissions
- **GET** `/wp-json/jet-cct/quiz_submissions/{id}` - Get specific submission
- Query parameters: `quiz_id`, `student_id`, `status` for filtering

**Note**: Changes made via CCT endpoints do NOT automatically sync back to CPT. Use the tool endpoints for full functionality.

## Security

- Quiz creation requires `edit_posts` capability (tutors, editors, admins)
- Quiz viewing requires `read` capability (all authenticated users)
- Grading requires being the quiz author or having `edit_others_posts` capability
- Students can only view their own results
- Duplicate submissions are prevented

## Use Cases

1. **Educational Assessments**: Create quizzes for online courses
2. **Knowledge Checks**: Quick comprehension tests
3. **Certification Exams**: Timed assessments with passing scores
4. **Self-Assessment**: Students can test their understanding
5. **Competency Evaluation**: Track learning progress over time
