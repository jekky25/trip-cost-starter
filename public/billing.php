<?php
require __DIR__ . '/../lib/bootstrap.php';

$months = db()->query("SELECT substr(departure_date, 1, 7) AS month FROM trips
    UNION
    SELECT period FROM invoices
    ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);

$period = isset($_GET['period']) ? $_GET['period'] : '';
if (!in_array($period, $months, true)) {
    $period = in_array('2026-10', $months, true) ? '2026-10' : (isset($months[0]) ? $months[0] : '');
}

page_header('Счета за месяц');
?>
<h1>Счета за месяц</h1>
<p class="note">Стоимость рейса делится по объёму груза. Сначала посмотрите, что изменится, и только потом записывайте счета.</p>

<form id="billing-filter" class="filter">
    <label>Месяц
        <select name="period">
            <?php foreach ($months as $month): ?>
                <option value="<?= h($month) ?>"<?= $month === $period ? ' selected' : '' ?>><?= h($month) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Показать</button>
    <button type="button" id="billing-apply">Применить</button>
</form>

<div id="billing-msg" class="msg" hidden></div>
<div id="billing-note" class="note" hidden></div>
<div id="billing-result"></div>

<script src="js/billing.js"></script>
<?php page_footer();
