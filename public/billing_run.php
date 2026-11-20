<?php
require __DIR__ . '/../lib/bootstrap.php';

$period = '';
if (isset($_POST['period'])) {
    $period = $_POST['period'];
} elseif (isset($_GET['period'])) {
    $period = $_GET['period'];
}

header('Location: billing.php?period=' . urlencode($period));
