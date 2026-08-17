import Foundation
import UIKit
import UserNotifications
import FirebaseMessaging

/// Bridges APNs/FCM into core. Holds the enrollment id + event class set by
/// PushNotification.RequestPermission.
///
/// HARDENED vs upstream (fatlum/nativephp-push) — see CHANGES.md for the full
/// writeup. `dispatchToPHP()` is the single choke point every dispatch path
/// (token generation AND incoming FCM messages) already ran through in
/// upstream too, which makes it the right place for one validation check
/// that covers both.
///
/// Upstream escaped backslashes in `eventClass` before embedding it in a
/// quoted artisan command-line argument, but did NOT escape the actual quote
/// delimiter character — meaning a class name containing a single quote
/// (fully attacker-controlled for incoming messages, since it comes straight
/// from the push payload's `event` field on a leaked/compromised sender
/// credential) could still break out of the quoting and inject additional
/// CLI arguments. Fix: validate against a strict FQCN character pattern
/// before touching the command string at all, rather than trying to escape
/// correctly after the fact.
final class PushObserver: NSObject, MessagingDelegate, UNUserNotificationCenterDelegate {

    static let shared = PushObserver()

    private static let defaultTokenEvent = "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"

    /// Conservative FQCN pattern: letters, digits, underscores, backslash
    /// namespace separators only — no quotes, no shell metacharacters.
    private static let fqcnPattern = try! NSRegularExpression(
        pattern: #"^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$"#
    )

    private static func isValidEventClassName(_ s: String) -> Bool {
        let range = NSRange(s.startIndex..<s.endIndex, in: s)
        return fqcnPattern.firstMatch(in: s, range: range) != nil
    }

    var enrollmentId: String?
    var tokenEventClass: String = PushObserver.defaultTokenEvent
    private(set) var cachedToken: String?

    // MARK: APNs token (from core's NotificationCenter broadcast)

    @objc func onApnsToken(_ note: Notification) {
        guard let deviceToken = note.userInfo?["deviceToken"] as? Data else { return }
        // Hand the APNs token to Firebase so it can mint an FCM token.
        Messaging.messaging().apnsToken = deviceToken
    }

    // MARK: FCM token

    func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        guard let token = fcmToken else { return }
        cachedToken = token
        dispatchToPHP(tokenEventClass, payload: ["token": token, "id": enrollmentId ?? NSNull()])
    }

    // MARK: Incoming pushes (silent/data via core broadcast)

    @objc func onRemoteNotification(_ note: Notification) {
        guard let userInfo = note.userInfo?["payload"] as? [AnyHashable: Any] else { return }
        handlePush(userInfo)
    }

    // Foreground presentation + taps.
    func userNotificationCenter(_ center: UNUserNotificationCenter,
                                willPresent notification: UNNotification,
                                withCompletionHandler completionHandler:
                                    @escaping (UNNotificationPresentationOptions) -> Void) {
        handlePush(notification.request.content.userInfo)
        completionHandler([.banner, .badge, .sound])
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter,
                                didReceive response: UNNotificationResponse,
                                withCompletionHandler completionHandler: @escaping () -> Void) {
        handlePush(response.notification.request.content.userInfo)
        completionHandler()
    }

    // MARK: Dispatch

    private func handlePush(_ userInfo: [AnyHashable: Any]) {
        // Only data messages naming an `event` class trigger PHP (mirrors the paid plugin).
        guard let eventClass = userInfo["event"] as? String else { return }

        var data: [String: Any] = [:]
        for (key, value) in userInfo {
            if let k = key as? String, k != "event", k != "aps" {
                data[k] = value
            }
        }
        dispatchToPHP(eventClass, payload: ["data": data])
    }

    /// Foreground: dispatch live through the web view. Background: run the
    /// dispatch artisan command in the on-device PHP runtime.
    private func dispatchToPHP(_ eventClass: String, payload: [String: Any]) {
        guard PushObserver.isValidEventClassName(eventClass) else {
            #if DEBUG
            print("[NativePush] Rejected event class — does not match a valid FQCN pattern: \(eventClass)")
            #endif
            return
        }

        let isActive = (UIApplication.shared.applicationState == .active)

        if isActive {
            DispatchQueue.main.async {
                LaravelBridge.shared.send?(eventClass, payload)
            }
            return
        }

        // Background: serialize payload as base64 JSON and dispatch via artisan.
        guard let json = try? JSONSerialization.data(withJSONObject: payload),
              let b64 = String(data: json.base64EncodedData(), encoding: .utf8) else { return }
        // eventClass is already validated above — it cannot contain a quote
        // character, so this interpolation is safe without needing to get
        // manual escaping exactly right (which upstream did not).
        let command = "native:push:dispatch '\(eventClass)' '\(b64)' --base64"
        _ = PersistentPHPRuntime.shared.artisan(command: command)
    }
}
