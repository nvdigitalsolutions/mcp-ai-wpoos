/**
 * Quiz Questions Management JavaScript
 *
 * Handles dynamic adding, removing, and reordering of quiz questions.
 *
 * @package WP_MCP_AI
 */

(function( $ ) {
	'use strict';

	let questionIndex = 0;

	/**
	 * Initialize quiz questions functionality.
	 */
	function init() {
		const $container = $( '#wp-mcp-ai-quiz-questions-container' );
		const $addButton = $( '#wp-mcp-ai-add-question' );

		// Set initial index based on existing questions.
		questionIndex = $container.find( '.quiz-question-row' ).length;

		// Make questions sortable.
		$container.sortable({
			handle: '.question-handle',
			placeholder: 'quiz-question-placeholder',
			update: updateQuestionNumbers
		});

		// Add question button.
		$addButton.on( 'click', addQuestion );

		// Remove question buttons (delegated).
		$container.on( 'click', '.remove-question', function() {
			removeQuestion( $( this ).closest( '.quiz-question-row' ) );
		});

		// Question type change (delegated).
		$container.on( 'change', '.question-type', function() {
			handleTypeChange( $( this ).closest( '.quiz-question-row' ) );
		});

		// Add option button (delegated).
		$container.on( 'click', '.add-option', function() {
			addOption( $( this ).closest( '.quiz-question-row' ) );
		});

		// Remove option button (delegated).
		$container.on( 'click', '.remove-option', function() {
			removeOption( $( this ).closest( '.option-row' ) );
		});

		// Initial question numbers.
		updateQuestionNumbers();
	}

	/**
	 * Add a new question.
	 */
	function addQuestion() {
		const template = $( '#wp-mcp-ai-question-template' ).html();
		const $newQuestion = $( template.replace( /{INDEX}/g, questionIndex ) );

		$( '#wp-mcp-ai-quiz-questions-container' ).append( $newQuestion );
		questionIndex++;

		// Initialize the type display for the new question.
		handleTypeChange( $newQuestion );
		updateQuestionNumbers();

		// Scroll to new question.
		$( 'html, body' ).animate({
			scrollTop: $newQuestion.offset().top - 100
		}, 500 );
	}

	/**
	 * Remove a question.
	 *
	 * @param {jQuery} $question Question row element.
	 */
	function removeQuestion( $question ) {
		if ( confirm( 'Are you sure you want to remove this question?' ) ) {
			$question.fadeOut( 300, function() {
				$( this ).remove();
				updateQuestionNumbers();
			});
		}
	}

	/**
	 * Handle question type change.
	 *
	 * @param {jQuery} $question Question row element.
	 */
	function handleTypeChange( $question ) {
		const type = $question.find( '.question-type' ).val();
		const $options = $question.find( '.multiple-choice-options' );

		if ( 'multiple_choice' === type ) {
			$options.show();
		} else {
			$options.hide();
		}
	}

	/**
	 * Add an option to a multiple choice question.
	 *
	 * @param {jQuery} $question Question row element.
	 */
	function addOption( $question ) {
		const $optionsList = $question.find( '.options-list' );
		const index = $question.data( 'index' );
		const $newOption = $( '<div class="option-row">' +
			'<input type="text" name="wp_mcp_ai_questions[' + index + '][options][]" value="" placeholder="Option text" class="widefat" />' +
			'<button type="button" class="button-link remove-option" title="Remove option"><span class="dashicons dashicons-no-alt"></span></button>' +
			'</div>' );

		$optionsList.append( $newOption );
		$newOption.find( 'input' ).focus();
	}

	/**
	 * Remove an option from a multiple choice question.
	 *
	 * @param {jQuery} $option Option row element.
	 */
	function removeOption( $option ) {
		const $optionsList = $option.closest( '.options-list' );

		// Don't allow removing if it's the last option.
		if ( $optionsList.find( '.option-row' ).length > 1 ) {
			$option.fadeOut( 200, function() {
				$( this ).remove();
			});
		} else {
			alert( 'A multiple choice question must have at least one option.' );
		}
	}

	/**
	 * Update question numbers after sorting or adding/removing.
	 */
	function updateQuestionNumbers() {
		$( '#wp-mcp-ai-quiz-questions-container .quiz-question-row' ).each( function( index ) {
			$( this ).find( '.question-number' ).text( 'Question ' + ( index + 1 ) );
		});
	}

	// Initialize on document ready.
	$( document ).ready( init );

})( jQuery );
