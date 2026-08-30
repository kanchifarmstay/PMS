# Production deployment

## Before uploading

1. Back up the current website files and SQLite database. Keep the database backup outside the web root.
2. Record the current release commit so rollback is unambiguous.
3. Configure every required variable listed in `.env.example` in the hosting environment. Use different random values for the iCal, cron, and document-signing secrets.
4. Rotate any credential that was ever stored in source code, including old iCal feed tokens and Razorpay keys.

## Deploy and migrate

1. Put the site in a short maintenance window or prevent booking writes during the file/database switch.
2. Upload the release without copying a development `calendar.db` over production.
3. Point `KFS_DB_PATH` at the production database. The first request runs additive SQLite migrations; keep the backup until validation is complete.
4. Confirm Apache honors both `.htaccess` files. Requests for `channel-manager/calendar.db`, `.env`, `cron.log`, and `/tests/` must return 403/404.
5. Configure the CLI cron every 15–30 minutes:

   ```text
   php /absolute/path/to/channel-manager/cron.php
   ```

6. Configure Razorpay's webhook as `https://kanchifarmstay.com/razorpay-webhook.php` using `KFS_RAZORPAY_WEBHOOK_SECRET`.

## OTA cutover

1. In the PMS **Channels** screen, replace each saved Airbnb, Booking.com, Agoda, and MakeMyTrip export URL with the current URL for the exact inventory item.
2. Run a manual sync and verify its status before replacing outbound feeds.
3. In **iCal Export**, copy the feed under the correct destination heading. Replace the old imported URL in that OTA; never reuse one destination's feed for another.
4. Test one future block per OTA. Verify component rooms, White Villa Full Floor, and whole-property inventory propagate as documented.
5. Remove the old OTA-imported feed only after the new feed has refreshed successfully.

## Acceptance checks

- Admin login, logout, CSRF-protected mutation, and manual booking conflict rejection.
- A room-page quote with adults/children, an unavailable-date rejection, and a Razorpay test-mode payment/webhook.
- iCal import of known Airbnb/Booking.com unavailable events and privacy-safe destination exports.
- Two cron invocations started together: one runs and the other reports `busy`.
- The verification commands in `README.md` all exit successfully.

## Rollback

1. Stop cron and temporarily disable direct payment initiation.
2. Restore the previous website release and its matching database backup as one unit.
3. Restore the prior environment-variable set without reintroducing any revoked credentials.
4. Restore the previous OTA feed URLs only if the prior release requires them, then run a manual sync and verify availability before reopening bookings.
