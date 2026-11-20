<?php

/**
 * Доля веса клиента в рейсе. Нужна отчёту загрузки машин, к деньгам не относится.
 *
 * Возвращает [client_id => [name, weight_kg, share, amount]], share: доля от 0 до 1.
 */
function calc_share($trip_id)
{
    $trip_id = (int)$trip_id;
    $st = db()->prepare('SELECT cost FROM trips WHERE id = ?');
    $st->execute(array($trip_id));
    $trip = $st->fetch();
    if (!$trip) {
        return array();
    }

    $st = db()->prepare('SELECT c.client_id, cl.name, SUM(c.weight_kg) AS weight_kg
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = ?
        GROUP BY c.client_id, cl.name');
    $st->execute(array($trip_id));
    $rows = $st->fetchAll();

    $total_weight = 0;
    foreach ($rows as $row) {
        $total_weight += $row['weight_kg'];
    }

    $result = array();
    foreach ($rows as $row) {
        $share = $total_weight > 0 ? $row['weight_kg'] / $total_weight : 0;
        $result[$row['client_id']] = array(
            'name' => $row['name'],
            'weight_kg' => $row['weight_kg'],
            'share' => $share,
            'amount' => round($trip['cost'] * $share, 2),
        );
    }

    return $result;
}

/**
 * Перевести десятичную запись в целое число с заданным числом знаков после запятой.
 * Лишние знаки округляются по половине вверх. Пустое значение — 0.
 */
function decimal_to_scaled($value, $scale)
{
    if ($value === null || $value === '') {
        return 0;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return 0;
    }

    $negative = false;
    if ($text[0] === '-') {
        $negative = true;
        $text = substr($text, 1);
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $text)) {
        $scaled = (int)round((float)$value * (10 ** $scale));
        return $negative ? -abs($scaled) : $scaled;
    }

    $parts = explode('.', $text, 2);
    $fraction = isset($parts[1]) ? $parts[1] : '';
    $factor = 10 ** $scale;
    if (strlen($fraction) > $scale) {
        $round_digit = (int)$fraction[$scale];
        $fraction = substr($fraction, 0, $scale);
        $scaled = (int)$parts[0] * $factor + (int)$fraction;
        if ($round_digit >= 5) {
            $scaled++;
        }
    } else {
        $fraction = str_pad($fraction, $scale, '0');
        $scaled = (int)$parts[0] * $factor + (int)$fraction;
    }

    return $negative ? -$scaled : $scaled;
}

function cents_to_money($cents)
{
    $negative = $cents < 0;
    $cents = abs((int)$cents);
    $text = intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    return $negative ? '-' . $text : $text;
}

function scaled_to_number($scaled)
{
    if ($scaled % 1000 === 0) {
        return (int)($scaled / 1000);
    }
    return $scaled / 1000;
}

/**
 * Разложить сумму в копейках по целым базам. Остаток копеек получает тот,
 * у кого больше дробная часть; при равенстве — меньший id.
 */
function allocate_cents($total_cents, $bases)
{
    $ids = array_keys($bases);
    sort($ids, SORT_NUMERIC);
    $result = array();
    foreach ($ids as $id) {
        $result[$id] = 0;
    }
    if (!$ids || $total_cents === 0) {
        return $result;
    }

    $total_base = 0;
    foreach ($ids as $id) {
        $total_base += $bases[$id];
    }

    if ($total_base <= 0) {
        $count = count($ids);
        $floor = intdiv($total_cents, $count);
        $left = $total_cents - $floor * $count;
        foreach ($ids as $index => $id) {
            $result[$id] = $floor + ($index < $left ? 1 : 0);
        }
        return $result;
    }

    $parts = array();
    $sum_floor = 0;
    foreach ($ids as $id) {
        $product = $total_cents * $bases[$id];
        $floor = intdiv($product, $total_base);
        $parts[] = array(
            'id' => $id,
            'floor' => $floor,
            'remainder' => $product % $total_base,
        );
        $sum_floor += $floor;
    }

    $left = $total_cents - $sum_floor;
    usort($parts, function ($a, $b) {
        if ($a['remainder'] === $b['remainder']) {
            return $a['id'] <=> $b['id'];
        }
        return $b['remainder'] <=> $a['remainder'];
    });
    for ($i = 0; $i < $left; $i++) {
        $parts[$i]['floor']++;
    }
    foreach ($parts as $part) {
        $result[$part['id']] = $part['floor'];
    }

    return $result;
}

/**
 * Распределение стоимости рейса.
 *
 * База — объём груза. Пустой объём считается нулём. Если объёма нет ни у кого,
 * стоимость делится по весу: иначе рейс выпал бы из счетов.
 * Сумма amount по клиентам равна стоимости рейса до копейки.
 * Возвращает null, если рейса нет.
 */
