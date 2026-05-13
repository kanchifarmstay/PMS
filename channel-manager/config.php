<?php
// ============================================================
// KANCHI FARM STAY — CHANNEL MANAGER CONFIGURATION
// Edit the values below before going live.
// ============================================================

// Admin password to access /channel-manager/admin.php
define('ADMIN_PASSWORD', 'KanchiFarm2025!');

// Path to the SQLite database (auto-created on first run)
define('DB_PATH', __DIR__ . '/calendar.db');

// Your website URL — no trailing slash
define('SITE_URL', 'https://kanchifarmstay.com');

// Secret token appended to iCal export URLs.
// Change this to any random string — it prevents random people
// from easily discovering your calendar feed URLs.
define('ICAL_TOKEN', 'ksf-ical-secret-2025');

// ============================================================
// WHATSAPP NOTIFICATIONS  (powered by CallMeBot — free)
// ============================================================
// How to get your CallMeBot API key:
//   1. Save this number in your contacts: +34 644 69 07 99  (name it "CallMeBot")
//   2. Open WhatsApp and send that contact: I allow callmebot to send me messages
//   3. Within seconds you'll receive a WhatsApp reply with your API key.
//
// Set WHATSAPP_PROVIDER to 'none' to disable notifications entirely.
//
define('WHATSAPP_PROVIDER', 'callmebot');         // 'callmebot' | 'none'
define('WHATSAPP_PHONE',    '916383726094');       // country code + number, no + sign
define('CALLMEBOT_API_KEY', 'YOUR_API_KEY_HERE'); // ⚠️ Replace with your CallMeBot key — see setup-wizard.php

// ============================================================
// OTA COMMISSION RATES (percentage)
// ============================================================
define('OTA_COMMISSIONS', [
    'airbnb'      => 15,
    'booking.com' => 15,
    'booking'     => 15,
    'agoda'       => 15,
    'makemytrip'  => 11,
    'razorpay'    => 2,
    'direct'      => 0,
    'manual'      => 0,
    'blocked'     => 0,
]);

// ============================================================
// META WHATSAPP BUSINESS API  (optional — leave blank to keep using CallMeBot)
// Set these to use the full 2-way WhatsApp Inbox feature.
// ============================================================
define('META_WA_TOKEN',    '');   // Bearer token from Meta Developer Console
define('META_WA_PHONE_ID','');   // Phone number ID from Meta WhatsApp API
define('META_WA_VERIFY_TOKEN', 'kfs-webhook-2025'); // Webhook verify token (set same in Meta console)
define('CRON_SECRET',         'kanchi-cron-2025'); // Token to trigger sync via URL (keep private)

// ============================================================
// BANK / PAYMENT DETAILS  (shown on booking confirmation PDF)
// ============================================================
define('BANK_NAME',       'Kotak Mahindra Bank');
define('BANK_ACCOUNT_NAME','Kanchi Farm Stay');
define('BANK_ACCOUNT_NO', '000000123456');
define('BANK_IFSC',       'KKBK00001234');
define('UPI_ID',          '6383726094@Kanchi');

// ============================================================
// ROOMS — must match the IDs in script.js
// ============================================================
define('ROOM_IDS', [
    'wooden-villa'          => 'Wooden Villa',
    'white-villa'           => 'White Villa — Room 1',
    'white-villa-room-2'    => 'White Villa — Room 2',
    'white-villa-full-floor'=> 'White Villa — Full 1st Floor',
    'natures-nest'          => "Nature's Nest",
    'tranquil-retreat'      => 'Tranquil Retreat',
    'wooden-cottage'        => 'Wooden Cottage',
    'kanchi-farm-stay'      => 'KanchiFarmStay (Group Booking)',
    'tent'                  => 'Tent',
    'tree-house'            => 'Tree House',
]);
