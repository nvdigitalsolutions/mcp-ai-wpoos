# Quiz Tools Documentation

## Overview

The Quiz Tools provide a complete assessment system for tutors and educators to create, manage, and grade quizzes. This toolkit includes 7 new tools that enable full quiz lifecycle management.

## Features

- **Multiple Question Types**: Support for multiple choice, true/false, and short answer questions
- **Timer Support**: Optional time limits for quiz completion
- **Flexible Grading**: Manual grading with per-question feedback
- **Result Tracking**: Comprehensive results and submission management
- **Permission Control**: Role-based access for tutors and students

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

### 2. get_quiz

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

### 3. list_quizzes

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

### 4. submit_quiz_answer

Submits answers for a quiz.

**Slug**: `submit_quiz_answer`

**Parameters**:
- `quiz_id` (integer, required): The quiz ID
- `answers` (array, required): Array of answer objects
  - `question_index` (integer, required): Zero-based question index
  - `answer` (string, required): The submitted answer
- `user_id` (integer, optional): User ID submitting (defaults to current user)

**Returns**:
- `submission_id`: ID of the created submission
- `quiz_id`: Quiz ID
- `status`: Submission status (pending)

**Note**: Each user can only submit once per quiz.

### 5. grade_quiz

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

### 6. get_quiz_submissions

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

### 7. get_quiz_results

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

## Custom Post Types

The quiz system uses two custom post types:

### mcp_ai_quiz
Stores quiz definitions with metadata:
- `_mcp_ai_quiz_description`: Quiz description
- `_mcp_ai_quiz_time_limit`: Time limit in minutes
- `_mcp_ai_quiz_questions`: Array of question objects
- `_mcp_ai_quiz_total_points`: Total possible points
- `_mcp_ai_quiz_passing_score`: Passing percentage

### mcp_ai_submission
Stores user submissions with metadata:
- `_mcp_ai_submission_quiz_id`: Associated quiz ID
- `_mcp_ai_submission_answers`: Array of user answers
- `_mcp_ai_submission_status`: `pending` or `graded`
- `_mcp_ai_submission_grades`: Array of grades (when graded)
- `_mcp_ai_submission_earned_points`: Total points earned
- `_mcp_ai_submission_percentage`: Percentage score
- `_mcp_ai_submission_passed`: Pass/fail boolean
- `_mcp_ai_submission_graded_by`: User ID who graded
- `_mcp_ai_submission_graded_at`: Grading timestamp

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
