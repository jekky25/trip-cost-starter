<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$period = isset($_POST['period']) ? $_POST['period'] : '';
if (!billing_period_ok($period)) {
    json_out(array('error' => 'Неверный месяц'), 400);
}

try {
    json_out(billing_apply_period($period));
} catch (Exception $e) {
    json_out(array('error' => 'Не удалось записать счета'), 500);
}
