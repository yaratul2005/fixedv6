# CAPI Integration Examples

## Example 1: Custom Purchase Event
```php
$event = new ServerTrack_Event( 'Purchase', 'order_5512' );

$event->set_user_data( [
    'email'      => 'john@example.com',
    'first_name' => 'John',
    'last_name'  => 'Doe'
] );

$event->set_custom_data( [
    'currency'     => 'USD',
    'value'        => 49.99,
    'content_ids'  => [ 'sku_01' ],
    'content_type' => 'product'
] );

// Respect User Consent / Opt-Out status
$event->set_opt_out( ServerTrack_Consent::is_opted_out() );

ServerTrack_Core::dispatch_to_all( $event );
```

## Example 2: Lead Gen (CF7)
```php
$event = new ServerTrack_Event( 'Lead' );

$event->set_user_data( [
    'email' => $posted_data['your-email'],
] );

$event->set_custom_data( [
    'lead_type' => 'Newsletter Signup',
] );

ServerTrack_Core::dispatch_to_all( $event );
```
