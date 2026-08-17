<?php

return [
    // Firebase project ID — Firebase Console > Project Settings.
    'project_id' => env('FCM_PROJECT_ID'),

    // Absolute path to the service-account JSON used to sign FCM v1 requests.
    // Keep this OFF the device build and out of version control.
    'credentials' => env('FIREBASE_CREDENTIALS'),

    /*
    |--------------------------------------------------------------------------
    | Allowed background events (security)
    |--------------------------------------------------------------------------
    | The FCM `data.event` key names the event class dispatched on the device.
    | This field is fully attacker-controlled: it comes directly from whatever
    | sent the push notification, which — on a leaked/compromised Firebase
    | server key, or any other unauthorized sender — is not necessarily you.
    |
    | ⚠️ HARDENED DEFAULT (differs from upstream fatlum/nativephp-push): this
    | list is FAIL-CLOSED. Only classes explicitly listed here can be
    | instantiated via a push payload. Upstream shipped an empty array
    | meaning "allow any class in your entire application" by default —
    | dynamic instantiation of an arbitrary class with attacker-controlled
    | constructor arguments is not an acceptable default for a
    | network-reachable input. See CHANGES.md for the full writeup.
    |
    | Add your own event classes here explicitly as you need them:
    |
    | NOTE: this guards the background/ephemeral path only. The foreground
    | path runs through NativePHP core's own POST /_native/api/events route,
    | which this config cannot gate — that's core's surface, not this
    | plugin's. Confirm with NativePHP core's docs/source whether it has its
    | own allow-list mechanism for that path before assuming it's covered.
    */
    'allowed_events' => [
        \Iamdevroyal\NativePush\Events\PushNotificationReceived::class,
    ],
];
