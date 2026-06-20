<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-servertrack-event.php';

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, $event) {
        return $value;
    }
}

class EventTest extends TestCase {

    public function test_set_custom_data_merges_arrays() {
        $event = new ServerTrack_Event('Purchase', '123');

        // Initial set
        $event->set_custom_data(['value' => 100.0, 'currency' => 'USD']);
        $this->assertEquals(['value' => 100.0, 'currency' => 'USD'], $event->custom_data);

        // Secondary set should merge, adding 'content_ids' and keeping existing
        $event->set_custom_data(['content_ids' => ['SKU1']]);
        $this->assertEquals(['value' => 100.0, 'currency' => 'USD', 'content_ids' => ['SKU1']], $event->custom_data);

        // Third set should overwrite existing key 'value'
        $event->set_custom_data(['value' => 200.0]);
        $this->assertEquals(['value' => 200.0, 'currency' => 'USD', 'content_ids' => ['SKU1']], $event->custom_data);
    }
}
