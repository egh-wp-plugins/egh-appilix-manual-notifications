# EGH Appilix Manual Notifications: AI Usage Guide

This plugin exposes a reusable global PHP function that other plugins can call to send Appilix notifications to a filtered audience.

## Global Function

```php
egh_send_appilix_notification_to_audience( array $args )
```

## What It Does

- Sends Appilix notifications to users filtered by:
  - device
  - studying language
  - CEFR level
- Uses a broadcast request with blank `user_identity` when the request is completely unfiltered:
  - `device_target = all`
  - `language_target = all`
  - `cefr_target = all`
- Otherwise loops through matching users one by one and sends individual notifications.
- If `notification_image` is provided:
  - Android users receive the image
  - iPhone users receive the same notification without the image

## Supported Args

- `notification_title` string, required
- `notification_body` string, required
- `open_link_url` string, optional
- `notification_image` string, optional
- `device_target` string, optional
  - allowed values: `all`, `android`, `iphone`
- `language_target` string, optional
  - studied language code such as `en`, `es`, `de`, `ko`
- `level_target` string, optional
  - alias of `language_target` for backward compatibility
- `cefr_target` string, optional
  - allowed values typically: `A1`, `A2`, `B1`, `B2`, `C1`, `C2`

## Current Metadata Schema

The function currently filters users using these `wp_usermeta` keys:

- device: `student_device`
  - possible values: `android`, `ios`, `other`
- studying language: `egh_studying_language`
  - current app values: `en`, `de`, `es`, `ko`
- CEFR level: `egh_student_levels`
  - current app values typically: `A1`, `A2`, `B1`, `B2`, `C1`, `C2`

## Language Options Source

Studying-language options should come from the `egh-language-mapping` plugin when it is active:

```php
EGH_Language_Mapping::get_studied_language_options()
```

That means the admin UI in this plugin stays in sync with the central language-mapping plugin.

## Return Value

On success, the function returns an array like:

```php
array(
    'mode'          => 'broadcast' | 'targeted',
    'sent_count'    => 1,
    'failed_count'  => 0,
    'matched_users' => 0,
)
```

On failure, it returns a `WP_Error`.

## Example

```php
$result = egh_send_appilix_notification_to_audience( array(
    'notification_title' => 'New Spanish Practice',
    'notification_body'  => 'A new A2 activity is ready for you.',
    'open_link_url'      => 'https://app.english-grammar-homework.com/?open=home',
    'notification_image' => 'https://example.com/image.jpg',
    'device_target'      => 'android',
    'language_target'    => 'es',
    'cefr_target'        => 'A2',
) );

if ( is_wp_error( $result ) ) {
    error_log( 'Notification send failed: ' . $result->get_error_message() );
} else {
    error_log( 'Notifications sent: ' . wp_json_encode( $result ) );
}
```

## Notes For Future AIs

- Prefer calling the global function instead of duplicating audience-query logic in another plugin.
- If the app’s usermeta schema changes, update this plugin first so every caller inherits the fix.
- If the studied languages change, prefer updating `egh-language-mapping` rather than hardcoding new languages elsewhere.
