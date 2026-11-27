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
     * @param string                    $prompt  The instruction.
     * @param string|array<int, string>|null $dataUri An image, or several, when the model is being
     *                                               asked about them. Several images are one user turn
     *                                               carrying several `image_url` parts, which is how a
     *                                               coverage question ("are all four sides of this car
     *                                               here?") is asked at all.
     * @param string                    $system  A system prompt, when the tool has one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function messages(string $prompt, string|array|null $dataUri = null, string $system = ''): array
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

        foreach (self::uris($dataUri) as $uri) {
            $content[] = array(
                'type'      => 'image_url',
                'image_url' => array( 'url' => $uri ),
            );
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $content,
        );

        return $messages;
    }

    /**
     * Normalise the image argument to a list of non-empty data URIs.
     *
     * A bare string stays a single image, so every existing caller is unaffected;
     * an array is one user turn with several images, which is the shape a
     * multi-view question needs.
     *
     * @param string|array<int, string>|null $dataUri One, several, or none.
     *
     * @return array<int, string>
     */
    private static function uris(string|array|null $dataUri): array
    {
        if (null === $dataUri) {
            return array();
        }

        $uris = is_array($dataUri) ? $dataUri : array($dataUri);
        $list = array();

        foreach ($uris as $uri) {
            if (is_string($uri) && '' !== trim($uri)) {
                $list[] = $uri;
            }
        }

        return $list;
    }
}
