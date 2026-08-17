<?php

namespace Iamdevroyal\NativePush\Commands;

use Illuminate\Console\Command;

/**
 * Fires a Laravel event from the on-device ephemeral PHP runtime when a data
 * message arrives while the app is backgrounded or killed.
 *
 * The native PushMessagingService (Android) / push observer (iOS) invokes this
 * via the ephemeral runtime, e.g.:
 *
 *   nativeEphemeralArtisan("native:push:dispatch 'App\\Events\\Sync' <base64> --base64")
 *
 * It mirrors core's DispatchEventFromAppController: the decoded payload is spread
 * into the event constructor, so push `data` keys map to named constructor args.
 *
 * HARDENED vs upstream (fatlum/nativephp-push) — see CHANGES.md for the full
 * writeup:
 *   1. push.allowed_events is now FAIL-CLOSED by default (empty/missing
 *      config = allow NOTHING, not "allow anything"). Upstream's inverse
 *      logic (`if (!empty($allowed) && !in_array(...))`) meant an
 *      unconfigured install silently allowed instantiating any class in the
 *      app with attacker-controlled constructor args, since $event is read
 *      directly from an incoming push payload's `data.event` field — fully
 *      attacker-controlled on a leaked/compromised sender credential.
 *   2. The $event string is validated against a strict FQCN character
 *      pattern before anything else happens with it — defense in depth in
 *      case it's ever used in a context that could otherwise be abused
 *      (e.g. reflected into a shell-like string elsewhere), even though
 *      this command itself only uses it for class_exists()/instantiation.
 */
class DispatchPushEventCommand extends Command
{
    protected $signature = 'native:push:dispatch {event : Fully-qualified event class} {payload? : JSON payload} {--base64 : Payload is base64-encoded JSON}';

    protected $description = 'Dispatch a Laravel event from a background push (used by the on-device runtime).';

    /** Conservative FQCN pattern: letters, digits, underscores, backslashes only. */
    private const FQCN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    public function handle(): int
    {
        $event = $this->argument('event');
        $raw = $this->argument('payload') ?? '[]';

        if (! preg_match(self::FQCN_PATTERN, $event)) {
            $this->error("Rejected event class — does not match a valid FQCN pattern: {$event}");

            return self::FAILURE;
        }

        if ($this->option('base64')) {
            $raw = base64_decode($raw, strict: true);
            if ($raw === false) {
                $this->error('Payload failed strict base64 decoding.');

                return self::FAILURE;
            }
        }

        if (! class_exists($event)) {
            $this->error("Event class does not exist: {$event}");

            return self::FAILURE;
        }

        // FAIL-CLOSED: an empty/unconfigured allow-list means nothing is
        // permitted, not everything. This is the inverse of upstream's
        // default and is the single most important change in this fork.
        $allowed = config('push.allowed_events', []);
        if (! in_array($event, $allowed, true)) {
            $this->error("Event not in push.allowed_events allow-list (default-deny): {$event}");

            return self::FAILURE;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $payload = [];
        }

        // Spread named args (assoc keys) exactly like core's HTTP dispatch route.
        event(new $event(...$payload));

        return self::SUCCESS;
    }
}
