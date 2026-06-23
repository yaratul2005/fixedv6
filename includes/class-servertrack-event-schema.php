<?php

if ( ! defined( 'ABSPATH' ) ) {
    die();
}

/**
 * Validates custom data payloads for standard CAPI events.
 */
class ServerTrack_EventSchema {

    private static array $schemas = [
        'Purchase' => [
            'required'    => ['currency', 'value'],
            'recommended' => ['content_type', 'num_items', 'content_ids'],
            'optional'    => ['content_name', 'content_category', 'order_id'],
        ],
        'AddToCart' => [
            'required'    => ['currency', 'value'],
            'recommended' => ['content_name', 'content_type', 'content_ids'],
            'optional'    => ['content_category'],
        ],
        'ViewContent' => [
            'required'    => [],
            'recommended' => ['content_ids', 'content_type', 'content_name'],
            'optional'    => ['currency', 'value', 'content_category'],
        ],
        'InitiateCheckout' => [
            'required'    => ['currency', 'value'],
            'recommended' => ['content_type', 'num_items'],
            'optional'    => ['content_name', 'content_ids', 'content_category'],
        ],
        'Lead' => [
            'required'    => [],
            'recommended' => ['lead_type'],
            'optional'    => ['currency', 'value', 'content_name'],
        ],
        'CompleteRegistration' => [
            'required'    => [],
            'recommended' => ['status'],
            'optional'    => ['currency', 'value'],
        ],
        'AddPaymentInfo' => [
            'required'    => ['currency', 'value'],
            'recommended' => ['content_type'],
            'optional'    => ['content_name', 'content_ids'],
        ],
    ];

    /**
     * Get all available event types
     *
     * @return array Array of event type strings
     */
    public static function get_event_types(): array {
        return array_keys( self::$schemas );
    }

    /**
     * Get schema definition for a specific event type
     *
     * @param string $event_name
     * @return array|null Schema definition or null if not found
     */
    public static function get_schema( string $event_name ): ?array {
        return self::$schemas[$event_name] ?? null;
    }

    /**
     * Checks if a field is required for a specific event
     *
     * @param string $event_name
     * @param string $field
     * @return bool
     */
    public static function is_required( string $event_name, string $field ): bool {
        $schema = self::get_schema( $event_name );
        return $schema && in_array( $field, $schema['required'], true );
    }

    /**
     * Validate an event payload against its schema
     *
     * @param string $event_name The standard event name
     * @param array $custom_data The custom data array to validate
     * @return array { valid: bool, errors: array, warnings: array, filtered_data: array }
     */
    public static function validate( string $event_name, array $custom_data ): array {
        $result = [
            'valid'         => true,
            'errors'        => [],
            'warnings'      => [],
            'filtered_data' => $custom_data,
        ];

        $schema = self::get_schema( $event_name );

        if ( ! $schema ) {
            $result['warnings'][] = sprintf( 'Unknown event type: %s. Schema validation skipped.', $event_name );
            return $result;
        }

        // Check required
        foreach ( $schema['required'] as $field ) {
            if ( ! isset( $custom_data[$field] ) || $custom_data[$field] === '' || $custom_data[$field] === null ) {
                $result['valid'] = false;
                $result['errors'][] = sprintf( 'Missing required field: %s', $field );
            }
        }

        // Check recommended
        foreach ( $schema['recommended'] as $field ) {
            if ( ! isset( $custom_data[$field] ) || $custom_data[$field] === '' || $custom_data[$field] === null ) {
                $result['warnings'][] = sprintf( 'Missing recommended field: %s', $field );
            }
        }

        return $result;
    }
}
