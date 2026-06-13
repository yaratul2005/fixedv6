<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-servertrack-stream.php';

class StreamTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_st_options'] = [];
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    public function test_push_to_buffer_adds_entry() {
        $event = new ServerTrack_Event('Purchase', 'evt_123');
        $event->set_custom_data(['value' => 100]);
        $response = ['status' => 'success'];

        ServerTrack_Stream::push_to_buffer('meta', $event, $response);

        $buffer = get_option(ServerTrack_Stream::STREAM_OPTION, []);

        $this->assertCount(1, $buffer);
        $this->assertEquals('meta', $buffer[0]['platform']);
        $this->assertEquals('Purchase', $buffer[0]['event_name']);
        $this->assertEquals('evt_123', $buffer[0]['event_id']);
        $this->assertEquals($response, $buffer[0]['response']);
    }

    public function test_push_to_buffer_prepends_new_entries() {
        $event1 = new ServerTrack_Event('AddToCart', 'evt_1');
        $event2 = new ServerTrack_Event('Purchase', 'evt_2');
        $response = ['status' => 'success'];

        ServerTrack_Stream::push_to_buffer('meta', $event1, $response);
        ServerTrack_Stream::push_to_buffer('tiktok', $event2, $response);

        $buffer = get_option(ServerTrack_Stream::STREAM_OPTION, []);

        $this->assertCount(2, $buffer);
        $this->assertEquals('tiktok', $buffer[0]['platform']);
        $this->assertEquals('Purchase', $buffer[0]['event_name']);
        $this->assertEquals('meta', $buffer[1]['platform']);
        $this->assertEquals('AddToCart', $buffer[1]['event_name']);
    }

    public function test_push_to_buffer_respects_max_events() {
        $response = ['status' => 'success'];

        for ($i = 1; $i <= ServerTrack_Stream::MAX_EVENTS + 5; $i++) {
            $event = new ServerTrack_Event('Event' . $i, 'evt_' . $i);
            ServerTrack_Stream::push_to_buffer('meta', $event, $response);
        }

        $buffer = get_option(ServerTrack_Stream::STREAM_OPTION, []);

        $this->assertCount(ServerTrack_Stream::MAX_EVENTS, $buffer);

        // The most recently pushed event should be at index 0
        $expected_newest_event_name = 'Event' . (ServerTrack_Stream::MAX_EVENTS + 5);
        $this->assertEquals($expected_newest_event_name, $buffer[0]['event_name']);

        // The oldest event kept should be Event6
        $this->assertEquals('Event6', $buffer[ServerTrack_Stream::MAX_EVENTS - 1]['event_name']);
    }

    public function test_push_to_buffer_handles_invalid_option_state() {
        // Corrupt the option state
        update_option(ServerTrack_Stream::STREAM_OPTION, 'invalid_string');

        $event = new ServerTrack_Event('Lead', 'evt_abc');
        $response = ['status' => 'success'];

        ServerTrack_Stream::push_to_buffer('google', $event, $response);

        $buffer = get_option(ServerTrack_Stream::STREAM_OPTION, []);

        $this->assertIsArray($buffer);
        $this->assertCount(1, $buffer);
        $this->assertEquals('google', $buffer[0]['platform']);
        $this->assertEquals('Lead', $buffer[0]['event_name']);
    }
}