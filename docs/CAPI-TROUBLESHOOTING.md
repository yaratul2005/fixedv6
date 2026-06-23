# CAPI Troubleshooting Guide

## 1. Events Not Sending
Ensure that your Meta App possesses a valid OAuth token and that the Pixel ID is correctly configured. Check the `ServerTrack -> Debug` tab.

## 2. Validation Warnings
You might see `schema_validation_error` in the debug log. This means your `custom_data` array was missing a required parameter. For example, a `Purchase` event *MUST* contain `currency` and `value`. If these are absent, ServerTrack blocks transmission to preserve pixel health.

## 3. Low EMQ Scores
If your Dashboard indicates a Poor EMQ score (< 0.50), it means you are only dispatching basic metadata (IP and User-Agent). You must extract user variables like `email` or `phone` during checkout / forms and apply them via `$event->set_user_data()`.
