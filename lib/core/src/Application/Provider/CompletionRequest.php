<?php

/**
 * Build the conversation a model is asked to answer.
 *
 * One place, because the shape is identical whether or not there is an image:
 * an optional system prompt, then a user turn holding the instruction and — for
 * a vision call — the image segment the provider clients already convert to
 * their own dialect.
 *
 * Keeping it here rather than per tool is what lets a text tool and a vision tool
 * share a single call path: the only difference between them is whether
 * `$dataUri` is null.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Provider;

final class CompletionRequest
{
    /**
     * @param string      $prompt  The instruction.
     * @param string|null $dataUri An image, when the model is being asked about one.
     * @param string      $system  A system prompt, when the tool has one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function messages(string $prompt, ?string $dataUri = null, string $system = ''): array
    {
        $messages = array();

        if ('' !== $system) {
            $messages[] = array(
                'role'    => 'system',
                'content' => $system,
            );
        }

        $content = array(
            array(
                'type' => 'text',
                'text' => $prompt,
            ),
        );

        if (null !== $dataUri) {
            $content[] = array(
                'type'      => 'image_url',
                'image_url' => array( 'url' => $dataUri ),
            );
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $content,
        );

        return $messages;
    }
}
