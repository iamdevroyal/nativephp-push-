# iamdevroyal/nativephp-push

**Free, MIT-licensed** FCM/APNs push notifications for [NativePHP Mobile](https://nativephp.com/docs/mobile).  
Implements the Swift/Kotlin native layer that NativePHP's open-source core already expects, adds a fluent server-side FCM v1 sender, and ships with **security-first defaults** — fail-closed event allow-list, native-side FQCN validation, and belt-and-suspenders defense in depth across every layer. Firebase Cloud Messaging and APNs are free; this is the glue.

---

## Table of Contents

1. [How It Fits Together](#how-it-fits-together)
2. [What's Implemented](#whats-implemented)
3. [Security Model](#security-model)
4. [Requirements](#requirements)
5. [Installation](#installation)
6. [Firebase Setup](#firebase-setup)
7. [Configuration](#configuration)
8. [Usage — Livewire / PHP](#usage--livewire--php)
9. [Usage — Vue / React / Plain SPA](#usage--vue--react--plain-spa)
10. [Background Event Processing](#background-event-processing)
11. [Sending from Your Server](#sending-from-your-server)
12. [Architecture Reference](#architecture-reference)
13. [Troubleshooting](#troubleshooting)
14. [License](#license)

---

## How It Fits Together

NativePHP Mobile's open-source core (`nativephp/mobile`) already ships the PHP API, the `TokenGenerated` event, and the on-device event-dispatch route. This plugin supplies what core calls but does not include:

| Layer | Where it lives |
|---|---|
| `PushNotifications::enroll()` / `checkPermission()` / `getToken()` facade | **core** (`Native\Mobile\Facades\PushNotifications`) |
| `TokenGenerated` event, `POST /_native/api/events` route | **core** |
| Ephemeral PHP runtime for background execution | **core** (v3.2+) |
| Native bridge functions (`PushNotification.*`), Firebase SDK, FCM messaging service | **this plugin** |
| Free server-side FCM v1 sender (`FcmSender` / `FcmMessage`) | **this plugin** |

> **Do not install this alongside `nativephp/mobile-firebase`.** Both register the same `PushNotification.*` bridge functions — pick one.

---

## What's Implemented

- **Permission flow** — request and check notification permission on both platforms
- **Token delivery** — `TokenGenerated` fires with `token` + enrollment `id` when FCM issues a device token
- **Background data-message processing** — when the app is backgrounded or killed, the FCM service boots core's ephemeral PHP runtime and dispatches your event via the `native:push:dispatch` artisan command
- **Foreground messages** — flow through the live web view so mounted Livewire components react immediately
- **Deep-link / data handling** — full FCM data map forwarded to your event constructor
- **Badge clearing** — `clearBadge()` / `PushNotification.ClearBadge`
- **Free server-side sending** — fluent `FcmMessage` builder + `FcmSender` via the FCM HTTP v1 API
- **Fail-closed event allow-list** — nothing runs unless you explicitly permit it
- **Native-side FQCN validation** — injected class names are rejected before they ever touch a command string

---

## Security Model

Push notification payloads arrive over the network from whoever holds a valid Firebase sender credential. The `data.event` field — which names the PHP class to dispatch on device — is **fully attacker-controlled** on a leaked or compromised key.

This plugin applies two independent layers of defense:

### Layer 1 — Native FQCN validation (Android + iOS)

Before `eventClass` is used for _anything_, it is matched against a strict pattern:

```
^[A-Za-z_][A-Za-z0-9_]*(\[A-Za-z_][A-Za-z0-9_]*)*$
```

Letters, digits, underscores, and backslash namespace separators — nothing else. A class name containing a quote character, a shell metacharacter, or any other unexpected byte is rejected and logged before it reaches the artisan command string. This closes the argument-injection vector at the source.

### Layer 2 — Fail-closed PHP allow-list

`DispatchPushEventCommand` checks `config('push.allowed_events')` using **default-deny** logic: an empty or unconfigured list means **nothing** is permitted, not everything. Only classes you explicitly add to the list can be instantiated via a push payload.

The two layers are independent — both must pass. Bypassing one does not bypass the other.

> **Scope note:** this plugin's allow-list guards the **background / ephemeral** path only. The foreground path runs through NativePHP core's `POST /_native/api/events` route, which is outside this plugin's scope. Verify core's own protections for that surface before assuming it is covered.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.2 |
| `nativephp/mobile` | ^3.2 (ephemeral runtime required) |
| `google/auth` | any (server-side **sending** machine only — not on device) |

---

## Installation

### 1. Add the repository (path installs / local development)

```json
// your app's composer.json
{
    "repositories": [
        { "type": "path", "url": "../packages/nativephp-push" }
    ]
}
```

### 2. Require the package

```bash
composer require iamdevroyal/nativephp-push
```

### 3. Register the plugin with NativePHP

```bash
php artisan native:plugin:register iamdevroyal/nativephp-push
```

### 4. Publish the config — **required**

```bash
php artisan vendor:publish --tag=native-push-config
```

This publishes `config/push.php`. **You must review and edit it** — specifically the `allowed_events` list — before building. The config ships with default-deny: only `PushNotificationReceived` is pre-approved. Add every event class you actually send.

### 5. Copy Firebase assets

The copy-assets hook runs automatically during `php artisan native:run` / `native:build`, but you can run it manually too:

```bash
php artisan native-push:copy-assets
```

---

## Firebase Setup

### Step 1 — Create a Firebase project

Open [Firebase Console](https://console.firebase.google.com/) and create a project (free tier is sufficient).

### Step 2 — Android

1. Add an Android app to your Firebase project.
2. Download `google-services.json`.
3. Place it at **`resources/google-services.json`** inside the plugin directory. The `copy-assets` hook places it at `app/google-services.json` in the Gradle project automatically at build time.

### Step 3 — iOS

1. Add an iOS app to your Firebase project.
2. Download `GoogleService-Info.plist`.
3. Place it at **`resources/GoogleService-Info.plist`** inside the plugin directory.
4. In Firebase Console → your project → Cloud Messaging → Apple app, upload your **APNs key** (Key ID + Team ID) or APNs certificate.

### Step 4 — Server-side credentials

1. Firebase Console → Project Settings → **Service Accounts**.
2. Click **Generate new private key** and save the downloaded JSON.
3. Store it somewhere **outside** your repository and your device build.

### Step 5 — Environment variables

```dotenv
# APNs environment — 'development' for local/simulator builds,
# 'production' for TestFlight and App Store submissions.
APS_ENVIRONMENT=production

# Server-side FCM sending (never included in a device build)
FCM_PROJECT_ID=your-firebase-project-id
FIREBASE_CREDENTIALS=/absolute/path/to/service-account.json
```

---

## Configuration

`config/push.php` after publishing:

```php
return [
    // Firebase project ID (server-side sending)
    'project_id'  => env('FCM_PROJECT_ID'),

    // Absolute path to the service-account JSON (server-side signing only)
    'credentials' => env('FIREBASE_CREDENTIALS'),

    /*
    |------------------------------------------------------------------
    | Allowed background events — FAIL-CLOSED by default
    |------------------------------------------------------------------
    | Only classes listed here can be instantiated via a push payload.
    | An empty array means nothing is allowed.
    |
    | Add every event class you send as data.event:
    */
    'allowed_events' => [
        \Iamdevroyal\NativePush\Events\PushNotificationReceived::class,

        // \App\Events\OrderShipped::class,
        // \App\Events\ChatMessageReceived::class,
    ],
];
```

---

## Usage — Livewire / PHP

Use NativePHP core's facade directly from any Livewire component or service:

```php
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Events\PushNotification\TokenGenerated;

// Prompt for permission and start enrollment (call once at login or app boot)
PushNotifications::enroll();

// Check current permission status without prompting
$status = PushNotifications::checkPermission();
// Returns: 'granted' | 'denied' | 'not_determined' | 'provisional' | 'ephemeral'

// Receive the token via a Livewire attribute
#[\Native\Mobile\Attributes\OnNative(TokenGenerated::class)]
public function handleToken(string $token): void
{
    auth()->user()->update(['push_token' => $token]);
}
```

---

## Usage — Vue / React / Plain SPA

There is no event-subscription mechanism on the JS side. Call `enroll()`, then call `getToken()` — it blocks natively for up to 3 seconds waiting for Firebase, so one call after enrollment is usually enough:

```js
import { checkPermission, enroll, getToken, clearBadge } from 'iamdevroyal-nativephp-push';

// Check current status without prompting
const { status } = await checkPermission();
// 'granted' | 'denied' | 'not_determined' | 'provisional' | 'ephemeral'

// Enroll (pass a stable user ID and the event class to fire with the token)
await enroll('user-42', 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated');

// Retrieve the FCM token and send it to your backend
const { token } = await getToken();
await fetch('/api/push-token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
});

// Clear the iOS badge and delivered notifications
await clearBadge();
```

The JS helpers call NativePHP's bridge endpoint (`POST /_native/api/call`) directly and work in any front-end framework without Livewire.

---

## Background Event Processing

When the app is **backgrounded or killed**, incoming FCM data messages are processed by the native service, which boots core's ephemeral PHP runtime and runs:

```
native:push:dispatch 'Your\App\Events\SomeEvent' '<base64-json>' --base64
```

The event class must be in your `push.allowed_events` list or the dispatch is rejected before any instantiation occurs.

### Defining a background event

Any event class with an `array $data` constructor works:

```php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrderShipped
{
    use Dispatchable;

    public function __construct(public array $data = []) {}
}
```

Add it to `config/push.php`:

```php
'allowed_events' => [
    \Iamdevroyal\NativePush\Events\PushNotificationReceived::class,
    \App\Events\OrderShipped::class,
],
```

Register a listener in a service provider:

```php
use Illuminate\Support\Facades\Event;
use App\Events\OrderShipped;

// Runs in the ephemeral runtime when the app is in the background
Event::listen(function (OrderShipped $event) {
    // $event->data — persist, queue work, update local SQLite, etc.
    logger()->info('Order shipped in background', $event->data);
});
```

### Using the bundled convenience event

`PushNotificationReceived` ships pre-approved and is suitable for generic data messages:

```php
use Iamdevroyal\NativePush\Events\PushNotificationReceived;

Event::listen(function (PushNotificationReceived $event) {
    $data    = $event->data;        // full FCM data map (minus 'event' key)
    $payload = $event->payload();   // auto-decodes a nested JSON 'payload' string if present
});
```

> **Event constructor convention:** the native handler wraps the FCM `data` map (minus the `event` key) under a single `data` key and passes it as the sole constructor argument. Design your event classes as `__construct(array $data = [])`.

---

## Sending from Your Server

Install `google/auth` on the machine that **sends** pushes:

```bash
composer require google/auth
```

### Tray notification (no PHP runs on device)

```php
use Iamdevroyal\NativePush\Server\FcmSender;

$sender = new FcmSender();

$sender->notify(
    $deviceToken,
    'Order shipped',
    'Your order #1234 is on its way.',
    ['url' => '/orders/1234']   // optional extra data
);
```

### Background event (triggers PHP on device)

```php
use Iamdevroyal\NativePush\Server\{FcmSender, FcmMessage};

$sender = new FcmSender();

$sender->send(
    FcmMessage::make()
        ->to($deviceToken)
        ->event(\App\Events\OrderShipped::class, [
            'order_id' => 1234,
            'status'   => 'shipped',
        ])
);
```

The event class you pass to `->event()` must be in the device's `push.allowed_events` list or it will be rejected at dispatch time.

### Combined — notification + background event

```php
FcmMessage::make()
    ->to($deviceToken)
    ->notification('Sync ready', 'New data available.')    // shows in tray
    ->event(\App\Events\SyncReady::class, ['items' => 5])  // triggers PHP
    ->badge(3);
```

### FcmMessage API reference

| Method | Description |
|---|---|
| `::make()` | Static factory |
| `->to(string $token)` | Device registration token (required) |
| `->notification(string $title, string $body)` | Visible tray notification |
| `->event(string $class, array $extra = [])` | Data-only message — triggers PHP dispatch on device |
| `->data(array $data)` | Arbitrary extra key/value data (values coerced to strings) |
| `->url(string $url)` | Shorthand for `->data(['url' => $url])` |
| `->badge(int $count)` | iOS badge count |
| `->toArray()` | Returns the FCM HTTP v1 `message` object |

---

## Architecture Reference

```
FCM / APNs
    │
    ▼
┌─────────────────────────────────────────────┐
│  Native layer (this plugin)                 │
│  ┌──────────────────┐  ┌──────────────────┐ │
│  │ Android          │  │ iOS              │ │
│  │ PushMessaging    │  │ PushObserver     │ │
│  │ Service.kt       │  │ .swift           │ │
│  │                  │  │                  │ │
│  │ PushDispatch.kt  │  │ dispatchToPHP()  │ │
│  │ ① FQCN validate  │  │ ① FQCN validate  │ │
│  └────────┬─────────┘  └────────┬─────────┘ │
└───────────┼────────────────────┼────────────┘
            │ app foregrounded?  │ app foregrounded?
            │ Yes → web view     │ Yes → web view (Livewire)
            │ No ↓               │ No ↓
            └──────┬─────────────┘
                   ▼
         Ephemeral PHP runtime (core)
                   │
         native:push:dispatch (artisan)
                   │
         ② FQCN validate   (defense-in-depth)
         ③ allow-list check (fail-closed)
                   │
         event(new $EventClass($data))
                   │
         Your Laravel event listeners
```

---

## Troubleshooting

### `google/auth` not found

```bash
composer require google/auth
```

Only required on the machine that sends pushes. Not included on device.

### Token is empty after `getToken()`

- Confirm `google-services.json` (Android) or `GoogleService-Info.plist` (iOS) is present in `resources/`.
- Run `php artisan native-push:copy-assets` before building.
- The native `getToken()` waits up to 3 seconds for Firebase. On slow networks or first boot, a single retry after a short delay may be needed.

### Background events are rejected / not dispatching

1. Confirm the event class is in `config('push.allowed_events')`.
2. Confirm the class name in your FCM `data.event` field matches exactly (backslashes must be double-escaped in JSON strings: `"Iamdevroyal\\\\NativePush\\\\Events\\\\PushNotificationReceived"`).
3. Check device logs for `[NativePush] Rejected event class` (iOS) or `NativePush: Rejected event class` (Android Logcat).

### iOS push not received on TestFlight / App Store

Set `APS_ENVIRONMENT=production` in your build environment. The `development` value only works with directly installed (non-distribution) builds.

### Is it safe to add any class to `allowed_events`?

Only add your own **event** classes that accept `__construct(array $data = [])` and do harmless work inside the ephemeral runtime. Avoid adding Eloquent models, commands, or anything whose constructor has side-effects beyond reading `$data`.

---

## License

MIT.
