<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking-service.php';
require_once __DIR__ . '/api.php';

function verifyRazorpayPaymentSignature(string $orderId, string $paymentId, string $signature, ?string $secret = null): bool
{
    $secret ??= RAZORPAY_KEY_SECRET;
    if ($orderId === '' || $paymentId === '' || $signature === '' || $secret === '') return false;
    return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
}

function verifyRazorpayWebhookSignature(string $rawPayload, string $signature, ?string $secret = null): bool
{
    $secret ??= RAZORPAY_WEBHOOK_SECRET;
    if ($rawPayload === '' || $signature === '' || $secret === '') return false;
    return hash_equals(hash_hmac('sha256', $rawPayload, $secret), $signature);
}

function requestRazorpayOrder(array $payload): array
{
    if (!paymentIsConfigured()) throw new RuntimeException('Online payment is not configured.');
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_USERPWD=>RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) throw new RuntimeException($error ?: 'Payment provider request failed.');
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        throw new RuntimeException((string)($decoded['error']['description'] ?? 'Payment provider rejected the order.'));
    }
    return $decoded;
}

function createRazorpayBookingOrder(array $input, ?callable $client = null): array
{
    if (!paymentIsConfigured()) throw new RuntimeException('Online payment is not configured.');
    $roomId = trim((string)($input['roomId'] ?? ''));
    $checkIn = trim((string)($input['checkIn'] ?? $input['checkin'] ?? ''));
    $checkOut = trim((string)($input['checkOut'] ?? $input['checkout'] ?? ''));
    $guestName = trim((string)($input['guestName'] ?? ''));
    $guestEmail = trim((string)($input['guestEmail'] ?? ''));
    $guestPhone = trim((string)($input['guestPhone'] ?? ''));
    $adults = (int)($input['adults'] ?? 1);
    $children = (int)($input['children'] ?? 0);
    if ($guestName === '' || mb_strlen($guestName) > 120) throw new InvalidArgumentException('A valid guest name is required.');
    if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('A valid email is required.');
    if (!preg_match('/^\+?[0-9 ()\-.]{7,20}$/', $guestPhone)) throw new InvalidArgumentException('A valid phone number is required.');
    $quote = calculateQuote($roomId, $checkIn, $checkOut, $adults, $children);
    $hold = createBookingHold([
        'room_id'=>$roomId, 'check_in'=>$checkIn, 'check_out'=>$checkOut,
        'guest_name'=>$guestName, 'guest_email'=>$guestEmail, 'guest_phone'=>$guestPhone,
        'adults'=>$adults, 'children'=>$children, 'amount'=>$quote['total'],
    ]);
    try {
        $amountPaise = (int)round($quote['total'] * 100);
        $payload = [
            'amount'=>$amountPaise, 'currency'=>'INR',
            'receipt'=>'kfs-' . substr($hold['token'], 0, 24),
            'notes'=>['hold_token'=>$hold['token'], 'room_id'=>$roomId, 'check_in'=>$checkIn, 'check_out'=>$checkOut],
        ];
        $client ??= 'requestRazorpayOrder';
        $providerOrder = $client($payload);
        if (!is_array($providerOrder) || empty($providerOrder['id'])) throw new RuntimeException('Invalid payment provider response.');
        getDB()->prepare("INSERT INTO payment_orders (order_id, hold_token, amount_paise, currency) VALUES (?,?,?,'INR')")
            ->execute([(string)$providerOrder['id'], $hold['token'], $amountPaise]);
        return [
            'id'=>(string)$providerOrder['id'], 'amount'=>$amountPaise, 'currency'=>'INR',
            'keyId'=>RAZORPAY_KEY_ID, 'holdToken'=>$hold['token'], 'expiresAt'=>$hold['expires_at'], 'quote'=>$quote,
        ];
    } catch (Throwable $e) {
        releaseBookingHold($hold['token']);
        throw $e;
    }
}

function finalizeVerifiedRazorpayPayment(string $orderId, string $paymentId): int
{
    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare("SELECT po.*, h.room_id, h.check_in, h.check_out, h.guest_name, h.guest_email,
                h.guest_phone, h.adults, h.children, h.amount, h.status AS hold_status, h.expires_at
            FROM payment_orders po JOIN booking_holds h ON h.token=po.hold_token WHERE po.order_id=?");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('Unknown payment order.');
        if ($row['status'] === 'paid' && (int)$row['booking_id'] > 0) {
            if ($row['payment_id'] !== $paymentId) throw new DomainException('Payment does not match the order.');
            $db->exec('COMMIT');
            return (int)$row['booking_id'];
        }
        if ($row['hold_status'] !== 'pending' || $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new DomainException('The payment hold has expired. Contact the property for reconciliation.');
        }
        if ((int)$row['amount_paise'] !== (int)round((float)$row['amount'] * 100)) {
            throw new DomainException('Payment amount does not match the server quote.');
        }
        if (!isInventoryAvailable($row['room_id'], $row['check_in'], $row['check_out'], null, $row['hold_token'], $db)) {
            throw new DomainException('Inventory conflict detected during payment confirmation.');
        }
        $uid = 'rzp-' . hash('sha256', $paymentId) . '@kanchifarmstay.com';
        $insert = $db->prepare("INSERT INTO bookings
            (room_id, room_name, check_in, check_out, guest_name, guest_email, guest_phone,
             source, booking_ref, amount, amount_paid, payment_method, payment_status, status, uid, notes, is_sync_imported)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)");
        $insert->execute([
            $row['room_id'], ROOM_IDS[$row['room_id']], $row['check_in'], $row['check_out'],
            $row['guest_name'], $row['guest_email'], $row['guest_phone'], 'direct', $paymentId,
            (float)$row['amount'], (float)$row['amount'], 'online', 'paid', 'confirmed', $uid,
            'Verified Razorpay order: ' . $orderId,
        ]);
        $bookingId = (int)$db->lastInsertId();
        $db->prepare("UPDATE booking_holds SET status='confirmed', updated_at=datetime('now') WHERE token=?")
            ->execute([$row['hold_token']]);
        $db->prepare("UPDATE payment_orders SET payment_id=?, status='paid', signature_verified=1,
                booking_id=?, last_error='', updated_at=datetime('now') WHERE order_id=?")
            ->execute([$paymentId, $bookingId, $orderId]);
        $db->exec('COMMIT');
        return $bookingId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function confirmRazorpayPayment(string $orderId, string $paymentId, string $signature): int
{
    if (!verifyRazorpayPaymentSignature($orderId, $paymentId, $signature)) {
        throw new DomainException('Payment signature verification failed.');
    }
    return finalizeVerifiedRazorpayPayment($orderId, $paymentId);
}
