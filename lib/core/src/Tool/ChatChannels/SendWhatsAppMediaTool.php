<?php

/**
 * Send a WhatsApp media message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_WhatsApp_Media` (Proposal 164 section
 * 164.103). One `POST /{phone_number_id}/messages` whose payload key is the
 * media type itself.
 *
 * The media is a `link` (a public https URL Meta fetches) or an `id` (from a
 * previous upload). Nothing is uploaded here, so no multipart body is needed.
 *
 * ## Corrections
 *
 * 1. **Exactly one of `media_url` and `media_id` is accepted.** WordPress
 *    preferred the id when both were given, silently ignoring the URL.
 * 2. **The link must be https.** WordPress ran `esc_url_raw()`, which accepts
 *    `http://`, while the schema says https and Meta requires a public https
 *    URL.
 * 3. **A caption or filename the type does not support is refused.** WordPress
 *    dropped a caption for audio and sticker, and a filename for anything but a
 *    document, so the caller believed it had been sent.
 * 4. **An over-long caption is refused**, not truncated at 1024 characters.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendWhatsAppMediaTool extends WhatsAppTool
{
    /**
     * The types and whether each takes a caption.
     *
     * @var array<string, bool>
     */
    private const TYPES = array(
        'image'    => true,
        'video'    => true,
        'audio'    => false,
        'document' => true,
        'sticker'  => false,
    );

    private const MAX_CAPTION_LENGTH = 1024;

    public function getSlug(): string
    {
        return 'send_whatsapp_media';
    }

    public function getName(): string
    {
        return 'Send WhatsApp Media';
    }

    public function getDescription(): string
    {
        return 'Send an image, video, audio, document or sticker through the WhatsApp Cloud API, by public https link or by an id from a previous upload. Returns the WhatsApp message id.';
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
                    'description' => 'Media type to send.',
                    'enum'        => array('image', 'video', 'audio', 'document', 'sticker'),
                ),
                'media_url'       => array(
                    'type'        => 'string',
                    'description' => 'Public https URL Meta fetches. Give this or media_id, not both.',
                ),
                'media_id'        => array(
                    'type'        => 'string',
                    'description' => 'Id of a media object uploaded to WhatsApp. Give this or media_url, not both.',
                    'minLength'   => 1,
                ),
                'caption'         => array(
                    'type'        => 'string',
                    'description' => 'Caption for image, video or document, at most 1024 characters.',
                    'maxLength'   => 1024,
                ),
                'filename'        => array(
                    'type'        => 'string',
                    'description' => 'Filename shown for a document.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('access_token', 'phone_number_id', 'to', 'type'),
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

        if (! array_key_exists($type, self::TYPES)) {
            return $this->errors->validationFailed(
                'The media type must be image, video, audio, document or sticker.',
                array('type' => array('Unsupported media type.'))
            );
        }

        $url = $this->textArg($arguments, 'media_url');
        $id  = $this->textArg($arguments, 'media_id');

        if ('' !== $url && '' !== $id) {
            return $this->errors->validationFailed(
                'Give either media_url or media_id, not both.',
                array('media_url' => array('Both media_url and media_id were given.'))
            );
        }

        if ('' === $url && '' === $id) {
            return $this->errors->validationFailed(
                'Either media_url or media_id is required.',
                array('media_url' => array('Neither media_url nor media_id was given.'))
            );
        }

        if ('' !== $url) {
            $parts  = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

            if ('https' !== $scheme || '' === $host) {
                return $this->errors->validationFailed(
                    'A media URL must be an absolute https URL.',
                    array('media_url' => array('The URL is not an absolute https URL.'))
                );
            }

            $media = array('link' => $url);
        } else {
            $media = array('id' => $id);
        }

        $caption = $this->textArg($arguments, 'caption');

        if ('' !== $caption) {
            if (! self::TYPES[$type]) {
                return $this->errors->validationFailed(
                    'This media type does not take a caption.',
                    array('caption' => array('A caption is not supported for this type.'))
                );
            }

            if (\mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
                return $this->errors->validationFailed(
                    'The caption may not exceed 1024 characters.',
                    array('caption' => array('The caption is too long.'))
                );
            }

            $media['caption'] = $caption;
        }

        $filename = $this->textArg($arguments, 'filename');

        if ('' !== $filename) {
            if ('document' !== $type) {
                return $this->errors->validationFailed(
                    'Only a document takes a filename.',
                    array('filename' => array('A filename is only supported for documents.'))
                );
            }

            $media['filename'] = $filename;
        }

        try {
            $response = $this->apiPost(
                $token,
                '/' . $phoneId . '/messages',
                array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => $recipient['type'],
                    'to'                => $recipient['to'],
                    'type'              => $type,
                    $type               => $media,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $sent = $this->sent($response);

        if ('' === $sent['message_id']) {
            return $this->errors->create(
                'whatsapp_invalid_response',
                'WhatsApp accepted the media but returned no message id.'
            );
        }

        return $this->success(
            ucfirst($type) . ' sent to ' . $recipient['to'] . '.',
            array(
                'message_id' => $sent['message_id'],
                'wa_id'      => $sent['wa_id'],
                'to'         => $recipient['to'],
                'type'       => $type,
            )
        );
    }
}
