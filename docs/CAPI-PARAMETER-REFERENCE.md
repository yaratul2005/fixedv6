# CAPI Parameter Reference Guide

This document defines the schema rules used to validate Facebook Conversions API events in ServerTrack.

## 1. Event Parameters by Type

### Purchase
- **Required**: `currency`, `value`
- **Recommended**: `content_type`, `num_items`, `content_ids`
- **Optional**: `content_name`, `content_category`

### AddToCart
- **Required**: `currency`, `value`
- **Recommended**: `content_name`, `content_type`, `content_ids`
- **Optional**: `content_category`

### ViewContent
- **Required**: None
- **Recommended**: `content_ids`, `content_type`, `content_name`
- **Optional**: `currency`, `value`, `content_category`

### InitiateCheckout
- **Required**: `currency`, `value`
- **Recommended**: `content_type`, `num_items`
- **Optional**: `content_name`, `content_ids`, `content_category`

### Lead
- **Required**: None
- **Recommended**: `lead_type`
- **Optional**: `currency`, `value`, `content_name`

### CompleteRegistration
- **Required**: None
- **Recommended**: `status`
- **Optional**: `currency`, `value`

### AddPaymentInfo
- **Required**: `currency`, `value`
- **Recommended**: `content_type`
- **Optional**: `content_name`, `content_ids`

## 2. User Data Parameters (Hashed)
All PII variables passed in the `$user_data` array are normalized and SHA-256 hashed before transmission.
- `em`: Email (Lowecased, trimmed)
- `ph`: Phone (E.164 standard)
- `fn`: First Name
- `ln`: Last Name
- `ct`: City
- `st`: State
- `zp`: Zip
- `country`: Country (ISO format)

## 3. User Data Parameters (Raw/Unhashed)
- `client_ip_address`: Detected directly from CF-Connecting-IP or X-Forwarded-For headers.
- `client_user_agent`: Direct HTTP_USER_AGENT string.
- `fbp`: Meta first-party browser cookie.
- `fbc`: Meta click attribution token.

## 4. Event Match Quality (EMQ) Scoring
EMQ defines how accurately Meta can associate a server event with a Facebook user account.
- **0.95+**: Excellent (Includes deeply hashed identifiers like email, phone, and name)
- **0.85+**: Good (Usually email + phone)
- **0.70+**: Fair (At least 4 identity parameters)
- **0.50+**: Poor (Fallback to IP and User-Agent)

Use the Dashboard EMQ Scorecard to monitor your implementation.
