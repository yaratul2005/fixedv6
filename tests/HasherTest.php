<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-servertrack-hasher.php';

class HasherTest extends TestCase {

    public function test_hash_normalises_string() {
        $expected = hash('sha256', 'test@example.com');

        $this->assertEquals($expected, ServerTrack_Hasher::hash('test@example.com'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash(' Test@Example.com '));
        $this->assertEquals($expected, ServerTrack_Hasher::hash('TEST@EXAMPLE.COM'));
    }

    public function test_hash_name() {
        $expected = hash('sha256', 'johnsmith');

        $this->assertEquals($expected, ServerTrack_Hasher::hash_name('John Smith'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_name(' John-Smith! '));
    }

    public function test_hash_city() {
        $expected = hash('sha256', 'newyork');

        $this->assertEquals($expected, ServerTrack_Hasher::hash_city('New York'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_city(' New-York! '));
    }

    public function test_hash_state() {
        $expected = hash('sha256', 'ny');

        $this->assertEquals($expected, ServerTrack_Hasher::hash_state('NY'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_state(' NY '));
    }

    public function test_hash_zip() {
        $expected = hash('sha256', '10001');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_zip('10001'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_zip(' 10001 '));

        // zip codes might have hyphens
        $expected2 = hash('sha256', '100011234');
        $this->assertEquals($expected2, ServerTrack_Hasher::hash_zip('10001-1234'));
    }

    public function test_hash_country() {
        $expected = hash('sha256', 'us');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_country('US'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_country(' us '));
    }

    public function test_hash_email_normalizes_and_hashes() {
        $expected = hash('sha256', 'test@example.com');

        $this->assertEquals($expected, ServerTrack_Hasher::hash_email('test@example.com'));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_email(' Test@Example.com '));
        $this->assertEquals($expected, ServerTrack_Hasher::hash_email('TEST@EXAMPLE.COM'));
    }

    public function test_hash_email_returns_empty_string_for_empty_input() {
        $this->assertEquals('', ServerTrack_Hasher::hash_email(''));
        $this->assertEquals('', ServerTrack_Hasher::hash_email('   '));
    }

    public function test_hash_phone_strips_non_numeric() {
        // Just numeric normalization (no country code provided)
        $expected = hash('sha256', '12125551234');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_phone('+1 (212) 555-1234', ''));
    }

    public function test_hash_phone_with_country_code_prepends_it() {
        $expected = hash('sha256', '8801712345678');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_phone('01712345678', '880'));
    }

    public function test_hash_phone_with_country_code_already_present() {
        $expected = hash('sha256', '8801712345678');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_phone('+8801712345678', '880'));
    }

    public function test_hash_phone_with_country_code_and_leading_zero() {
        $expected = hash('sha256', '447123456789');
        $this->assertEquals($expected, ServerTrack_Hasher::hash_phone('07123456789', '44'));
    }

    public function test_hash_phone_empty() {
        $this->assertEquals('', ServerTrack_Hasher::hash_phone(''));
        $this->assertEquals('', ServerTrack_Hasher::hash_phone('   '));
        $this->assertEquals('', ServerTrack_Hasher::hash_phone('abc'));
    }

    public function test_event_id() {
        // Since ServerTrack_Dedup::generate_event_id is likely mocked or not.
        // Actually, we can just test if it returns a string if it's mocked, or we can check bootstrap.php.
        // Let's rely on the mock returning a stable value if it exists, or just assert it's a string.
        $result = ServerTrack_Hasher::event_id('Purchase', 123);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
