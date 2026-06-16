<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-servertrack-cookiehelper.php';

if (!function_exists('is_admin')) {
    function is_admin() { return $GLOBALS['mock_is_admin'] ?? false; }
}
if (!function_exists('wp_doing_cron')) {
if (!defined("DAY_IN_SECONDS")) define("DAY_IN_SECONDS", 86400);
    function wp_doing_cron() { return $GLOBALS['mock_wp_doing_cron'] ?? false; }
}
if (!function_exists('home_url')) {
    function home_url() { return 'https://example.com'; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component) { return 'example.com'; }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return true; }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min, $max) { return 1234567890; }
}

class CookieHelperTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $_GET = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_SERVER['HTTP_HOST'] = 'example.com';
        $GLOBALS['mock_is_admin'] = false;
        $GLOBALS['mock_wp_doing_cron'] = false;
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    public function test_ignores_admin() {
        $GLOBALS['mock_is_admin'] = true;
        $_GET['fbclid'] = 'test1234';
        ServerTrack_CookieHelper::capture_and_refresh_cookies();
        $this->assertArrayNotHasKey('_fbc', $_COOKIE);
    }

    public function test_ignores_cron() {
        $GLOBALS['mock_wp_doing_cron'] = true;
        $_GET['fbclid'] = 'test1234';
        ServerTrack_CookieHelper::capture_and_refresh_cookies();
        $this->assertArrayNotHasKey('_fbc', $_COOKIE);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_captures_fbclid() {
        // Needs `@runInSeparateProcess` because it modifies headers/cookies
        $_GET['fbclid'] = 'test_fbc_id';
        ServerTrack_CookieHelper::capture_and_refresh_cookies();

        $this->assertArrayHasKey('_fbc', $_COOKIE);
        $this->assertStringContainsString('fb.1.', $_COOKIE['_fbc']);
        $this->assertStringContainsString('.test_fbc_id', $_COOKIE['_fbc']);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_generates_fbp_if_missing() {
        ServerTrack_CookieHelper::capture_and_refresh_cookies();

        $this->assertArrayHasKey('_fbp', $_COOKIE);
        $this->assertStringContainsString('fb.1.', $_COOKIE['_fbp']);
        $this->assertStringContainsString('.1234567890', $_COOKIE['_fbp']); // uses mocked wp_rand
    }

    /**
     * @runInSeparateProcess
     */
    public function test_captures_ttclid() {
        $_GET['ttclid'] = 'test_ttclid_id';
        ServerTrack_CookieHelper::capture_and_refresh_cookies();

        $this->assertArrayHasKey('ttclid', $_COOKIE);
        $this->assertEquals('test_ttclid_id', $_COOKIE['ttclid']);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_captures_gclid() {
        $_GET['gclid'] = 'test_gclid_id';
        ServerTrack_CookieHelper::capture_and_refresh_cookies();

        $this->assertArrayHasKey('_gcl_aw', $_COOKIE);
        $this->assertStringContainsString('GCL.', $_COOKIE['_gcl_aw']);
        $this->assertStringContainsString('.test_gclid_id', $_COOKIE['_gcl_aw']);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_refreshes_existing_cookies() {
        $_COOKIE['_fbc'] = 'fb.1.12345.existing_fbc';
        $_COOKIE['_fbp'] = 'fb.1.12345.existing_fbp';
        $_COOKIE['ttclid'] = 'existing_ttclid';
        $_COOKIE['_gcl_aw'] = 'GCL.12345.existing_gcl';

        ServerTrack_CookieHelper::capture_and_refresh_cookies();

        $this->assertEquals('fb.1.12345.existing_fbc', $_COOKIE['_fbc']);
        $this->assertEquals('fb.1.12345.existing_fbp', $_COOKIE['_fbp']);
        $this->assertEquals('existing_ttclid', $_COOKIE['ttclid']);
        $this->assertEquals('GCL.12345.existing_gcl', $_COOKIE['_gcl_aw']);
    }
}
