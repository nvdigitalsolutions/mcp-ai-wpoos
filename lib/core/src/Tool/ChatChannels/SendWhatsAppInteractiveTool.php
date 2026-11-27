<?php

/**
 * Send an interactive WhatsApp message: reply buttons or a list.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_WhatsApp_Interactive` (Proposal 164 section
 * 164.103). One `POST /{phone_number_id}/messages` of type `interactive`.
 *
 * ## Corrections
 *
 * 1. **An over-long value is refused, not truncated.** WordPress cut button
 *    titles to 20 characters, row titles to 24, descriptions to 72, footers and
 *    header text to 60, and section titles to 24, with byte-based `substr()`.
 *    Every one of those is a silent rewrite of what the caller asked for, and
 *    the byte-based cut can split a multi-byte character.
 * 2. **A malformed entry is refused, not skipped.** WordPress `continue`d past
 *    a button or row without an id or title, so the message arrived with fewer
 *    options than the caller wrote and no signal.
 * 3. **The list row cap is total, not per section.** Meta allows at most ten
 *    rows across every section; WordPress allowed ten per section and up to ten
 *    sections, which Meta rejects.
 * 4. **An unknown header type is refused.** WordPress copied `type` through and
 *    only attached a payload for text, image, video and document, so an
 *    unrecognised type went to Meta with no payload.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendWhatsAppInteractiveTool extends WhatsAppTool
{
    private const MAX_BODY_LENGTH = 1024;
    private const MAX_FOOTER_LENGTH = 60;
    private const MAX_HEADER_TEXT_LENGTH = 60;
    private const MAX_BUTTONS = 3;
    private const MAX_BUTTON_TITLE_LENGTH = 20;
    private const MAX_SECTIONS = 10;
    private const MAX_ROWS = 10;
    private const MAX_ROW_TITLE_LENGTH = 24;
    private const MAX_ROW_DESCRIPTION_LENGTH = 72;
    private const MAX_SECTION_TITLE_LENGTH = 24;

    public function getSlug(): string
    {
        return 'send_whatsapp_interactive';
    }

    public function getName(): string
    {
        return 'Send WhatsApp Interactive';
    }

    public function getDescription(): string
    {
        return 'Send an interactive WhatsApp message with up to three reply buttons, or a list message with a button that opens up to ten rows across its sections. Returns the WhatsApp message id.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token'    => array(
                    'type'        => 'string',
                    'description' => 'WhatsApp Cloud API access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'phone_number_id' => array(
                    'type'        => 'string',
                    'description' => 'Numeric id of the sending phone number.',
                    'pattern'     => '^[0-9]+$',
                ),
                'to'              => array(
                    'type'        => 'string',
                    'description' => 'E.164 phone number, or a group id when recipient_type is group.',
                    'minLength'   => 1,
                ),
                'recipient_type'  => array(
                    'type'        => 'string',
                    'description' => 'individual for a phone number, group for a group id. Defaults to individual.',
                    'enum'        => array('individual', 'group'),
                    'default'     => 'individual',
                ),
                'type'            => array(
                    'type'        => 'string',
                    'description' => 'button for reply buttons, list for a list message.',
                    'enum'        => array('button', 'list'),
                ),
                'header'          => array(
                    'type'        => 'object',
                    'description' => 'Optional header: a text of at most 60 characters, or an image, video or document object holding a link or an id.',
                ),
                'body'            => array(
                    'type'        => 'string',
                    'description' => 'Body text, at most 1024 characters.',
                    'minLength'   => 1,
                    'maxLength'   => 1024,
                ),
                'footer'          => array(
                    'type'        => 'string',
                    'description' => 'Optional footer text, at most 60 characters.',
                    'maxLength'   => 60,
                ),
                'buttons'         => array(
                    'type'        => 'array',
                    'description' => 'Reply buttons for type button: one to three objects of the shape {type: reply, reply: {id, title}}, title at most 20 characters.',
                    'items'       => array('type' => 'object'),
                ),
                'button_text'     => array(
                    'type'        => 'string',
                    'description' => 'Text on the button that opens the list, for type list. At most 20 characters.',
                    'maxLength'   => 20,
                ),
                'sections'        => array(
                    'type'        => 'array',
                    'description' => 'List sections for type list. Up to ten sections and ten rows in total; a row title is at most 24 characters and its description at most 72.',
                    'items'       => array('type' => 'object'),
                ),
            ),
            'required'             => array('access_token', 'phone_number_id', 'to', 'type', 'body'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->accessToken($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $phoneId = $this->phoneNumberId($arguments);

        if (! is_string($phoneId)) {
            return $phoneId;
        }

        $recipient = $this->recipient($arguments);

        if (! is_array($recipient)) {
            return $recipient;
        }

        $type = $this->textArg($arguments, 'type');

        if (! in_array($type, array('button', 'list'), true)) {
            return $this->errors->validationFailed(
                'The interactive type must be button or list.',
                array('type' => array('Unsupported interactive type.'))
            );
        }

        $body = $this->textArg($arguments, 'body');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'A message body is required.',
                array('body' => array('A message body is required.'))
            );
        }

        if (\mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return $this->errors->validationFailed(
                'The message body may not exceed 1024 characters.',
                array('body' => array('The body is too long.'))
            );
        }

        $footer = $this->textArg($arguments, 'footer');

        if (\mb_strlen($footer) > self::MAX_FOOTER_LENGTH) {
            return $this->errors->validationFailed(
                'The footer may not exceed 60 characters.',
                array('footer' => array('The footer is too long.'))
            );
        }

        $header = $this->header($arguments);

        if ($this->errors->isError($header)) {
            return $header;
        }

        $action = 'button' === $type
            ? $this->buttonAction($arguments)
            : $this->listAction($arguments);

        if ($this->errors->isError($action)) {
            return $action;
        }

        $interactive = array(
            'type'   => $type,
            'body'   => array('text' => $body),
            'action' => $action,
        );

        if (array() !== $header) {
            $interactive['header'] = $header;
        }

        if ('' !== $footer) {
            $interactive['footer'] = array('text' => $footer);
        }

        try {
            $response = $this->apiPost(
                $token,
                '/' . $phoneId . '/messages',
                array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => $recipient['type'],
                    'to'                => $recipient['to'],
                    'type'              => 'interactive',
                    'interactive'       => $interactive,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $sent = $this->sent($response);

        if ('' === $sent['message_id']) {
            return $this->errors->create(
                'whatsapp_invalid_response',
                'WhatsApp accepted the message but returned no message id.'
            );
        }

        return $this->success(
            'Interactive ' . $type . ' message sent to ' . $recipient['to'] . '.',
            array(
                'message_id' => $sent['message_id'],
                'wa_id'      => $sent['wa_id'],
                'to'         => $recipient['to'],
                'type'       => 'interactive',
                'variant'    => $type,
            )
        );
    }

    /**
     * The header object, or an empty array when none was given.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed The header, or the validation error.
     */
    private function header(array $arguments): mixed
    {
        $header = $this->arrayParam($arguments, 'header');

        if (array() === $header) {
            return array();
        }

        $type = $this->text($header['type'] ?? '');

        if (! in_array($type, array('text', 'image', 'video', 'document'), true)) {
            return $this->errors->validationFailed(
                'A header must be text, image, video or document.',
                array('header' => array('Unsupported header type.'))
            );
        }

        if ('text' === $type) {
            $text = $this->text($header['text'] ?? '');

            if ('' === $text) {
                return $this->errors->validationFailed(
                    'A text header requires its text.',
                    array('header' => array('A text header requires its text.'))
                );
            }

            if (\mb_strlen($text) > self::MAX_HEADER_TEXT_LENGTH) {
                return $this->errors->validationFailed(
                    'The header text may not exceed 60 characters.',
                    array('header' => array('The header text is too long.'))
                );
            }

            return array('type' => 'text', 'text' => $text);
        }

        $media = is_array($header[$type] ?? null) ? $header[$type] : array();
        $media = $this->media($media, 'header.' . $type);

        if ($this->errors->isError($media)) {
            return $media;
        }

        return array('type' => $type, $type => $media);
    }

    /**
     * A media object with exactly one of link and id.
     *
     * @param array<string, mixed> $value Raw media object.
     *
     * @return array<string, string>|mixed The media, or the validation error.
     */
    private function media(array $value, string $field): mixed
    {
        $link = $this->text($value['link'] ?? '');
        $id   = $this->text($value['id'] ?? '');

        if ('' !== $link && '' !== $id) {
            return $this->errors->validationFailed(
                'A media object takes either a link or an id, not both.',
                array($field => array('Both link and id were given.'))
            );
        }

        if ('' === $link && '' === $id) {
            return $this->errors->validationFailed(
                'A media object requires a link or an id.',
                array($field => array('Neither link nor id was given.'))
            );
        }

        if ('' !== $link) {
            $parts  = parse_url($link);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

            if ('https' !== $scheme || '' === $host) {
                return $this->errors->validationFailed(
                    'A media link must be an absolute https URL.',
                    array($field => array('The link is not an absolute https URL.'))
                );
            }

            return array('link' => $link);
        }

        return array('id' => $id);
    }

    /**
     * The reply-button action.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed The action, or the validation error.
     */
    private function buttonAction(array $arguments): mixed
    {
        $buttons = $this->arrayParam($arguments, 'buttons');

        if (array() === $buttons) {
            return $this->errors->validationFailed(
                'At least one reply button is required.',
                array('buttons' => array('At least one button is required.'))
            );
        }

        if (count($buttons) > self::MAX_BUTTONS) {
            return $this->errors->validationFailed(
                'At most three reply buttons are allowed.',
                array('buttons' => array('Too many buttons.'))
            );
        }

        $clean = array();

        foreach ($buttons as $index => $button) {
            $reply = is_array($button) && is_array($button['reply'] ?? null) ? $button['reply'] : array();

            $id    = $this->text($reply['id'] ?? '');
            $title = $this->text($reply['title'] ?? '');

            if ('' === $id || '' === $title) {
                return $this->errors->validationFailed(
                    'Every button needs a reply id and title.',
                    array('buttons' => array(sprintf('Button %d is missing its id or title.', (int) $index + 1)))
                );
            }

            if (\mb_strlen($title) > self::MAX_BUTTON_TITLE_LENGTH) {
                return $this->errors->validationFailed(
                    'A button title may not exceed 20 characters.',
                    array('buttons' => array(sprintf('Button %d has a title that is too long.', (int) $index + 1)))
                );
            }

            $clean[] = array(
                'type'  => 'reply',
                'reply' => array(
                    'id'    => $id,
                    'title' => $title,
                ),
            );
        }

        return array('buttons' => $clean);
    }

    /**
     * The list action.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed The action, or the validation error.
     */
    private function listAction(array $arguments): mixed
    {
        $button = $this->textArg($arguments, 'button_text');

        if ('' === $button) {
            return $this->errors->validationFailed(
                'A list message requires its button text.',
                array('button_text' => array('The button text is required.'))
            );
        }

        if (\mb_strlen($button) > self::MAX_BUTTON_TITLE_LENGTH) {
            return $this->errors->validationFailed(
                'The list button text may not exceed 20 characters.',
                array('button_text' => array('The button text is too long.'))
            );
        }

        $sections = $this->arrayParam($arguments, 'sections');

        if (array() === $sections) {
            return $this->errors->validationFailed(
                'A list message requires at least one section.',
                array('sections' => array('At least one section is required.'))
            );
        }

        if (count($sections) > self::MAX_SECTIONS) {
            return $this->errors->validationFailed(
                'At most ten sections are allowed.',
                array('sections' => array('Too many sections.'))
            );
        }

        $clean = array();
        $total = 0;

        foreach ($sections as $index => $section) {
            $position = (int) $index + 1;
            $rows     = is_array($section) && is_array($section['rows'] ?? null) ? $section['rows'] : array();

            if (array() === $rows) {
                return $this->errors->validationFailed(
                    'Every list section needs at least one row.',
                    array('sections' => array(sprintf('Section %d has no rows.', $position)))
                );
            }

            $cleanRows = array();

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    return $this->errors->validationFailed(
                        'Every list row must be an object.',
                        array('sections' => array(sprintf('Section %d has a row that is not an object.', $position)))
                    );
                }

                $id    = $this->text($row['id'] ?? '');
                $title = $this->text($row['title'] ?? '');

                if ('' === $id || '' === $title) {
                    return $this->errors->validationFailed(
                        'Every list row needs an id and a title.',
                        array('sections' => array(sprintf('Section %d has a row without an id or title.', $position)))
                    );
                }

                if (\mb_strlen($title) > self::MAX_ROW_TITLE_LENGTH) {
                    return $this->errors->validationFailed(
                        'A row title may not exceed 24 characters.',
                        array('sections' => array(sprintf('A row in section %d has a title that is too long.', $position)))
                    );
                }

                $cleanRow = array(
                    'id'    => $id,
                    'title' => $title,
                );

                $description = $this->text($row['description'] ?? '');

                if ('' !== $description) {
                    if (\mb_strlen($description) > self::MAX_ROW_DESCRIPTION_LENGTH) {
                        return $this->errors->validationFailed(
                            'A row description may not exceed 72 characters.',
                            array('sections' => array(sprintf('A row in section %d has a description that is too long.', $position)))
                        );
                    }

                    $cleanRow['description'] = $description;
                }

                $cleanRows[] = $cleanRow;
                ++$total;
            }

            if ($total > self::MAX_ROWS) {
                return $this->errors->validationFailed(
                    'A list message may hold at most ten rows across every section.',
                    array('sections' => array('There are more than ten rows in total.'))
                );
            }

            $cleanSection = array('rows' => $cleanRows);
            $title        = $this->text(is_array($section) ? ($section['title'] ?? '') : '');

            if ('' !== $title) {
                if (\mb_strlen($title) > self::MAX_SECTION_TITLE_LENGTH) {
                    return $this->errors->validationFailed(
                        'A section title may not exceed 24 characters.',
                        array('sections' => array(sprintf('Section %d has a title that is too long.', $position)))
                    );
                }

                $cleanSection['title'] = $title;
            }

            $clean[] = $cleanSection;
        }

        if (array() === $clean) {
            return $this->errors->validationFailed(
                'A list message requires at least one section with a row.',
                array('sections' => array('No usable section was given.'))
            );
        }

        return array(
            'button'   => $button,
            'sections' => $clean,
        );
    }
}
