# Quiz Management Toolkit

> Quiz creation, grading, analytics, and submission management.

## Purpose

Tools for creating and managing quizzes, handling student submissions, automated grading, and producing analytics reports.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Create Quiz | `create_quiz` | Create a new quiz with questions and answer keys |
| Delete Quiz | `delete_quiz` | Remove a quiz and its submissions |
| Get Quiz Analytics | `get_quiz_analytics` | Aggregate statistics across quiz submissions |
| Get Quiz Results | `get_quiz_results` | Fetch results for a specific quiz |
| Get Quiz Submissions | `get_quiz_submissions` | List all submissions for a quiz |
| Get Quiz | `get_quiz` | Fetch a quiz by ID |
| Grade Quiz | `grade_quiz` | Auto-grade a quiz submission against answer key |
| List Quizzes | `list_quizzes` | List all quizzes with filtering |
| Research Quiz Topic | `research_quiz_topic` | AI-assisted topic research for quiz creation |
| Submit Quiz Answer | `submit_quiz_answer` | Record a student's answer submission |
| Update Quiz | `update_quiz` | Modify an existing quiz |

## Dependencies

- WordPress 6.0+
- JetEngine (optional, for CCT-backed submission storage)

## Registration

Loaded by `quiz-management-init.php` in `addons/pro/includes/`. Gated on `enable_quiz_management` setting.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Quiz CPT: `addons/pro/includes/class-wp-mcp-ai-quiz-cpt.php`](../../class-wp-mcp-ai-quiz-cpt.php)
