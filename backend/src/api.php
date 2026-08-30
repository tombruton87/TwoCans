<?php
declare(strict_types=1);

/**
 * Small JSON endpoints for the bits of the page that update on their own.
 *
 * Reached as `/?api=<name>`, after authentication, and always terminates the
 * request. Read-only — anything that changes state goes through a POST action
 * so it keeps its CSRF protection.
 */

/** @var Store $store */
/** @var DeviceRepository $devices */
/** @var string $api */

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

/** The fields a page needs to redraw a phone's status without reloading. */
$status = static function (array $row): array {
    $d = Presenter::device(DeviceRepository::toView($row));

    return [
        'id' => $d['id'],
        'online' => $d['online'],
        'registered' => $d['registered'],
        'statusText' => $d['statusText'],
        'statusMod' => $d['statusMod'],
        'lastSeenText' => $d['lastSeenText'],
        'canTestCall' => $d['online'] && Auth::can('devices'),
    ];
};

switch ($api) {
    /*
     * Live registration state for one phone. The device page polls this so a
     * parent watching the screen sees it go green the moment the app signs in,
     * instead of having to reload.
     */
    case 'device_status':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($devices->find($id) === null) {
            $json(['error' => 'no such device'], 404);
        }

        (new PjsipConfig($devices))->syncRegistrations();
        $json($status($devices->find($id)));

        // no break — $json exits

    /*
     * The same thing for every phone at once, for the list page. One reply
     * rather than one per card: each call asks Asterisk for its contacts, so
     * polling per card would multiply that by the number of phones in the
     * house every two seconds.
     */
    case 'device_statuses':
        (new PjsipConfig($devices))->syncRegistrations();

        $json(['devices' => array_values(array_map($status, $devices->all()))]);

        // no break — $json exits

    default:
        $json(['error' => 'unknown endpoint'], 404);
}
