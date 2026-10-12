# Membership payments (Apple / Google billing)

**Status: the plumbing is built and tested with a fake store; no real store adapter ships and nothing here has been run
against Apple or Google.** Selling a subscription for real needs store accounts, signed builds and server credentials
that do not exist in this repository. This page says exactly what is done and what each store still requires.

## What is built

| Piece | Where | Tested |
| --- | --- | --- |
| Plans, entitlements, subscriptions, audit trail (`subscription_events`) | `MembershipPlan`, `Subscription`, `SubscriptionEvent`, `App\Support\Entitlement` | yes (`MembershipTest`, `MembershipAdminTest`) |
| Server-side enforcement on every protected endpoint (`entitled:<key>`), also inside `POST /sync/batch` | `EnsureEntitled`, `routes/api_v1.php`, `SyncBatchService` | yes (`SecurityTest` walks every entitled route) |
| `PaymentVerifier` contract + registry (a store is "available" only when its verifier reports credentials configured) | `app/Services/Membership/*` | yes, against `tests/Support/FakePaymentVerifier.php` |
| `POST /api/v1/membership/purchases/verify` and `/restore` | `MembershipController` | yes |
| Expiry, grace and reminders (`memberships:expire`, scheduled) | `ExpireMemberships` | yes |
| Admin UI: plans, entitlements, price and store product ids, manual grant/revoke, subscription list | `Admin\MembershipPlanController`, `Admin\SubscriptionController` | yes |

## What is not built

- `AppleStoreVerifier` and `GooglePlayVerifier`. `config/ironpulse.php` → `payments.verifiers` has both lines commented
  out. Until a verifier is registered and configured, `GET /membership` lists no store in
  `payments.available_providers`. The Flutter paywall (not written yet) must key its purchase buttons off that list so
  there is no dead purchase control: with no provider available it shows plan contents only.
- Store server notifications (Apple App Store Server Notifications V2, Google Real-time Developer Notifications).
  Without them, renewals, refunds and cancellations are only noticed when the app next calls `/membership/restore` or
  `memberships:expire` finds the period over. The `eventId` on `VerifiedPurchase` and the `subscription_events`
  table are ready for a webhook controller; the controller itself is not written.

## Rules the code already follows

1. **The client is untrusted.** `/purchases/verify` accepts `provider`, `product_id`, the opaque `receipt` and an
   optional `transaction_id`. It ignores any price, currency, "success" flag or expiry the client sends
   (`MembershipTest`: "it ignores anything the client says about price").
2. **The store decides.** A `VerifiedPurchase` (state, period end, trial end, auto-renew) comes only from a verifier.
   A product id that belongs to no plan is rejected; a receipt whose product differs from the request is rejected.
3. **One purchase, one account.** A store transaction id that is already attached to another member is refused
   (`409 purchase_already_claimed`), so a receipt cannot be shared.
4. **Access follows timestamps.** `ends_at` is what grants access; a `revoked`/`expired` store state removes it.
5. **A store outage is retryable**, not a refusal: the verifier throws `PaymentVerificationException(retryable: true)`
   and the API answers `503 payments_unreachable`; a refusal is `422 purchase_invalid`. No access is granted or
   removed on a guess.
6. **No secrets in the app.** Store shared secrets, the Apple private key and the Google service account stay on the
   server (`.env` / a file outside `public/`).

## Store rules the product has to respect

- **Digital memberships bought inside the iOS/Android app must use Apple In-App Purchase / Google Play Billing.**
  Do not link out to a web checkout for the same digital content from the iOS app, and do not show prices that
  differ from the store's. The paywall must show the **store-localised price returned by the store SDK**, not the
  admin's `price_minor`/`currency` (those are for admin screens and for plans sold at the gym).
- A **Restore purchases** action is mandatory on iOS (it calls `/membership/restore`).
- Subscriptions need auto-renew disclosure, a link to the terms and privacy policy, and a cancel/manage link
  (`https://apps.apple.com/account/subscriptions`, `https://play.google.com/store/account/subscriptions`).
- Memberships sold at the gym or on the web and merely *used* in the app (a "reader" style app) are a separate store
  policy question; confirm the current guideline wording with each store before choosing that model.

## To go live, per store

### Apple (App Store Connect)

1. Apple Developer Program membership; app record; bundle id; "In-App Purchase" capability.
2. Create one auto-renewable subscription per paid plan; put its **product id** in the plan (Admin → Memberships →
   edit plan → *Store products* → *Apple product id*).
3. Create an **App Store Connect API key** (In-App Purchase key) → server needs `issuer id`, `key id`, `.p8` private
   key and the bundle id. Implement `AppleStoreVerifier` on the App Store Server API (`/inApps/v1/transactions/{id}`,
   signed JWS verification against Apple's root certificate chain).
4. Configure App Store Server Notifications V2 → add a webhook controller that feeds `MembershipService`.
5. Test with sandbox testers and StoreKit configuration files on a physical iPhone.

### Google (Play Console)

1. Play Console developer account; app record; upload an AAB to an internal test track (required before products
   can be tested).
2. Create subscriptions with base plans; put the **product id** in the plan (*Store products* → *Google Play product id*).
3. Create a service account with Android Publisher API access, grant it "View financial data / Manage orders" in the
   Play Console. Server needs the service-account JSON. Implement `GooglePlayVerifier` on
   `purchases.subscriptionsv2.get` and acknowledge purchases (unacknowledged purchases are refunded after 3 days).
4. Configure Real-time Developer Notifications (Pub/Sub) → webhook controller.
5. Test with licence testers on a device.

## How to add a store adapter

```php
// config/ironpulse.php
'payments' => ['verifiers' => ['apple' => \App\Services\Membership\AppleStoreVerifier::class]],
```

Implement `PaymentVerifier`: `provider()`, `isConfigured()` (true only when the credentials exist, so the store stays
hidden otherwise) and `verify(array $payload): VerifiedPurchase`. Map the store's states onto
`active | trialing | canceled | expired | revoked`. Copy the assertions in `MembershipTest` ("store purchases") for the
new class, using `Http::fake()` for the store's responses; do not claim it works until it has run against the store's
sandbox.
