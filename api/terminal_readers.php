<?php
/**
 * GET api/terminal_readers.php
 * -----------------------------------------------------------------------------
 * Setup / diagnostics helper. Lists the readers on your Stripe account with
 * their id, label, status and when they last checked in - which is how you
 * find the tmr_... id for config.php, and how you prove a reader is online
 * before blaming the code.
 *
 * Optional: ?status=online   ?location=tml_...
 */

require_once __DIR__ . '/../lib/PosTerminal.php';

pos_require_auth();

try {
    $stripe  = new StripeApi();
    $filters = array('limit' => 100);
    if (!empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];       // online | offline
    }
    if (!empty($_GET['location'])) {
        $filters['location'] = $_GET['location'];
    }

    $list    = $stripe->listReaders($filters);
    $readers = array();
    foreach (isset($list['data']) ? $list['data'] : array() as $r) {
        $readers[] = array(
            'id'            => $r['id'],
            'label'         => isset($r['label']) ? $r['label'] : '',
            'device_type'   => isset($r['device_type']) ? $r['device_type'] : '',
            'status'        => isset($r['status']) ? $r['status'] : '',
            'ip_address'    => isset($r['ip_address']) ? $r['ip_address'] : '',
            'location'      => isset($r['location']) ? (is_array($r['location']) ? $r['location']['id'] : $r['location']) : '',
            'serial_number' => isset($r['serial_number']) ? $r['serial_number'] : '',
            'sw_version'    => isset($r['device_sw_version']) ? $r['device_sw_version'] : '',
            // last_seen_at is in MILLIseconds, unlike every other Stripe timestamp.
            'last_seen'     => isset($r['last_seen_at']) && $r['last_seen_at']
                ? date('Y-m-d H:i:s', (int) round($r['last_seen_at'] / 1000))
                : '',
            'busy_with'     => isset($r['action']['status']) ? $r['action']['status'] : '',
        );
    }

    pos_json_response(array(
        'ok'        => true,
        'live_mode' => $stripe->isLiveMode(),
        'count'     => count($readers),
        'readers'   => $readers,
    ));
} catch (StripeApiException $e) {
    pos_json_error($e->getMessage(), 502, array('failure_code' => $e->stripeCode));
} catch (Exception $e) {
    pos_json_error($e->getMessage(), 500);
}
