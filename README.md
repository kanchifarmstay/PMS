# Kanchi Farm Stay PMS

Website, direct-booking checkout, and an iCal-based property-management system for Airbnb, Booking.com, Agoda, and MakeMyTrip.

## Production configuration

Set these environment variables in the hosting control panel. Do not put their values in PHP files or Git.

```text
KFS_APP_ENV=production
KFS_PROPERTY_TIMEZONE=Asia/Kolkata
KFS_SITE_URL=https://kanchifarmstay.com
KFS_DB_PATH=/absolute/private/path/calendar.db
KFS_ADMIN_PASSWORD_HASH=<password_hash output>
KFS_ICAL_TOKEN=<long random token>
KFS_CRON_SECRET=<different long random token>
KFS_DOCUMENT_SIGNING_SECRET=<different long random token>
KFS_RAZORPAY_KEY_ID=<Razorpay key id>
KFS_RAZORPAY_KEY_SECRET=<Razorpay key secret>
KFS_RAZORPAY_WEBHOOK_SECRET=<Razorpay webhook secret>
```

Optional notification and payment-display settings are `KFS_WHATSAPP_PROVIDER`, `KFS_WHATSAPP_PHONE`, `KFS_CALLMEBOT_API_KEY`, `KFS_META_WA_TOKEN`, `KFS_META_WA_PHONE_ID`, `KFS_META_WA_VERIFY_TOKEN`, `KFS_BANK_NAME`, `KFS_BANK_ACCOUNT_NAME`, `KFS_BANK_ACCOUNT_NO`, `KFS_BANK_IFSC`, and `KFS_UPI_ID`.

Generate secrets with a cryptographically secure password manager. Generate the admin hash with:

```bash
php -r 'echo password_hash("replace-with-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Keep the database outside `public_html` when the host permits it. The included `.htaccess` also denies direct access to database, log, test, and maintenance files.

## iCal setup

1. Sign in at `/channel-manager/admin.php` and open **Channels**.
2. Add each OTA export URL against the exact room/inventory item it represents.
3. Open **iCal Export**. Copy the URL from the section for the destination OTA; do not reuse an Airbnb URL for Agoda or Booking.com.
4. Import that destination-specific URL into the corresponding OTA extranet.
5. Run **Sync Now**, then verify a known OTA block appears on both the room and any dependent parent inventory.

Whole-property inventory blocks every component. White Villa Full Floor and its two component rooms block each other. A destination feed excludes a block imported from the same OTA for that exact source listing, while still propagating it to dependent listings on that OTA.

## Scheduled sync and payments

Run the cron from CLI every 15–30 minutes:

```text
php /absolute/path/to/channel-manager/cron.php
```

The job uses a non-blocking lock, so overlapping runs exit safely. Configure Razorpay's webhook URL as `/razorpay-webhook.php` and use the same secret in `KFS_RAZORPAY_WEBHOOK_SECRET`.

## Verification

```bash
php tests/run.php
TZ=Asia/Kolkata node --test tests/date-utils.test.js
find channel-manager -maxdepth 1 -name '*.php' -print0 | xargs -0 -n1 php -l
node --check script.js
node --check sw.js
node --check channel-manager/admin-sw.js
```
