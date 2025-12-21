# OpenAI Moderation API - Usage Examples

This document provides practical examples of using the OpenAI Moderation API through the `moderate_content` tool in WP oOS.

## Table of Contents

- [Basic Content Moderation](#basic-content-moderation)
- [Batch Moderation](#batch-moderation)
- [WordPress Integration Examples](#wordpress-integration-examples)
- [Automated Content Filtering](#automated-content-filtering)
- [API Response Structure](#api-response-structure)

## Basic Content Moderation

### Simple Text Moderation

```php
// Get the tool
$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'moderate_content' );

// Moderate a single text input
$result = $tool->execute(
    array(
        'input' => 'This is a friendly message about WordPress development.',
        'model' => 'omni-moderation-latest', // Optional, this is the default
    ),
    array(
        'user_id' => get_current_user_id(),
    )
);

if ( is_wp_error( $result ) ) {
    // Handle error
    echo 'Error: ' . $result->get_error_message();
} else {
    // Check if content is safe
    if ( $result['summary']['is_safe'] ) {
        echo 'Content is safe for publication.';
    } else {
        echo 'Content flagged: ' . implode( ', ', $result['summary']['categories_found'] );
    }
}
```

### Checking Specific Content Before Publishing

```php
function check_content_safety_before_publish( $content ) {
    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $content );

    if ( is_wp_error( $result ) ) {
        return array(
            'safe' => false,
            'error' => $result->get_error_message(),
        );
    }

    $is_safe = isset( $result['results'][0]['flagged'] ) ? ! $result['results'][0]['flagged'] : true;

    return array(
        'safe'       => $is_safe,
        'results'    => $result['results'],
        'categories' => isset( $result['results'][0]['categories'] ) ? $result['results'][0]['categories'] : array(),
    );
}

// Usage
$safety_check = check_content_safety_before_publish( $user_submitted_content );

if ( ! $safety_check['safe'] ) {
    wp_die( 'Content contains policy violations and cannot be published.' );
}
```

## Batch Moderation

### Moderate Multiple Comments at Once

```php
// Get all pending comments
$comments = get_comments( array(
    'status' => 'hold',
    'number' => 100,
) );

// Extract comment content
$content_to_moderate = array_map( function( $comment ) {
    return $comment->comment_content;
}, $comments );

// Batch moderate
$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'moderate_content' );
$result = $tool->execute(
    array(
        'input' => $content_to_moderate,
    ),
    array(
        'user_id' => get_current_user_id(),
    )
);

if ( ! is_wp_error( $result ) ) {
    // Process results
    foreach ( $result['results'] as $index => $moderation_result ) {
        if ( $moderation_result['flagged'] ) {
            // Mark comment as spam
            wp_spam_comment( $comments[ $index ]->comment_ID );
        } else {
            // Approve safe comments
            wp_set_comment_status( $comments[ $index ]->comment_ID, 'approve' );
        }
    }

    echo sprintf(
        'Moderated %d comments: %d flagged, %d approved',
        $result['summary']['total_items'],
        $result['summary']['flagged_items'],
        $result['summary']['total_items'] - $result['summary']['flagged_items']
    );
}
```

### Moderate All Posts in a Category

```php
function moderate_posts_in_category( $category_id ) {
    $posts = get_posts( array(
        'category'    => $category_id,
        'numberposts' => 50,
    ) );

    $content_array = array();
    foreach ( $posts as $post ) {
        // Combine title and content for moderation
        $content_array[] = $post->post_title . "\n\n" . $post->post_content;
    }

    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $content_array );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    $flagged_posts = array();

    foreach ( $result['results'] as $index => $moderation_result ) {
        if ( $moderation_result['flagged'] ) {
            $flagged_posts[] = array(
                'post_id'    => $posts[ $index ]->ID,
                'post_title' => $posts[ $index ]->post_title,
                'categories' => $moderation_result['categories'],
                'scores'     => $moderation_result['scores'],
            );
        }
    }

    return array(
        'total_moderated' => count( $posts ),
        'flagged_count'   => count( $flagged_posts ),
        'flagged_posts'   => $flagged_posts,
    );
}
```

## WordPress Integration Examples

### Automatic Comment Moderation

```php
/**
 * Automatically moderate comments before they're saved.
 */
add_filter( 'pre_comment_approved', 'auto_moderate_comment_content', 10, 2 );

function auto_moderate_comment_content( $approved, $commentdata ) {
    // Skip moderation for trusted users
    if ( user_can( $commentdata['user_id'], 'moderate_comments' ) ) {
        return $approved;
    }

    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $commentdata['comment_content'] );

    if ( is_wp_error( $result ) ) {
        // Log error but don't block comment
        error_log( 'Moderation API error: ' . $result->get_error_message() );
        return $approved;
    }

    // Check if flagged
    if ( isset( $result['results'][0]['flagged'] ) && $result['results'][0]['flagged'] ) {
        // Hold for manual review
        return 0;
    }

    return $approved;
}
```

### Contact Form Validation

```php
/**
 * Moderate contact form submissions.
 */
add_action( 'wpcf7_before_send_mail', 'moderate_contact_form_content' );

function moderate_contact_form_content( $contact_form ) {
    $submission = WPCF7_Submission::get_instance();

    if ( ! $submission ) {
        return;
    }

    $data    = $submission->get_posted_data();
    $message = isset( $data['your-message'] ) ? $data['your-message'] : '';

    if ( empty( $message ) ) {
        return;
    }

    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $message );

    if ( is_wp_error( $result ) ) {
        return;
    }

    if ( isset( $result['results'][0]['flagged'] ) && $result['results'][0]['flagged'] ) {
        // Get flagged categories
        $categories = isset( $result['results'][0]['categories'] ) ? $result['results'][0]['categories'] : array();

        // Block the submission
        $submission->set_status( 'validation_failed' );
        $submission->set_response(
            sprintf(
                'Your message contains content that violates our policies (%s). Please revise and try again.',
                implode( ', ', array_values( $categories ) )
            )
        );
    }
}
```

### User Registration Content Check

```php
/**
 * Moderate user bio during registration.
 */
add_action( 'user_profile_update_errors', 'moderate_user_bio', 10, 3 );

function moderate_user_bio( &$errors, $update, &$user ) {
    if ( empty( $user->description ) ) {
        return;
    }

    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $user->description );

    if ( is_wp_error( $result ) ) {
        // Log but don't block
        error_log( 'Moderation API error: ' . $result->get_error_message() );
        return;
    }

    if ( isset( $result['results'][0]['flagged'] ) && $result['results'][0]['flagged'] ) {
        $categories = array();
        if ( isset( $result['results'][0]['categories'] ) ) {
            foreach ( $result['results'][0]['categories'] as $category ) {
                $categories[] = ucfirst( str_replace( '/', ' / ', $category ) );
            }
        }

        $errors->add(
            'bio_content_violation',
            sprintf(
                'Your bio contains inappropriate content (%s). Please revise.',
                implode( ', ', $categories )
            )
        );
    }
}
```

## Automated Content Filtering

### Daily Content Audit

```php
/**
 * Schedule daily content audit via WP-Cron.
 */
add_action( 'wp', 'schedule_daily_content_audit' );

function schedule_daily_content_audit() {
    if ( ! wp_next_scheduled( 'run_daily_content_audit' ) ) {
        wp_schedule_event( time(), 'daily', 'run_daily_content_audit' );
    }
}

add_action( 'run_daily_content_audit', 'perform_content_audit' );

function perform_content_audit() {
    // Get posts published in the last 24 hours
    $recent_posts = get_posts( array(
        'numberposts' => 100,
        'date_query'  => array(
            array(
                'after' => '24 hours ago',
            ),
        ),
    ) );

    if ( empty( $recent_posts ) ) {
        return;
    }

    $content_to_check = array_map( function( $post ) {
        return $post->post_content;
    }, $recent_posts );

    $client = new WP_MCP_AI_OpenAI_Client();
    $result = $client->moderate_content( $content_to_check );

    if ( is_wp_error( $result ) ) {
        error_log( 'Content audit failed: ' . $result->get_error_message() );
        return;
    }

    $flagged_count = 0;

    foreach ( $result['results'] as $index => $moderation_result ) {
        if ( $moderation_result['flagged'] ) {
            ++$flagged_count;

            // Send email to admin
            wp_mail(
                get_option( 'admin_email' ),
                'Content Policy Violation Detected',
                sprintf(
                    "Post ID %d has been flagged for policy violations:\n\nCategories: %s\n\nPlease review: %s",
                    $recent_posts[ $index ]->ID,
                    implode( ', ', $moderation_result['categories'] ),
                    get_edit_post_link( $recent_posts[ $index ]->ID, 'raw' )
                )
            );
        }
    }

    // Log audit results
    error_log( sprintf( 'Content audit complete: %d posts checked, %d flagged', count( $recent_posts ), $flagged_count ) );
}
```

## API Response Structure

### Sample Response - Safe Content

```json
{
    "moderation_id": "modr-8xrb2jB1q0vQyxkL",
    "model": "omni-moderation-latest",
    "results_count": 1,
    "results": [
        {
            "index": 0,
            "flagged": false,
            "categories": [],
            "scores": {}
        }
    ],
    "summary": {
        "total_items": 1,
        "flagged_items": 0,
        "flagged_percentage": 0,
        "is_safe": true,
        "categories_found": [],
        "category_counts": {},
        "recommendation": "Content appears safe for publication."
    }
}
```

### Sample Response - Flagged Content

```json
{
    "moderation_id": "modr-970d409ef3bef3b70c",
    "model": "omni-moderation-latest",
    "results_count": 1,
    "results": [
        {
            "index": 0,
            "flagged": true,
            "categories": [
                "violence",
                "harassment/threatening"
            ],
            "scores": {
                "violence": 0.86,
                "violence/graphic": 0.37,
                "harassment": 0.0011,
                "harassment/threatening": 0.0022
            }
        }
    ],
    "summary": {
        "total_items": 1,
        "flagged_items": 1,
        "flagged_percentage": 100,
        "is_safe": false,
        "categories_found": [
            "violence",
            "harassment/threatening"
        ],
        "category_counts": {
            "violence": 1,
            "harassment/threatening": 1
        },
        "recommendation": "1 item was flagged and requires review before publication."
    }
}
```

## Violation Categories

The Moderation API checks for the following 14 categories:

| Category | Description |
|----------|-------------|
| `sexual` | Sexual content |
| `sexual/minors` | Sexual content involving minors |
| `harassment` | Harassment or bullying |
| `harassment/threatening` | Threatening harassment |
| `hate` | Hate speech |
| `hate/threatening` | Hateful threatening content |
| `illicit` | Illicit activities |
| `illicit/violent` | Violent illicit activities |
| `self-harm` | Self-harm content |
| `self-harm/intent` | Intent to self-harm |
| `self-harm/instructions` | Instructions for self-harm |
| `violence` | Violent content |
| `violence/graphic` | Graphic violent content |

## Best Practices

1. **Use Batch Processing**: When moderating multiple items, use batch mode to reduce API calls
2. **Cache Results**: Consider caching moderation results to avoid re-checking identical content
3. **Handle Errors Gracefully**: Always have fallback logic for API failures
4. **Set Thresholds**: Use confidence scores to fine-tune your moderation sensitivity
5. **Human Review**: For borderline cases (scores 0.3-0.7), consider human review
6. **Log Everything**: Keep logs of moderation decisions for compliance and debugging
7. **Inform Users**: Provide clear feedback when content is flagged
8. **Regular Audits**: Run periodic audits on existing content

## Pricing

The OpenAI Moderation API is **free to use** with no token costs or rate limits beyond OpenAI's standard API rate limits.

## Additional Resources

- [OpenAI Moderation API Documentation](https://platform.openai.com/docs/guides/moderation)
- [OpenAI API Reference - Moderations](https://platform.openai.com/docs/api-reference/moderations)
- [Content Safety Best Practices](https://platform.openai.com/docs/guides/safety-best-practices)
