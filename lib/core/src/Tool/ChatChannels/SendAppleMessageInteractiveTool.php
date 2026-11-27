<?php

/**
 * Send an interactive Apple Messages for Business message.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive` (Proposal 164
 * section 164.108). Four widget types over one POST to the caller's MSP
 * endpoint: `list_picker`, `time_picker`, `rich_link` and `authenticate`.
 *
 * See `AppleTool` for the platform model and the shared corrections. The
 * corrections specific to this tool are all of the same kind — WordPress
 * **skipped or truncated** what it could not accept, so a caller's widget
 * silently lost content:
 *
 * 1. **An over-full list picker is refused, not sliced.** WordPress
 *    `array_slice()`d each section to ten items and dropped the rest.
 * 2. **A malformed section or item is refused, not skipped.** WordPress
 *    `continue`d past a section with no items and past an item with no
 *    identifier or title, so a picker could arrive with fewer choices than the
 *    caller built.
 * 3. **`multipleSelection` is read as a boolean.** WordPress cast with
 *    `(bool)`, which turns the string `"false"` into true — the one setting a
 *    caller is most likely to send as a string.
 * 4. **A time slot's `startTime` must be an ISO 8601 instant.** WordPress
 *    accepted any string, so a date the customer could not be shown was sent.
 * 5. **Coordinates are range-checked.** Latitude outside ±90 and longitude
 *    outside ±180 are refused, as is a non-positive radius.
 * 6. **A `data:` image must be a base64 image.** WordPress forwarded any string
 *    beginning `data:image/`, including a plain-text payload.
 *
 * ## The envelope
 *
 * `interactiveType` plus `interactive` is this family's convention, not Apple's
 * wire format, which nests the payload under
 * `interactiveData.data.<camelCaseType>` and refers to images by an identifier
 * uploaded separately. A gateway that speaks the native format must translate.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Support\Json;

class SendAppleMessageInteractiveTool extends AppleTool
{
    public function getSlug(): string
    {
        return 'send_apple_message_interactive';
    }

    public function getName(): string
    {
        return 'Send Apple Interactive Message';
    }

    public function getDescription(): string
    {
        return 'Sends an interactive Apple Messages for Business message: a list picker, a time picker, a rich link card, or an authentication request. The endpoint and token are your provider\'s, and the JSON envelope must be one your provider accepts.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'msp_api_url'             => array(
                    'type'        => 'string',
                    'description' => 'Your provider\'s public https endpoint for interactive messages.',
                ),
                'api_key'                 => array(
                    'type'        => 'string',
                    'description' => 'Bearer token issued by your provider.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'business_id'             => array(
                    'type'        => 'string',
                    'description' => 'Apple Messages for Business id issued during registration.',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                ),
                'conversation_id'         => array(
                    'type'        => 'string',
                    'description' => 'Conversation to continue. Supply this or recipient_id.',
                ),
                'recipient_id'            => array(
                    'type'        => 'string',
                    'description' => 'Opaque customer identifier. Supply this or conversation_id.',
                ),
                'interactive_type'        => array(
                    'type'        => 'string',
                    'description' => 'Which widget to send.',
                    'enum'        => self::INTERACTIVE_TYPES,
                ),
                'body_text'               => array(
                    'type'        => 'string',
                    'description' => 'Text shown above the widget, up to 2,000 characters.',
                    'minLength'   => 1,
                    'maxLength'   => self::MAX_MESSAGE_LENGTH,
                ),
                'list_picker_sections'    => array(
                    'type'        => 'array',
                    'description' => 'For list_picker: at most 10 sections of at most 10 items each.',
                    'items'       => array('type' => 'object'),
                ),
                'time_picker_event'       => array(
                    'type'        => 'object',
                    'description' => 'For time_picker: the event whose slots the customer picks from.',
                ),
                'rich_link_url'           => array(
                    'type'        => 'string',
                    'description' => 'For rich_link: the destination, a public https URL.',
                ),
                'rich_link_title'         => array(
                    'type'        => 'string',
                    'description' => 'For rich_link: the card title.',
                ),
                'rich_link_image_url'     => array(
                    'type'        => 'string',
                    'description' => 'For rich_link: a public https image URL for the card.',
                ),
                'authenticate_request_id' => array(
                    'type'        => 'string',
                    'description' => 'For authenticate: the request identifier responses are correlated with.',
                ),
                'locale'                  => array(
                    'type'        => 'string',
                    'description' => 'BCP 47 locale of the message. Defaults to en-US.',
                    'default'     => self::DEFAULT_LOCALE,
                ),
            ),
            'required'             => array('msp_api_url', 'api_key', 'business_id', 'interactive_type', 'body_text'),
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

        $url = $this->gatewayUrl($arguments, 'msp_api_url', 'provider endpoint');

        if (! is_string($url)) {
            return $url;
        }

        $token = $this->gatewayKey($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $business = $this->businessId($arguments);

        if (! is_string($business)) {
            return $business;
        }

        $type = $this->textArg($arguments, 'interactive_type');

        if (! in_array($type, self::INTERACTIVE_TYPES, true)) {
            return $this->errors->validationFailed(
                'interactive_type must be one of: ' . implode(', ', self::INTERACTIVE_TYPES) . '.',
                array('interactive_type' => array('Unsupported type.'))
            );
        }

        $bodyText = $this->appleMessage($arguments, 'body_text');

        if (! is_string($bodyText)) {
            return $bodyText;
        }

        $interactive = $this->build($type, $arguments);

        if ($this->errors->isError($interactive) || ! is_array($interactive)) {
            return $interactive;
        }

        $addressing = $this->conversation($arguments);

        if ($this->errors->isError($addressing) || ! is_array($addressing)) {
            return $addressing;
        }

        $locale = $this->locale($arguments);

        if (! is_string($locale)) {
            return $locale;
        }

        $payload = array_merge(
            array(
                'businessId'      => $business,
                'type'            => 'interactive',
                'interactiveType' => $type,
                'body'            => array('text' => $bodyText),
                'interactive'     => $interactive,
                'locale'          => $locale,
            ),
            $addressing
        );

        $body = Json::encodeOr($payload, '');

        if ('' === $body) {
            return $this->errors->validationFailed(
                'The interactive message could not be encoded as JSON.',
                array('interactive' => array('Not encodable.'))
            );
        }

        try {
            $response = $this->gatewayPost($url, $token, $body);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Apple interactive message sent.',
            array(
                'business_id'      => $business,
                'type'             => 'interactive',
                'interactive_type' => $type,
                'conversation_id'  => $addressing['conversationId'] ?? '',
                'recipient_id'     => $addressing['recipientId'] ?? '',
                'message_id'       => $this->stringValue($response['messageId'] ?? $response['id'] ?? null),
                'response'         => $response,
            )
        );
    }

    /**
     * The payload for one widget type.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function build(string $type, array $arguments): mixed
    {
        if ('list_picker' === $type) {
            return $this->listPicker($arguments);
        }

        if ('time_picker' === $type) {
            return $this->timePicker($arguments);
        }

        if ('rich_link' === $type) {
            return $this->richLink($arguments);
        }

        return $this->authenticate($arguments);
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function listPicker(array $arguments): mixed
    {
        $raw = $arguments['list_picker_sections'] ?? null;

        if (! is_array($raw) || array() === $raw) {
            return $this->errors->validationFailed(
                'list_picker_sections is required for a list picker.',
                array('list_picker_sections' => array('Required.'))
            );
        }

        if (self::MAX_PICKER_SECTIONS < count($raw)) {
            return $this->errors->validationFailed(
                'A list picker holds at most ' . self::MAX_PICKER_SECTIONS . ' sections.',
                array('list_picker_sections' => array('Too many sections.'))
            );
        }

        $sections = array();

        foreach ($raw as $index => $section) {
            $built = $this->section($section, (int) $index);

            if ($this->errors->isError($built) || ! is_array($built)) {
                return $built;
            }

            $sections[] = $built;
        }

        return array('sections' => $sections);
    }

    /**
     * One list picker section.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function section(mixed $section, int $index): mixed
    {
        if (! is_array($section)) {
            return $this->sectionRefused($index, 'is not an object');
        }

        if (! is_array($section['items'] ?? null) || array() === $section['items']) {
            return $this->sectionRefused($index, 'has no items');
        }

        if (self::MAX_PICKER_ITEMS < count($section['items'])) {
            return $this->sectionRefused($index, 'has more than ' . self::MAX_PICKER_ITEMS . ' items');
        }

        $built = array();

        $title = $this->opaque($section, 'title');

        if ('' !== $title) {
            $built['title'] = $title;
        }

        if (array_key_exists('multipleSelection', $section)) {
            $built['multipleSelection'] = $this->boolParam($section, 'multipleSelection');
        }

        $items = array();

        foreach ($section['items'] as $item) {
            $entry = $this->item($item, $index);

            if ($this->errors->isError($entry) || ! is_array($entry)) {
                return $entry;
            }

            $items[] = $entry;
        }

        $built['items'] = $items;

        return $built;
    }

    /**
     * One list picker item.
     *
     * @return array<string, mixed>|mixed
     */
    private function item(mixed $item, int $section): mixed
    {
        if (! is_array($item)) {
            return $this->itemRefused($section, 'is not an object');
        }

        $identifier = $this->opaque($item, 'identifier');
        $title      = $this->opaque($item, 'title');

        if ('' === $identifier || '' === $title) {
            return $this->itemRefused($section, 'needs an identifier and a title');
        }

        $built = array(
            'identifier' => $identifier,
            'title'      => $title,
        );

        $subtitle = $this->opaque($item, 'subtitle');

        if ('' !== $subtitle) {
            $built['subtitle'] = $subtitle;
        }

        if (array_key_exists('imageData', $item)) {
            $image = $this->imageRef($item['imageData'], $section);

            if ($this->errors->isError($image) || ! is_string($image)) {
                return $image;
            }

            $built['imageData'] = $image;
        }

        $style = $this->textArg($item, 'style');

        if ('' !== $style) {
            if (! in_array($style, array('default', 'emphasize'), true)) {
                return $this->itemRefused($section, 'has an unknown style');
            }

            $built['style'] = $style;
        }

        return $built;
    }

    /**
     * An inline image reference: a public https URL, or a base64 `data:` image.
     *
     * @return string|mixed
     */
    private function imageRef(mixed $value, int $section): mixed
    {
        if (! is_string($value) || '' === trim($value)) {
            return $this->itemRefused($section, 'has an imageData that is not a string');
        }

        $image = trim($value);

        if (1 === preg_match('#^data:image/[a-z0-9.+-]{1,32};base64,[A-Za-z0-9+/]+={0,2}$#i', $image)) {
            return $image;
        }

        $url = $this->publicUrl(array('image' => $image), 'image', 'image URL');

        if (! is_string($url)) {
            return $this->errors->validationFailed(
                'An item image must be a public https URL or a base64 data: image.',
                array('list_picker_sections' => array('Invalid imageData.'))
            );
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function timePicker(array $arguments): mixed
    {
        $event = $arguments['time_picker_event'] ?? null;

        if (! is_array($event)) {
            return $this->errors->validationFailed(
                'time_picker_event is required for a time picker.',
                array('time_picker_event' => array('Required.'))
            );
        }

        $identifier = $this->opaque($event, 'identifier');
        $title      = $this->opaque($event, 'title');

        if ('' === $identifier || '' === $title) {
            return $this->errors->validationFailed(
                'time_picker_event needs an identifier and a title.',
                array('time_picker_event' => array('Both are required.'))
            );
        }

        $built = array(
            'identifier' => $identifier,
            'title'      => $title,
        );

        $location = $this->location($event['location'] ?? null);

        if ($this->errors->isError($location)) {
            return $location;
        }

        if (is_array($location) && array() !== $location) {
            $built['location'] = $location;
        }

        $slots = $this->timeslots($event['timeslots'] ?? null);

        if ($this->errors->isError($slots) || ! is_array($slots)) {
            return $slots;
        }

        if (array() !== $slots) {
            $built['timeslots'] = $slots;
        }

        return array('event' => $built);
    }

    /**
     * @return array<string, mixed>|mixed
     */
    private function location(mixed $value): mixed
    {
        if (! is_array($value)) {
            return array();
        }

        $built = array();

        $title = $this->opaque($value, 'title');

        if ('' !== $title) {
            $built['title'] = $title;
        }

        $latitude  = $this->coordinate($value, 'latitude', 90.0);
        $longitude = $this->coordinate($value, 'longitude', 180.0);

        if ($this->errors->isError($latitude)) {
            return $latitude;
        }

        if ($this->errors->isError($longitude)) {
            return $longitude;
        }

        if (is_float($latitude)) {
            $built['latitude'] = $latitude;
        }

        if (is_float($longitude)) {
            $built['longitude'] = $longitude;
        }

        if (isset($value['radius'])) {
            if (! is_numeric($value['radius']) || 0 >= (float) $value['radius']) {
                return $this->errors->validationFailed(
                    'A location radius must be a positive number of metres.',
                    array('time_picker_event' => array('Invalid radius.'))
                );
            }

            $built['radius'] = (float) $value['radius'];
        }

        return $built;
    }

    /**
     * One coordinate, or the canonical refusal when it is out of range.
     *
     * @return float|mixed|null Null when the key is absent.
     */
    private function coordinate(array $source, string $key, float $limit): mixed
    {
        if (! isset($source[$key])) {
            return null;
        }

        if (! is_numeric($source[$key]) || $limit < abs((float) $source[$key])) {
            return $this->errors->validationFailed(
                $key . ' must be a number within ±' . $limit . '.',
                array('time_picker_event' => array('Invalid ' . $key . '.'))
            );
        }

        return (float) $source[$key];
    }

    /**
     * @return list<array<string, mixed>>|mixed
     */
    private function timeslots(mixed $value): mixed
    {
        if (! is_array($value)) {
            return array();
        }

        $slots = array();

        foreach ($value as $index => $slot) {
            if (! is_array($slot)) {
                return $this->slotRefused((int) $index, 'is not an object');
            }

            $identifier = $this->opaque($slot, 'identifier');
            $start      = $this->opaque($slot, 'startTime');

            if ('' === $identifier || '' === $start) {
                return $this->slotRefused((int) $index, 'needs an identifier and a startTime');
            }

            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $start)) {
                return $this->slotRefused((int) $index, 'startTime is not an ISO 8601 instant');
            }

            $built = array(
                'identifier' => $identifier,
                'startTime'  => $start,
            );

            if (isset($slot['duration'])) {
                if (! is_numeric($slot['duration']) || 0 >= (int) $slot['duration']) {
                    return $this->slotRefused((int) $index, 'duration must be a positive number of seconds');
                }

                $built['duration'] = (int) $slot['duration'];
            }

            $slots[] = $built;
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function richLink(array $arguments): mixed
    {
        if ('' === $this->textArg($arguments, 'rich_link_url')) {
            return $this->errors->validationFailed(
                'rich_link_url is required for a rich link.',
                array('rich_link_url' => array('Required.'))
            );
        }

        $url = $this->publicUrl($arguments, 'rich_link_url', 'rich link URL');

        if (! is_string($url)) {
            return $url;
        }

        $link = array('url' => $url);

        $title = $this->opaque($arguments, 'rich_link_title');

        if ('' !== $title) {
            $link['title'] = $title;
        }

        if ('' !== $this->textArg($arguments, 'rich_link_image_url')) {
            $image = $this->publicUrl($arguments, 'rich_link_image_url', 'rich link image URL');

            if (! is_string($image)) {
                return $image;
            }

            $link['imageUrl'] = $image;
        }

        return array('richLink' => $link);
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>|mixed
     */
    private function authenticate(array $arguments): mixed
    {
        $request = $this->opaque($arguments, 'authenticate_request_id');

        if ('' === $request) {
            return $this->errors->validationFailed(
                'authenticate_request_id is required for an authentication request.',
                array('authenticate_request_id' => array('Required.'))
            );
        }

        return array('authenticate' => array('requestIdentifier' => $request));
    }

    /**
     * @return mixed
     */
    private function sectionRefused(int $index, string $why): mixed
    {
        return $this->errors->validationFailed(
            'List picker section ' . $index . ' ' . $why . '.',
            array('list_picker_sections' => array('Section ' . $index . ' ' . $why . '.'))
        );
    }

    /**
     * @return mixed
     */
    private function itemRefused(int $section, string $why): mixed
    {
        return $this->errors->validationFailed(
            'A list picker item in section ' . $section . ' ' . $why . '.',
            array('list_picker_sections' => array('An item in section ' . $section . ' ' . $why . '.'))
        );
    }

    /**
     * @return mixed
     */
    private function slotRefused(int $index, string $why): mixed
    {
        return $this->errors->validationFailed(
            'Time slot ' . $index . ' ' . $why . '.',
            array('time_picker_event' => array('Slot ' . $index . ' ' . $why . '.'))
        );
    }
}
