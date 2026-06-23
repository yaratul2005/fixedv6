<?php

use PHPUnit\Framework\TestCase;

class Test_Event_Schema extends TestCase {

    public function test_purchase_missing_currency() {
        $result = ServerTrack_EventSchema::validate('Purchase', ['value' => 99.99]);
        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: currency', $result['errors']);
    }

    public function test_purchase_valid() {
        $result = ServerTrack_EventSchema::validate('Purchase', ['currency' => 'USD', 'value' => 99.99]);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_viewcontent_optional_fields() {
        $result = ServerTrack_EventSchema::validate('ViewContent', ['content_name' => 'Product', 'content_ids' => ['sku-123']]);
        $this->assertTrue($result['valid']);
        $this->assertEquals('Product', $result['filtered_data']['content_name']);
    }

    public function test_lead_missing_recommended() {
        $result = ServerTrack_EventSchema::validate('Lead', []);
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertContains('Missing recommended field: lead_type', $result['warnings']);
    }

    public function test_unknown_event_type() {
        $result = ServerTrack_EventSchema::validate('UnknownEvent', []);
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertContains('Unknown event type: UnknownEvent. Schema validation skipped.', $result['warnings']);
    }
}
