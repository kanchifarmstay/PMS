<?php
declare(strict_types=1);

/** Runtime configuration. Secrets come from the hosting environment. */
function kfsEnv(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : trim((string)$value);
}

define('APP_ENV', kfsEnv('KFS_APP_ENV', 'production'));
$propertyTimezone = kfsEnv('KFS_PROPERTY_TIMEZONE', 'Asia/Kolkata');
if (!in_array($propertyTimezone, DateTimeZone::listIdentifiers(), true)) $propertyTimezone = 'Asia/Kolkata';
define('PROPERTY_TIMEZONE', $propertyTimezone);
date_default_timezone_set(PROPERTY_TIMEZONE);
define('DB_PATH', kfsEnv('KFS_DB_PATH', __DIR__ . '/calendar.db'));
define('SITE_URL', rtrim(kfsEnv('KFS_SITE_URL', 'https://kanchifarmstay.com'), '/'));
define('ADMIN_PASSWORD_HASH', kfsEnv('KFS_ADMIN_PASSWORD_HASH'));
define('ICAL_TOKEN', kfsEnv('KFS_ICAL_TOKEN'));
define('DOCUMENT_SIGNING_SECRET', kfsEnv('KFS_DOCUMENT_SIGNING_SECRET'));
define('CRON_SECRET', kfsEnv('KFS_CRON_SECRET'));
define('RAZORPAY_KEY_ID', kfsEnv('KFS_RAZORPAY_KEY_ID'));
define('RAZORPAY_KEY_SECRET', kfsEnv('KFS_RAZORPAY_KEY_SECRET'));
define('RAZORPAY_WEBHOOK_SECRET', kfsEnv('KFS_RAZORPAY_WEBHOOK_SECRET'));
define('WHATSAPP_PROVIDER', kfsEnv('KFS_WHATSAPP_PROVIDER', 'none'));
define('WHATSAPP_PHONE', kfsEnv('KFS_WHATSAPP_PHONE'));
define('CALLMEBOT_API_KEY', kfsEnv('KFS_CALLMEBOT_API_KEY'));
define('META_WA_TOKEN', kfsEnv('KFS_META_WA_TOKEN'));
define('META_WA_PHONE_ID', kfsEnv('KFS_META_WA_PHONE_ID'));
define('META_WA_VERIFY_TOKEN', kfsEnv('KFS_META_WA_VERIFY_TOKEN'));
define('BANK_NAME', kfsEnv('KFS_BANK_NAME'));
define('BANK_ACCOUNT_NAME', kfsEnv('KFS_BANK_ACCOUNT_NAME'));
define('BANK_ACCOUNT_NO', kfsEnv('KFS_BANK_ACCOUNT_NO'));
define('BANK_IFSC', kfsEnv('KFS_BANK_IFSC'));
define('UPI_ID', kfsEnv('KFS_UPI_ID'));

define('OTA_COMMISSIONS', [
    'airbnb'=>15, 'booking.com'=>15, 'booking'=>15, 'agoda'=>15,
    'makemytrip'=>11, 'razorpay'=>2, 'direct'=>0, 'manual'=>0, 'blocked'=>0,
]);

define('ROOM_IDS', [
    'wooden-villa'=>'Wooden Villa',
    'white-villa'=>'White Villa — Room 1',
    'white-villa-room-2'=>'White Villa — Room 2',
    'white-villa-full-floor'=>'White Villa — Full 1st Floor',
    'natures-nest'=>"Nature's Nest",
    'tranquil-retreat'=>'Tranquil Retreat',
    'wooden-cottage'=>'Wooden Cottage',
    'kanchi-farm-stay'=>'KanchiFarmStay (Group Booking)',
    'tent'=>'Tent',
    'tree-house'=>'Tree House',
]);

define('ROOM_PRICING', [
    'wooden-villa'=>['weekday'=>3000,'weekend'=>3000,'base_adults'=>2,'base_children'=>1,'max_adults'=>4,'max_children'=>2],
    'white-villa'=>['weekday'=>2000,'weekend'=>2000,'base_adults'=>2,'base_children'=>1,'max_adults'=>4,'max_children'=>2],
    'white-villa-room-2'=>['weekday'=>1000,'weekend'=>1000,'base_adults'=>2,'base_children'=>1,'max_adults'=>4,'max_children'=>2],
    'white-villa-full-floor'=>['weekday'=>2500,'weekend'=>2500,'base_adults'=>4,'base_children'=>2,'max_adults'=>6,'max_children'=>3],
    'natures-nest'=>['weekday'=>2500,'weekend'=>2500,'base_adults'=>2,'base_children'=>1,'max_adults'=>5,'max_children'=>3],
    'tranquil-retreat'=>['weekday'=>2500,'weekend'=>2500,'base_adults'=>2,'base_children'=>1,'max_adults'=>5,'max_children'=>3],
    'wooden-cottage'=>['weekday'=>3000,'weekend'=>3000,'base_adults'=>2,'base_children'=>1,'max_adults'=>4,'max_children'=>2],
    'kanchi-farm-stay'=>['weekday'=>8000,'weekend'=>8000,'base_adults'=>10,'base_children'=>4,'max_adults'=>15,'max_children'=>6],
    'tent'=>['weekday'=>500,'weekend'=>500,'base_adults'=>2,'base_children'=>0,'max_adults'=>2,'max_children'=>1],
    'tree-house'=>['weekday'=>2000,'weekend'=>2000,'base_adults'=>2,'base_children'=>1,'max_adults'=>4,'max_children'=>2],
]);

define('EXTRA_ADULT_RATE', 800);
define('EXTRA_CHILD_RATE', 500);
define('WEEKEND_ISO_DAYS', [5, 6]);
define('PAYMENT_HOLD_MINUTES', 15);
define('INVENTORY_COMPONENTS', [
    'white-villa-full-floor'=>['white-villa', 'white-villa-room-2'],
    'kanchi-farm-stay'=>array_values(array_filter(array_keys(ROOM_IDS), static fn(string $id): bool => $id !== 'kanchi-farm-stay')),
]);
define('SUPPORTED_ICAL_PLATFORMS', ['airbnb', 'booking.com', 'agoda', 'makemytrip']);