function trip_cost_split($trip_id)
{
    $trip_id = (int)$trip_id;
    $st = db()->prepare('SELECT id, number, cost FROM trips WHERE id = ?');
    $st->execute(array($trip_id));
    $trip = $st->fetch();
    if (!$trip) {
        return null;
    }

    $st = db()->prepare('SELECT c.client_id, cl.name, c.weight_kg, c.volume_m3
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = ?
        ORDER BY c.client_id, c.id');
    $st->execute(array($trip_id));

    $grouped = array();
    foreach ($st->fetchAll() as $row) {
        $client_id = (int)$row['client_id'];
        if (!isset($grouped[$client_id])) {
            $grouped[$client_id] = array(
                'client_id' => $client_id,
                'name' => $row['name'],
                'cargo_count' => 0,
                'weight_scaled' => 0,
                'volume_scaled' => 0,
            );
        }
        $grouped[$client_id]['cargo_count']++;
        $grouped[$client_id]['weight_scaled'] += decimal_to_scaled($row['weight_kg'], 3);
        $grouped[$client_id]['volume_scaled'] += decimal_to_scaled($row['volume_m3'], 3);
    }

    $volume_total = 0;
    $weight_total = 0;
    foreach ($grouped as $group) {
        $volume_total += $group['volume_scaled'];
        $weight_total += $group['weight_scaled'];
    }

    $basis = $volume_total > 0 ? 'volume' : 'weight';
    $bases = array();
    foreach ($grouped as $client_id => $group) {
        $bases[$client_id] = $basis === 'volume' ? $group['volume_scaled'] : $group['weight_scaled'];
    }
    $total_base = array_sum($bases);
    $amounts = allocate_cents(decimal_to_scaled($trip['cost'], 2), $bases);

    $clients = array();
    foreach ($grouped as $client_id => $group) {
        $clients[] = array(
            'client_id' => $client_id,
            'name' => $group['name'],
            'cargo_count' => $group['cargo_count'],
            'weight_kg' => scaled_to_number($group['weight_scaled']),
            'volume_m3' => scaled_to_number($group['volume_scaled']),
            'share' => $total_base > 0 ? $bases[$client_id] / $total_base : 0,
            'amount_cents' => $amounts[$client_id],
            'amount' => cents_to_money($amounts[$client_id]),
        );
    }

    return array(
        'trip_id' => (int)$trip['id'],
        'number' => $trip['number'],
        'cost' => cents_to_money(decimal_to_scaled($trip['cost'], 2)),
        'basis' => $basis,
        'clients' => $clients,
    );
}

function billing_period_ok($period)
{
    return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) === 1;
}

/**
 * Что изменится в счетах месяца, если посчитать их по объёму. Базу не меняет.
 */
