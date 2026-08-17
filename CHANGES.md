# Security audit & hardening changes

This document records every change made during the security review of this package.
It is provided as a transparency log for maintainers and consumers.

---

## Critical #1 — Fail-closed event allow-list (was fail-open)

**Affected file:** `src/Commands/DispatchPushEventCommand.php`

The FCM `data.event` field — read verbatim from an incoming push payload — is fully
attacker-controlled on a leaked or compromised sender credential. It flows directly
into dynamic class instantiation:

```php
event(new $event(...$payload));
```

The original default shipped an **empty** `allowed_events` array, which was interpreted
as "allow any class." An unconfigured install silently permitted instantiating any class
reachable by `class_exists()` in the entire application with attacker-controlled
constructor arguments.

**Fix:** The allow-list check is now **fail-closed**. An empty or unconfigured list means
**nothing** is permitted — not everything. The config ships with exactly one class
pre-approved (`PushNotificationReceived`) and a comment requiring explicit additions.

```php
// Before (fail-open):
if (!empty($allowed) && !in_array($event, $allowed, true)) { reject }

// After (fail-closed):
if (! in_array($event, $allowed, true)) { reject }
```

> **Scope note:** this guards the background/ephemeral path only. The foreground path
> runs through NativePHP core's `POST /_native/api/events` route, which this plugin
> cannot gate. Verify core's own protections for that surface independently.

---

## Critical #2 — Native-side FQCN validation (argument injection)

**Affected files:**
- `resources/android/src/PushDispatch.kt`
- `resources/ios/Sources/PushObserver.swift`

Both platforms built the artisan command string by interpolating `eventClass` (attacker-
controlled for incoming messages) directly into a single-quoted argument with no
validation. The iOS code escaped backslashes but **not** the quote delimiter itself —
a class name containing `'` could break out of the quoting and inject additional CLI
tokens. Android performed no escaping at all.

**Fix:** `eventClass` is now validated against a strict FQCN pattern before it touches
any command string on both platforms:

```
^[A-Za-z_][A-Za-z0-9_]*(\[A-Za-z_][A-Za-z0-9_]*)*$
```

Letters, digits, underscores, backslash namespace separators — nothing else. Anything
that does not match is rejected and logged. The same pattern is enforced again in
`DispatchPushEventCommand` (PHP) as independent defense-in-depth.

---

## Medium — Namespace and package branding

**Affected files:** all source files

The original package used inconsistent internal naming (leftover from an apparent
rename). All namespaces, package identifiers, and class references have been updated
to use consistent `Iamdevroyal\NativePush` (PHP) and `com.iamdevroyal.push` (Android)
identifiers throughout.

---

## Left unchanged — was sound on review

- **iOS bounded timeouts** (`CheckPermission` 2s, `GetToken` 3s) — correct.
- **`FcmSender` / `FcmMessage`** — server-side, developer-authored code. No logic
  changes beyond namespace update.
- **`CopyFirebaseAssetsCommand`** — straightforward file copy, no issues.
- **`resources/js/push.js`** — the JS helpers were correct as shipped. The
  vulnerabilities were all in the native dispatch path and the PHP allow-list default.

---

## Verification checklist

- [ ] Real-device test: send a push with a deliberately malformed `event` field
      (containing `'` or a class not in the allow-list) and confirm clean rejection
      on both platforms without crash or hang.
- [ ] Confirm the FQCN regex does not reject any event class name you actually use.
- [ ] Confirm NativePHP core's `POST /_native/api/events` (foreground path) has its
      own allow-list or validation mechanism — this plugin cannot cover that surface.
- [ ] Confirm `getToken()`'s 3-second native-side timeout is sufficient in practice;
      add JS-side retry logic if needed.
