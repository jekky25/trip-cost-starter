<?php

function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $file = __DIR__ . '/../data/app.sqlite';
        if (!file_exists($file)) {
            die('База не создана. Запустите: php bin/reset-db.php');
        }
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS invoices_client_period ON invoices(client_id, period)');
    }

    return $pdo;
}