function billing_collect($period)
{
    $st = db()->prepare("SELECT id, number FROM trips
        WHERE departure_date LIKE ? AND status <> 'cancelled'
        ORDER BY departure_date, id");
    $st->execute(array($period . '%'));
    $trips = $st->fetchAll();

    $clients = array();
    $weight_fallback = array();
    foreach ($trips as $trip) {
        $split = trip_cost_split($trip['id']);
        if (!$split) {
            continue;
        }
        if ($split['basis'] === 'weight' && $split['clients']) {
            $weight_fallback[] = array(
                'trip_id' => $split['trip_id'],
                'number' => $split['number'],
            );
        }
        foreach ($split['clients'] as $client) {
            $client_id = $client['client_id'];
            if (!isset($clients[$client_id])) {
                $clients[$client_id] = array(
                    'client_id' => $client_id,
                    'name' => $client['name'],
                    'invoice_id' => null,
                    'before_cents' => 0,
                    'after_cents' => 0,
                    'lines' => array(),
                );
            }
            $clients[$client_id]['after_cents'] += $client['amount_cents'];
            if ($client['amount_cents'] !== 0) {
                $clients[$client_id]['lines'][] = array(
                    'trip_id' => $split['trip_id'],
                    'amount_cents' => $client['amount_cents'],
                );
            }
        }
    }

    $st = db()->prepare('SELECT id, client_id, total FROM invoices WHERE period = ?');
    $st->execute(array($period));
    foreach ($st->fetchAll() as $invoice) {
        $client_id = (int)$invoice['client_id'];
        if (!isset($clients[$client_id])) {
            continue;
        }
        $clients[$client_id]['invoice_id'] = (int)$invoice['id'];
        $clients[$client_id]['before_cents'] = decimal_to_scaled($invoice['total'], 2);
    }

    $list = array_values($clients);
    usort($list, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    $total_before = 0;
    $total_after = 0;
    foreach ($list as $client) {
        $total_before += $client['before_cents'];
        $total_after += $client['after_cents'];
    }

    return array(
        'period' => $period,
        'clients' => $list,
        'total_before_cents' => $total_before,
        'total_after_cents' => $total_after,
        'weight_fallback' => $weight_fallback,
    );
}

function billing_client_payload($client, $action = null)
{
    $payload = array(
        'client_id' => $client['client_id'],
        'name' => $client['name'],
        'invoice_id' => $client['invoice_id'],
        'before' => cents_to_money($client['before_cents']),
        'after' => cents_to_money($client['after_cents']),
        'diff' => cents_to_money($client['after_cents'] - $client['before_cents']),
    );
    if ($action !== null) {
        $payload['action'] = $action;
    }
    return $payload;
}

function billing_result_payload($collected, $created = null, $updated = null, $unchanged = null)
{
    $clients = array();
    foreach ($collected['clients'] as $client) {
        $action = isset($client['action']) ? $client['action'] : null;
        $clients[] = billing_client_payload($client, $action);
    }

    $payload = array(
        'period' => $collected['period'],
        'clients' => $clients,
        'total_before' => cents_to_money($collected['total_before_cents']),
        'total_after' => cents_to_money($collected['total_after_cents']),
        'weight_fallback' => $collected['weight_fallback'],
    );
    if ($created !== null) {
        $payload['created'] = $created;
        $payload['updated'] = $updated;
        $payload['unchanged'] = $unchanged;
    }
    return $payload;
}

function invoice_line_cents($invoice_id)
{
    $st = db()->prepare('SELECT trip_id, amount FROM invoice_lines WHERE invoice_id = ?');
    $st->execute(array($invoice_id));
    $lines = array();
    foreach ($st->fetchAll() as $line) {
        $trip_id = (int)$line['trip_id'];
        if (!isset($lines[$trip_id])) {
            $lines[$trip_id] = 0;
        }
        $lines[$trip_id] += decimal_to_scaled($line['amount'], 2);
    }
    ksort($lines);
    return $lines;
}

function desired_line_cents($lines)
{
    $map = array();
    foreach ($lines as $line) {
        $map[$line['trip_id']] = $line['amount_cents'];
    }
    ksort($map);
    return $map;
}

function write_invoice_lines($invoice_id, $lines)
{
    $delete = db()->prepare('DELETE FROM invoice_lines WHERE invoice_id = ?');
    $delete->execute(array($invoice_id));

    $insert = db()->prepare('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (?, ?, ?)');
    foreach ($lines as $line) {
        $insert->execute(array(
            $invoice_id,
            $line['trip_id'],
            cents_to_money($line['amount_cents']),
        ));
    }
}

/**
 * Записать счета месяца по текущему правилу.
 * Повторный вызов с тем же результатом строки не переписывает.
 */
function billing_apply_period($period)
{
    $collected = billing_collect($period);
    $db = db();
    $created = 0;
    $updated = 0;
    $unchanged = 0;

    $db->beginTransaction();
    try {
        $find = $db->prepare('SELECT * FROM invoices WHERE client_id = ? AND period = ?');
        $insert = $db->prepare('INSERT INTO invoices (client_id, period, status, total, created_at)
            VALUES (?, ?, \'draft\', ?, ?)');
        $update = $db->prepare('UPDATE invoices SET total = ?, exported_at = NULL WHERE id = ?');

        foreach ($collected['clients'] as $index => $client) {
            $find->execute(array($client['client_id'], $period));
            $invoice = $find->fetch();
            $desired = desired_line_cents($client['lines']);

            if (!$invoice) {
                if ($client['after_cents'] === 0) {
                    $collected['clients'][$index]['action'] = 'unchanged';
                    $unchanged++;
                    continue;
                }
                $insert->execute(array(
                    $client['client_id'],
                    $period,
                    cents_to_money($client['after_cents']),
                    date('Y-m-d H:i:s'),
                ));
                $invoice_id = (int)$db->lastInsertId();
                write_invoice_lines($invoice_id, $client['lines']);
                $collected['clients'][$index]['invoice_id'] = $invoice_id;
                $collected['clients'][$index]['action'] = 'created';
                $created++;
                continue;
            }

            $invoice_id = (int)$invoice['id'];
            $collected['clients'][$index]['invoice_id'] = $invoice_id;
            $same_lines = invoice_line_cents($invoice_id) == $desired;
            $same_total = decimal_to_scaled($invoice['total'], 2) === $client['after_cents'];
            if ($same_lines && $same_total) {
                $collected['clients'][$index]['action'] = 'unchanged';
                $unchanged++;
                continue;
            }

            write_invoice_lines($invoice_id, $client['lines']);
            $update->execute(array(cents_to_money($client['after_cents']), $invoice_id));
            $collected['clients'][$index]['action'] = 'updated';
            $updated++;
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return billing_result_payload($collected, $created, $updated, $unchanged);
}
