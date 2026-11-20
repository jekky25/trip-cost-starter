<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$period = isset($_GET['period']) ? $_GET['period'] : '';
if (!billing_period_ok($period)) {
    json_out(array('error' => 'Неверный месяц'), 400);
}

json_out(billing_result_payload(billing_collect($period)));
