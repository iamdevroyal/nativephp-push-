package com.iamdevroyal.push

import android.util.Base64
import android.util.Log
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.ui.MainActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/**
 * Sends an event to PHP. While the app is alive (MainActivity.instance != null)
 * it goes through the live web view so mounted Livewire components react. When
 * the app is backgrounded/killed it runs core's ephemeral PHP runtime and fires
 * the event via the `native:push:dispatch` artisan command.
 *
 * HARDENED vs upstream (fatlum/nativephp-push) — see CHANGES.md for the full
 * writeup. This is the single choke point every dispatch path (token
 * generation AND incoming FCM messages) already ran through in upstream too,
 * which makes it the right place to add one validation check that covers
 * both.
 *
 * Upstream took `eventClass` — which, for incoming FCM messages, comes
 * directly from the push payload's `data.event` field, i.e. fully
 * attacker-controlled on a leaked/compromised sender credential — and
 * interpolated it into a quoted artisan command-line argument with no
 * validation and no escaping. A class name containing a single quote could
 * break out of that quoting and inject additional CLI arguments.
 *
 * Fix: validate against a strict FQCN character pattern (letters, digits,
 * underscores, backslash namespace separators — nothing else, definitely no
 * quotes) BEFORE it's used anywhere. This closes the injection vector at the
 * source rather than trying to escape correctly after the fact — escaping
 * is easy to get subtly wrong (see upstream's iOS code, which escaped
 * backslashes but not the actual quote delimiter). This is defense-in-depth
 * on top of, not a replacement for, the PHP-side allow-list check in
 * DispatchPushEventCommand — a class can pass this format check and still be
 * rejected server-side if it's not in push.allowed_events.
 */
object PushDispatch {

    /** Conservative FQCN pattern: letters, digits, underscores, backslashes only. */
    private val FQCN_PATTERN = Regex("^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$")

    fun dispatch(eventClass: String, payload: JSONObject) {
        if (!FQCN_PATTERN.matches(eventClass)) {
            Log.e("NativePush", "Rejected event class — does not match a valid FQCN pattern: $eventClass")
            return
        }

        val activity = MainActivity.instance
        if (activity != null) {
            // Foreground: core's coordinator must run on the main thread (FragmentManager
            // commitNow); FCM callbacks arrive on a background thread, so hop explicitly.
            activity.runOnUiThread {
                NativeActionCoordinator.dispatchEvent(activity, eventClass, payload.toString())
            }
        } else {
            dispatchInBackground(eventClass, payload)
        }
    }

    private fun dispatchInBackground(eventClass: String, payload: JSONObject) {
        val context = PushRuntime.appContext ?: return
        val bridge = PHPBridge(context)

        val b64 = Base64.encodeToString(
            payload.toString().toByteArray(Charsets.UTF_8),
            Base64.NO_WRAP
        )
        // eventClass is already validated against FQCN_PATTERN above — it
        // cannot contain a quote character, so this interpolation is safe
        // regardless of Symfony StringInput's exact tokenization behavior.
        val command = "native:push:dispatch '$eventClass' '$b64' --base64"

        try {
            bridge.nativeRuntimeInit()
            val booted = bridge.nativeEphemeralBoot(
                "${bridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
            )
            if (booted == 0) {
                bridge.nativeEphemeralArtisan(command)
            } else {
                Log.e("NativePush", "Ephemeral boot failed (code=$booted)")
            }
        } catch (e: Throwable) {
            Log.e("NativePush", "Background dispatch failed: ${e.message}")
        } finally {
            try { bridge.nativeEphemeralShutdown() } catch (_: Throwable) {}
        }
    }
}
