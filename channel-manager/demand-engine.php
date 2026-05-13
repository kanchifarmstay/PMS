<?php
/**
 * Demand Engine — KanchiFarmstay
 * Seeds high-demand dates (Muhurathams, festivals, holidays, bridge days)
 * and generates pricing suggestions.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Shubh Muhuratham dates 2025-2026 ──────────────────────────
// Source: Tamil Panchangam — Wedding/Muhurathams
function getMuhurathamDates(): array {
    return [
        // 2025
        '2025-04-20'=>'Muhuratham','2025-04-21'=>'Muhuratham','2025-04-22'=>'Muhuratham',
        '2025-04-25'=>'Muhuratham','2025-04-26'=>'Muhuratham','2025-04-27'=>'Muhuratham',
        '2025-05-10'=>'Muhuratham','2025-05-11'=>'Muhuratham','2025-05-15'=>'Muhuratham',
        '2025-05-16'=>'Muhuratham','2025-05-18'=>'Muhuratham','2025-05-22'=>'Muhuratham',
        '2025-06-08'=>'Muhuratham','2025-06-12'=>'Muhuratham','2025-06-13'=>'Muhuratham',
        '2025-06-14'=>'Muhuratham','2025-06-15'=>'Muhuratham',
        // 2026
        '2026-04-20'=>'Muhuratham','2026-04-21'=>'Muhuratham','2026-04-22'=>'Muhuratham',
        '2026-04-24'=>'Muhuratham','2026-04-26'=>'Muhuratham','2026-04-27'=>'Muhuratham',
        '2026-04-28'=>'Muhuratham',
        '2026-05-04'=>'Muhuratham','2026-05-07'=>'Muhuratham','2026-05-10'=>'Muhuratham',
        '2026-05-11'=>'Muhuratham','2026-05-17'=>'Muhuratham','2026-05-18'=>'Muhuratham',
        '2026-06-19'=>'Muhuratham','2026-06-20'=>'Muhuratham','2026-06-21'=>'Muhuratham',
        '2026-06-22'=>'Muhuratham','2026-06-23'=>'Muhuratham','2026-06-24'=>'Muhuratham',
        '2026-06-25'=>'Muhuratham','2026-06-26'=>'Muhuratham','2026-06-27'=>'Muhuratham',
        '2026-06-28'=>'Muhuratham','2026-06-29'=>'Muhuratham',
        '2026-11-15'=>'Muhuratham','2026-11-16'=>'Muhuratham','2026-11-22'=>'Muhuratham',
        '2026-11-23'=>'Muhuratham','2026-11-29'=>'Muhuratham','2026-11-30'=>'Muhuratham',
        '2026-12-06'=>'Muhuratham','2026-12-07'=>'Muhuratham','2026-12-13'=>'Muhuratham',
        '2026-12-14'=>'Muhuratham',
    ];
}

// ── Kanchipuram & Tamil Nadu festivals ────────────────────────
function getKanchiFestivals(): array {
    return [
        // Kanchipuram-specific
        '2026-05-28' => ['Varadaraja Perumal Festival',         'festival', 'gold'],
        '2026-02-20' => ['Kanchi Kamakshi Brahmotsavam (Start)','festival', 'high'],
        '2026-02-21' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-22' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-23' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-24' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-25' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-26' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-27' => ['Kanchi Kamakshi Brahmotsavam',        'festival', 'high'],
        '2026-02-28' => ['Kanchi Kamakshi Brahmotsavam (End)',  'festival', 'high'],
        '2026-01-14' => ['Pongal',                             'festival', 'gold'],
        '2026-01-15' => ['Pongal (Mattu Pongal)',              'festival', 'high'],
        '2026-01-16' => ['Pongal (Kaanum Pongal)',             'festival', 'high'],
        '2026-04-14' => ['Tamil New Year (Puthandu)',          'festival', 'gold'],
        '2026-10-19' => ['Navaratri (Start)',                  'festival', 'high'],
        '2026-10-20' => ['Navaratri',                         'festival', 'high'],
        '2026-10-21' => ['Navaratri',                         'festival', 'high'],
        '2026-10-22' => ['Navaratri',                         'festival', 'high'],
        '2026-10-23' => ['Navaratri',                         'festival', 'high'],
        '2026-10-24' => ['Navaratri',                         'festival', 'high'],
        '2026-10-25' => ['Navaratri',                         'festival', 'high'],
        '2026-10-26' => ['Navaratri',                         'festival', 'high'],
        '2026-10-27' => ['Vijayadasami',                      'festival', 'gold'],
        '2026-11-05' => ['Deepavali / Diwali',                'festival', 'gold'],
        '2025-01-14' => ['Pongal',                            'festival', 'gold'],
        '2025-04-14' => ['Tamil New Year (Puthandu)',         'festival', 'gold'],
        '2025-10-02' => ['Gandhi Jayanti',                    'holiday',  'medium'],
    ];
}

// ── Indian Public Holidays 2025-2026 ──────────────────────────
function getPublicHolidays(): array {
    return [
        '2025-01-26' => 'Republic Day',
        '2025-03-14' => 'Holi',
        '2025-04-14' => 'Dr. Ambedkar Jayanti',
        '2025-04-18' => 'Good Friday',
        '2025-05-01' => 'Labour Day',
        '2025-08-15' => 'Independence Day',
        '2025-10-02' => 'Gandhi Jayanti',
        '2025-10-02' => 'Gandhi Jayanti',
        '2025-10-20' => 'Dussehra',
        '2025-11-01' => 'Diwali',
        '2025-12-25' => 'Christmas',
        '2026-01-26' => 'Republic Day',
        '2026-03-03' => 'Holi',
        '2026-04-14' => 'Dr. Ambedkar Jayanti / Tamil New Year',
        '2026-04-03' => 'Good Friday',
        '2026-05-01' => 'Labour Day',
        '2026-05-12' => 'Buddha Purnima',
        '2026-08-15' => 'Independence Day',
        '2026-09-02' => 'Ganesh Chaturthi',
        '2026-10-02' => 'Gandhi Jayanti',
        '2026-10-19' => 'Dussehra',
        '2026-11-05' => 'Diwali',
        '2026-12-25' => 'Christmas',
    ];
}

// ── Bridge holiday detection ──────────────────────────────────
function detectBridgeHolidays(array $holidays): array {
    $bridges = [];
    foreach ($holidays as $date => $name) {
        $dow = (int)date('N', strtotime($date)); // 1=Mon…7=Sun
        // Thursday holiday → flag Friday
        if ($dow === 4) {
            $fri = date('Y-m-d', strtotime($date . ' +1 day'));
            $bridges[$fri] = "Bridge Holiday (after $name)";
        }
        // Tuesday holiday → flag Monday before
        if ($dow === 2) {
            $mon = date('Y-m-d', strtotime($date . ' -1 day'));
            $bridges[$mon] = "Bridge Holiday (before $name)";
        }
    }
    return $bridges;
}

// ── Long weekend detection ────────────────────────────────────
function detectLongWeekends(array $holidays): array {
    $lw = [];
    foreach ($holidays as $date => $name) {
        $dow = (int)date('N', strtotime($date));
        // Friday or Monday holiday = long weekend
        if ($dow === 5 || $dow === 1) {
            $lw[$date] = "Long Weekend ($name)";
            // Also flag the adjacent weekend days
            if ($dow === 5) {
                $lw[date('Y-m-d', strtotime($date.' +1 day'))] = 'Long Weekend (Saturday)';
                $lw[date('Y-m-d', strtotime($date.' +2 days'))] = 'Long Weekend (Sunday)';
            }
            if ($dow === 1) {
                $lw[date('Y-m-d', strtotime($date.' -1 day'))] = 'Long Weekend (Sunday)';
                $lw[date('Y-m-d', strtotime($date.' -2 days'))] = 'Long Weekend (Saturday)';
            }
        }
    }
    return $lw;
}

// ── Seed all demand events ─────────────────────────────────────
function seedDemandEvents(): int {
    clearAndReSeedDemandEvents();
    $count = 0;

    // Muhurathams — Gold level
    foreach (getMuhurathamDates() as $date => $label) {
        upsertDemandEvent($date, "Shubh $label", 'muhuratham', 'gold', 'Tamil Panchangam wedding date');
        $count++;
    }

    // Kanchi festivals
    foreach (getKanchiFestivals() as $date => [$name, $type, $level]) {
        upsertDemandEvent($date, $name, $type, $level, 'Kanchipuram / Tamil Nadu festival');
        $count++;
    }

    // Public holidays — medium demand
    $holidays = getPublicHolidays();
    foreach ($holidays as $date => $name) {
        upsertDemandEvent($date, $name, 'holiday', 'medium', 'Indian public holiday');
        $count++;
    }

    // Bridge holidays
    foreach (detectBridgeHolidays($holidays) as $date => $name) {
        upsertDemandEvent($date, $name, 'bridge_holiday', 'high', 'Auto-detected bridge holiday');
        $count++;
    }

    // Long weekends
    foreach (detectLongWeekends($holidays) as $date => $name) {
        upsertDemandEvent($date, $name, 'long_weekend', 'medium', 'Auto-detected long weekend');
        $count++;
    }

    return $count;
}

// ── Generate pricing suggestions ──────────────────────────────
function generatePricingSuggestions(): int {
    $rooms  = ROOM_IDS;
    $count  = 0;

    // Get all room base prices
    $rates = [];
    foreach (getRoomRates() as $r) $rates[$r['room_id']] = (float)$r['base_price'];

    // Demand events from today to +180 days
    $events = getDemandEvents(date('Y-m-d'), date('Y-m-d', strtotime('+180 days')));

    // Group events by date range (consecutive dates of same type)
    $clusters = [];
    $prev = null;
    foreach ($events as $e) {
        if ($prev && $e['event_type'] === $prev['event_type'] && $e['demand_level'] === $prev['demand_level']
            && (strtotime($e['event_date']) - strtotime($prev['event_date'])) <= 86400) {
            $clusters[count($clusters)-1]['date_to'] = $e['event_date'];
            $clusters[count($clusters)-1]['names'][] = $e['event_name'];
        } else {
            $clusters[] = [
                'date_from'    => $e['event_date'],
                'date_to'      => $e['event_date'],
                'event_type'   => $e['event_type'],
                'demand_level' => $e['demand_level'],
                'names'        => [$e['event_name']],
            ];
        }
        $prev = $e;
    }

    // Pricing multipliers
    $pctMap = ['gold' => 40, 'high' => 25, 'medium' => 15];

    foreach ($clusters as $cluster) {
        $pct   = $pctMap[$cluster['demand_level']] ?? 15;
        $names = array_unique($cluster['names']);
        $label = count($names) === 1 ? $names[0] : $names[0] . ' + ' . (count($names)-1) . ' more';
        $dateRange = $cluster['date_from'] === $cluster['date_to']
            ? date('d M Y', strtotime($cluster['date_from']))
            : date('d M', strtotime($cluster['date_from'])) . '–' . date('d M Y', strtotime($cluster['date_to']));

        foreach ($rooms as $rid => $rname) {
            $base = $rates[$rid] ?? 2500;
            if ($base <= 0) continue;
            $suggested = round($base * (1 + $pct/100), -2);
            $reason = "Event Detected: $label ($dateRange). Suggesting +{$pct}% price increase.";

            addPricingSuggestion([
                'room_id'        => $rid,
                'date_from'      => $cluster['date_from'],
                'date_to'        => $cluster['date_to'],
                'current_price'  => $base,
                'suggested_price'=> $suggested,
                'suggestion_pct' => $pct,
                'reason'         => $reason,
                'demand_level'   => $cluster['demand_level'],
            ]);
            $count++;
        }
    }
    return $count;
}

// ── CLI / cron entry point ─────────────────────────────────────
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === '1')) {
    $seeded = seedDemandEvents();
    $suggested = generatePricingSuggestions();
    echo json_encode(['seeded_events' => $seeded, 'suggestions_generated' => $suggested]);
}
