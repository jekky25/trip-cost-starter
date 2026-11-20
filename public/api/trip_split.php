<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$trip_id = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);

if (!$trip_id || $trip_id < 1) {
    json_out(array('error' => 'Некорректный trip_id'), 400);
}

$split = trip_cost_split($trip_id);
if (!$split) {
    json_out(array('error' => 'Рейс не найден'), 404);
}

$clients = array();
foreach ($split['clients'] as $client) {
    $clients[] = array(
        'client_id' => $client['client_id'],
        'name' => $client['name'],
        'cargo_count' => $client['cargo_count'],
        'weight_kg' => $client['weight_kg'],
        'volume_m3' => $client['volume_m3'],
        'share' => $client['share'],
        'amount' => $client['amount'],
    );
}

json_out(array(
    'trip_id' => $split['trip_id'],
    'number' => $split['number'],
    'cost' => $split['cost'],
    'basis' => $split['basis'],
    'clients' => $clients,
));
