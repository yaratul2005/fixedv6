# ServerTrack Consent & Privacy Guide

ServerTrack implements robust tools to comply with GDPR, CCPA, and Facebook's Advanced Consent specifications.

## The Opt-Out Parameter
ServerTrack uses an `opt_out` parameter boolean within the `ServerTrack_Event` payload structure.
When true, this signals to Facebook and other ad networks that the user's data should strictly not be used for campaign optimization or tracking algorithms.

### How to use
```php
$event = new ServerTrack_Event( 'Purchase' );
$opt_out = ServerTrack_Consent::is_opted_out( $order_id );
$event->set_opt_out( $opt_out );
```

This ensures that even if events are fired to complete attribution paths or analytic goals, Meta respects the privacy restriction.
