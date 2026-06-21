<?php

use PHPUnit\Framework\TestCase;

class Test_ServerTrack_License extends TestCase {

    public function test_parse_array_data_with_valid_json() {
        $reflection = new ReflectionClass( 'ServerTrack_License' );
        $method = $reflection->getMethod( 'parse_array_data' );
        $method->setAccessible( true );

        $json = json_encode( [ 'foo' => 'bar' ] );
        $result = $method->invoke( null, $json );

        $this->assertIsArray( $result );
        $this->assertEquals( [ 'foo' => 'bar' ], $result );
    }

    public function test_parse_array_data_with_valid_array() {
        $reflection = new ReflectionClass( 'ServerTrack_License' );
        $method = $reflection->getMethod( 'parse_array_data' );
        $method->setAccessible( true );

        $array = [ 'baz' => 'qux' ];
        $result = $method->invoke( null, $array );

        $this->assertIsArray( $result );
        $this->assertEquals( [ 'baz' => 'qux' ], $result );
    }

    public function test_parse_array_data_with_serialized_string() {
        $reflection = new ReflectionClass( 'ServerTrack_License' );
        $method = $reflection->getMethod( 'parse_array_data' );
        $method->setAccessible( true );

        $serialized = serialize( [ 'should' => 'fail' ] );
        $result = $method->invoke( null, $serialized );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result, 'Serialized strings should be rejected and return an empty array.' );
    }

    public function test_parse_array_data_with_invalid_json() {
        $reflection = new ReflectionClass( 'ServerTrack_License' );
        $method = $reflection->getMethod( 'parse_array_data' );
        $method->setAccessible( true );

        $invalid_json = "{foo: 'bar'}";
        $result = $method->invoke( null, $invalid_json );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result, 'Invalid JSON should be rejected and return an empty array.' );
    }

    public function test_parse_array_data_with_non_array_json() {
        $reflection = new ReflectionClass( 'ServerTrack_License' );
        $method = $reflection->getMethod( 'parse_array_data' );
        $method->setAccessible( true );

        $non_array_json = json_encode( "just a string" );
        $result = $method->invoke( null, $non_array_json );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result, 'JSON decoding to non-array should return an empty array.' );
    }
}
