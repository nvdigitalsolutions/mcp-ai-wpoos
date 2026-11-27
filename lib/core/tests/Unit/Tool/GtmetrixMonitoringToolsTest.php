<?php

/**
 * The GTmetrix monitoring and alert tools.
 *
 * Proposal 164 section 164.97. The cases that matter most: a malformed alert
 * condition must refuse the whole replace instead of silently dropping an
 * alert, and the event limit is a page size rather than a post-fetch slice.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tests\Support\FakeGtmetrixClient;
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetAlertConditionsTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetAlertEventsTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixSetAlertConditionsTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixSetMonitoringTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixMonitoringToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── set_monitoring ──────────────────────────────────────────────────

    public function test_a_monitoring_frequency_is_set(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetMonitoringTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'frequency' => 'daily'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('PAGE1', $result['data']['page_id']);
        $this->assertSame('daily', $result['data']['frequency']);
        $this->assertSame('daily', $result['data']['monitored']);

        $body = $client->calls[0]['body'];
        $this->assertSame('POST', $client->calls[0]['method']);
        $this->assertSame('performance_monitor', $body['data']['type']);
        $this->assertSame('daily', $body['data']['attributes']['frequency']);
    }

    public function test_none_is_reported_as_the_stored_no(): void
    {
        $client            = $this->client();
        $client->responses['POST pages/PAGE1/performance_monitor'] = array(
            'data' => array(
                'type'       => 'page',
                'id'         => 'PAGE1',
                'attributes' => array('monitored' => 'no'),
            ),
        );

        $result = ( new GtmetrixSetMonitoringTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'frequency' => 'none'),
            self::CONTEXT
        );

        $this->assertSame('none', $result['data']['frequency']);
        $this->assertSame('no', $result['data']['monitored']);
    }

    public function test_the_frequency_is_case_folded(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetMonitoringTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'frequency' => 'Daily'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('daily', $client->calls[0]['body']['data']['attributes']['frequency']);
    }

    public function test_an_unknown_frequency_is_refused_without_calling(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetMonitoringTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'frequency' => 'quarterly'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('frequency', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_plan_restriction_is_classified(): void
    {
        $client = $this->client();
        $client->failOn['POST pages/PAGE1/performance_monitor'] = new GtmetrixApiException(
            'Monitoring update not permitted for this plan.',
            'gtmetrix_plan_denied',
            403,
            'E40303'
        );

        $result = ( new GtmetrixSetMonitoringTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'frequency' => 'hourly'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_plan_denied', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
    }

    // ─── get_alert_conditions ────────────────────────────────────────────

    public function test_the_conditions_and_recipients_are_read(): void
    {
        $result = ( new GtmetrixGetAlertConditionsTool($this->errors, $this->client()) )
            ->execute(array('page_id' => 'PAGE1'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['create_video']);
        $this->assertSame(1, count($result['data']['conditions']));
        $this->assertSame('largest_contentful_paint', $result['data']['conditions'][0]['metric']);
        $this->assertSame('>', $result['data']['conditions'][0]['operator']);
        $this->assertSame(2500, $result['data']['conditions'][0]['value']);
        $this->assertArrayNotHasKey('junk', $result['data']['conditions'][0]);
        $this->assertSame('ops@example.test', $result['data']['send_to'][0]['email']);
        $this->assertSame('email', $result['data']['send_to'][0]['channel']);
        $this->assertArrayNotHasKey('extra', $result['data']['send_to'][0]);
    }

    public function test_an_invalid_page_id_is_refused(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixGetAlertConditionsTool($this->errors, $client) )
            ->execute(array('page_id' => ''), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── set_alert_conditions ────────────────────────────────────────────

    public function test_the_full_condition_set_is_sent(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'      => 'PAGE1',
                'create_video' => true,
                'conditions'   => array(
                    array('metric' => 'largest_contentful_paint', 'operator' => '>', 'value' => 2500),
                    array('metric' => 'cumulative_layout_shift', 'operator' => '>', 'value' => 0.1),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertTrue($result['data']['create_video']);

        $attributes = $client->calls[0]['body']['data']['attributes'];
        $this->assertTrue($attributes['create_video']);
        $this->assertSame('largest_contentful_paint', $attributes['conditions'][0]['metric']);
        $this->assertSame(0.1, $attributes['conditions'][1]['value']);
        $this->assertArrayNotHasKey('send_to', $attributes);
    }

    public function test_an_empty_list_clears_the_alerts(): void
    {
        $client            = $this->client();
        $client->responses['POST pages/PAGE1/performance_alert_conditions'] = array(
            'data' => array(
                'type'       => 'performance_alert_conditions',
                'id'         => 'PAGE1',
                'attributes' => array('create_video' => false, 'conditions' => array()),
            ),
        );

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'conditions' => array()),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['conditions']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(array(), $client->calls[0]['body']['data']['attributes']['conditions']);
    }

    public function test_a_malformed_condition_refuses_the_whole_replace(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(
                    array('metric' => 'ttfb', 'operator' => '>', 'value' => 600),
                    'not-an-object',
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conditions[1]', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_unknown_metric_is_refused_by_index(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(
                    array('metric' => 'vibes', 'operator' => '>', 'value' => 1),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conditions[0].metric', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_non_numeric_threshold_is_refused(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(
                    array('metric' => 'ttfb', 'operator' => '>', 'value' => 'fast'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conditions[0].value', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_unknown_operator_is_refused(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(
                    array('metric' => 'ttfb', 'operator' => '>=', 'value' => 600),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conditions[0].operator', $result['error']['data']);
    }

    public function test_more_than_ten_conditions_are_refused(): void
    {
        $client     = $this->client();
        $conditions = array();

        for ($i = 0; $i < 11; $i++) {
            $conditions[] = array('metric' => 'ttfb', 'operator' => '>', 'value' => 600 + $i);
        }

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array('page_id' => 'PAGE1', 'conditions' => $conditions),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conditions', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_recipients_are_sent_when_supplied_and_validated(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(),
                'send_to'    => array(array('email' => 'ops@example.test')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(array('email' => 'ops@example.test', 'channel' => 'email')),
            $client->calls[0]['body']['data']['attributes']['send_to']
        );

        $bad = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(),
                'send_to'    => array(array('email' => 'not-an-email')),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $bad['error']['code']);
        $this->assertArrayHasKey('send_to[0].email', $bad['error']['data']);
    }

    public function test_the_stored_set_is_counted_rather_than_the_sent_one(): void
    {
        $client            = $this->client();
        $client->responses['POST pages/PAGE1/performance_alert_conditions'] = array(
            'data' => array(
                'type'       => 'performance_alert_conditions',
                'id'         => 'PAGE1',
                'attributes' => array(
                    'create_video' => false,
                    'conditions'   => array(
                        array('metric' => 'ttfb', 'operator' => '>', 'value' => 700),
                    ),
                ),
            ),
        );

        $result = ( new GtmetrixSetAlertConditionsTool($this->errors, $client) )->execute(
            array(
                'page_id'    => 'PAGE1',
                'conditions' => array(
                    array('metric' => 'ttfb', 'operator' => '>', 'value' => 600),
                    array('metric' => 'onload', 'operator' => '>', 'value' => 3000),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(700, $result['data']['conditions'][0]['value']);
    }

    // ─── get_alert_events ────────────────────────────────────────────────

    public function test_the_events_are_read_with_a_page_size(): void
    {
        $client = $this->client();

        $result = ( new GtmetrixGetAlertEventsTool($this->errors, $client) )
            ->execute(array('page_id' => 'PAGE1'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'pages/PAGE1/performance_alert_events?page[size]=25',
            $client->calls[0]['path']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('EVENT1', $result['data']['events'][0]['event_id']);
        $this->assertSame(1700000000, $result['data']['events'][0]['timestamp']);
        $this->assertSame('REPORT1', $result['data']['events'][0]['report_id']);
        $this->assertSame('onload', $result['data']['events'][0]['alerts'][0]['metric']);
        $this->assertSame(5124, $result['data']['events'][0]['alerts'][0]['value']);
        $this->assertArrayNotHasKey('junk', $result['data']['events'][0]['alerts'][0]);
        $this->assertSame(array('ops@example.test'), $result['data']['events'][0]['recipients']);
        $this->assertSame('eBoo', $result['data']['events'][1]['alerts'][0]['error_slug']);
    }

    public function test_the_event_limit_is_bounded(): void
    {
        $client = $this->client();

        ( new GtmetrixGetAlertEventsTool($this->errors, $client) )
            ->execute(array('page_id' => 'PAGE1', 'limit' => 1000), self::CONTEXT);

        $this->assertStringEndsWith('page[size]=100', $client->calls[0]['path']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function client(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'POST pages/PAGE1/performance_monitor'            => array(
                'data' => array(
                    'type'       => 'page',
                    'id'         => 'PAGE1',
                    'attributes' => array('monitored' => 'daily'),
                ),
            ),
            'GET pages/PAGE1/performance_alert_conditions'    => array(
                'data' => array(
                    'type'       => 'performance_alert_conditions',
                    'id'         => 'PAGE1',
                    'attributes' => array(
                        'create_video' => true,
                        'conditions'   => array(
                            array(
                                'metric'   => 'largest_contentful_paint',
                                'operator' => '>',
                                'value'    => 2500,
                                'junk'     => array('nested'),
                            ),
                        ),
                        'send_to'      => array(
                            array('email' => 'ops@example.test', 'channel' => 'email', 'extra' => array('nested')),
                        ),
                    ),
                ),
            ),
            'POST pages/PAGE1/performance_alert_conditions'   => array(
                'data' => array(
                    'type'       => 'performance_alert_conditions',
                    'id'         => 'PAGE1',
                    'attributes' => array(
                        'create_video' => true,
                        'conditions'   => array(
                            array('metric' => 'largest_contentful_paint', 'operator' => '>', 'value' => 2500),
                            array('metric' => 'cumulative_layout_shift', 'operator' => '>', 'value' => 0.1),
                        ),
                        'send_to'      => array(array('email' => 'ops@example.test', 'channel' => 'email')),
                    ),
                ),
            ),
            'pages/PAGE1/performance_alert_events'            => array(
                'data' => array(
                    array(
                        'type'       => 'performance_alert_event',
                        'id'         => 'EVENT1',
                        'attributes' => array(
                            'timestamp'        => 1700000000,
                            'report_id'        => 'REPORT1',
                            'alerts'           => array(
                                array(
                                    'metric'    => 'onload',
                                    'operator'  => '>',
                                    'threshold' => 3000,
                                    'value'     => 5124,
                                    'junk'      => array('nested'),
                                ),
                            ),
                            'recipient_emails' => array('ops@example.test'),
                        ),
                    ),
                    array(
                        'type'       => 'performance_alert_event',
                        'id'         => 'EVENT2',
                        'attributes' => array(
                            'timestamp' => 1699990000,
                            'alerts'    => array(
                                array('error' => 'Error generating Lighthouse report', 'error_slug' => 'eBoo'),
                            ),
                        ),
                    ),
                ),
            ),
        );

        return $client;
    }
}
