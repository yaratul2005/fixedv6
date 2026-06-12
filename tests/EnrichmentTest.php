<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-servertrack-enrichment.php';

class EnrichmentTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $_SERVER = [];
    }

    public function test_get_client_ip_returns_cf_connecting_ip_highest_priority() {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1';
        $_SERVER['HTTP_X_REAL_IP'] = '2.2.2.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '3.3.3.3,4.4.4.4';
        $_SERVER['REMOTE_ADDR'] = '5.5.5.5';

        $this->assertEquals('1.1.1.1', ServerTrack_Enrichment::get_client_ip());
    }

    public function test_get_client_ip_returns_x_real_ip() {
        $_SERVER['HTTP_X_REAL_IP'] = '2.2.2.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '3.3.3.3,4.4.4.4';
        $_SERVER['REMOTE_ADDR'] = '5.5.5.5';

        $this->assertEquals('2.2.2.2', ServerTrack_Enrichment::get_client_ip());
    }

    public function test_get_client_ip_returns_last_x_forwarded_for() {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '3.3.3.3, 4.4.4.4';
        $_SERVER['REMOTE_ADDR'] = '5.5.5.5';

        $this->assertEquals('4.4.4.4', ServerTrack_Enrichment::get_client_ip());
    }

    public function test_get_client_ip_returns_remote_addr_as_fallback() {
        $_SERVER['REMOTE_ADDR'] = '5.5.5.5';

        $this->assertEquals('5.5.5.5', ServerTrack_Enrichment::get_client_ip());
    }

    public function test_get_client_ip_returns_empty_if_nothing_set() {
        $this->assertEquals('', ServerTrack_Enrichment::get_client_ip());
    }

    /**
     * @dataProvider userAgentProvider
     */
    public function test_parse_user_agent(string $ua, array $expected) {
        $parsed = ServerTrack_Enrichment::parse_user_agent($ua);
        $this->assertEquals($expected, $parsed);
    }

    public static function userAgentProvider() {
        return [
            'iOS Safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                ['os' => 'iOS', 'device_type' => 'mobile', 'browser_name' => 'Safari']
            ],
            'iPad Safari' => [
                'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                ['os' => 'iOS', 'device_type' => 'mobile', 'browser_name' => 'Safari']
            ],
            'iOS Chrome' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/115.0.5790.130 Mobile/15E148 Safari/604.1',
                ['os' => 'iOS', 'device_type' => 'mobile', 'browser_name' => 'Chrome']
            ],
            'Android Chrome' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Mobile Safari/537.36',
                ['os' => 'Android', 'device_type' => 'mobile', 'browser_name' => 'Chrome']
            ],
            'Android Firefox' => [
                'Mozilla/5.0 (Android 13; Mobile; rv:109.0) Gecko/116.0 Firefox/116.0',
                ['os' => 'Android', 'device_type' => 'mobile', 'browser_name' => 'Firefox']
            ],
            'Windows Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
                ['os' => 'Windows', 'device_type' => 'desktop', 'browser_name' => 'Chrome']
            ],
            'Windows Firefox' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/116.0',
                ['os' => 'Windows', 'device_type' => 'desktop', 'browser_name' => 'Firefox']
            ],
            'Mac Chrome' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
                ['os' => 'Mac OS X', 'device_type' => 'desktop', 'browser_name' => 'Chrome']
            ],
            'Mac Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
                ['os' => 'Mac OS X', 'device_type' => 'desktop', 'browser_name' => 'Safari']
            ],
            'Unknown Desktop Browser' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) UnknownBrowser/1.0',
                ['os' => 'Windows', 'device_type' => 'desktop']
            ],
            'Empty string' => [
                '',
                ['device_type' => 'desktop']
            ],
        ];
    }
}
