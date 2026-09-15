<?php
/**
 * CLI setup helper:  php tools_list_readers.php
 * -----------------------------------------------------------------------------
 * Prints the readers on your Stripe account so you can copy the tmr_... id of
 * your BBPOS WisePOS E into config.php, and shows whether it is online right
 * now - the first thing to check whenever a payment does not reach the counter.
 *
 * Also prints your locations, which you need when you register a new reader.
 */

require_once __DIR__ . '/lib/PosTerminal.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain');
}

try {
    $stripe = new StripeApi();
    echo "Mode: " . ($stripe->isLiveMode() ? "LIVE" : "TEST") . "\n\n";

    $locations = $stripe->listLocations(20);
    echo "Locations\n";
    echo str_repeat('-', 78) . "\n";
    if (empty($locations['data'])) {
        echo "  (none yet - create one in Dashboard > Terminal > Locations)\n";
    }
    foreach ($locations['data'] as $l) {
        printf("  %-22s %s\n", $l['id'], isset($l['display_name']) ? $l['display_name'] : '');
    }

    $list = $stripe->listReaders(array('limit' => 100));
    echo "\nReaders\n";
    echo str_repeat('-', 78) . "\n";
    if (empty($list['data'])) {
        echo "  (none registered - on the WisePOS E: Settings > enter the pairing code\n";
        echo "   shown in Dashboard > Terminal > Readers > Register reader)\n";
    }
    foreach ($list['data'] as $r) {
        $seen = !empty($r['last_seen_at']) ? date('Y-m-d H:i:s', (int) round($r['last_seen_at'] / 1000)) : '-';
        printf(
            "  %-22s %-16s %-8s %-15s label=%s\n    last seen %s  sw=%s  busy=%s\n",
            $r['id'],
            isset($r['device_type']) ? $r['device_type'] : '',
            isset($r['status']) ? $r['status'] : '',
            isset($r['ip_address']) ? $r['ip_address'] : '',
            isset($r['label']) ? $r['label'] : '',
            $seen,
            isset($r['device_sw_version']) ? $r['device_sw_version'] : '-',
            isset($r['action']['status']) ? $r['action']['status'] : 'no'
        );
    }
    echo "\nPut the tmr_... id of your counter reader into config.php -> default_reader_id\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
