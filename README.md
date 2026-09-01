# Kanchi Farm Stay PMS

Website, direct-booking checkout, and an iCal-based property-management system for Airbnb, Booking.com, Agoda, and MakeMyTrip.

## Production configuration

On Hostinger shared hosting, copy `.env.example` to
`/home/<user>/domains/kanchifarmstay.com/kfs.env`, replace every placeholder,
and keep that file outside `public_html` and Git. The application finds this
domain-root file automatically for both Apache requests and CLI cron. Hosts
with native environment management can set the same variables directly; an
explicit `KFS_ENV_FILE` path overrides the default file location.

```text
KFS_APP_ENV=production
KFS_PROPERTY_TIMEZONE=Asia/Kolkata
KFS_SITE_URL=https://kanchifarmstay.com
KFS_DB_PATH=/home/<user>/domains/kanchifarmstay.com/private/calendar.db
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

Create `/home/<user>/domains/kanchifarmstay.com/private/` and keep the live
database there. Give `kfs.env` and the private directory owner-only permissions
where Hostinger permits it. A required, malformed, or unreadable private config
fails startup instead of silently creating a new database under `public_html`.
The included `.htaccess` also denies direct access to database, log, test, and
maintenance files as defence in depth.

## iCal setup

1. Sign in at `/channel-manager/admin.php` and open **Channels**.
2. Add each OTA export URL against the exact room/inventory item it represents.
3. Open **iCal Export**. Copy the URL from the section for the destination OTA; do not reuse an Airbnb URL for Agoda or Booking.com.
4. Import that destination-specific URL into the corresponding OTA extranet.
5. Run **Sync Now**, then verify a known OTA block appears on both the room and any dependent parent inventory.

A confirmed whole-property booking blocks every component. Individual component inventory blocks the KanchiFarmStay group listing only when at least three distinct component rooms are occupied on the same night; one or two occupied rooms leave the group listing available. White Villa Full Floor and its two component rooms block each other. Destination feeds never send a booking or block back to the OTA it originated from, including through dependent listings; this prevents imported calendar events from circulating through the OTA and returning as false whole-property blocks.

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
