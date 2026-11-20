$(function () {
    var $form = $('#billing-filter');
    var $result = $('#billing-result');
    var $msg = $('#billing-msg');
    var $note = $('#billing-note');

    function signedMoney(value) {
        var number = Number(value);
        if (number > 0) {
            return '+' + money(number);
        }
        if (number < 0) {
            return '−' + money(Math.abs(number));
        }
        return money(0);
    }

    function actionName(action) {
        if (action === 'created') {
            return 'создан';
        }
        if (action === 'updated') {
            return 'пересчитан';
        }
        if (action === 'unchanged') {
            return 'без изменений';
        }
        return '';
    }

    function show(data, summary) {
        $msg.prop('hidden', !summary).text(summary || '');

        if (data.weight_fallback && data.weight_fallback.length) {
            var numbers = $.map(data.weight_fallback, function (trip) {
                return trip.number;
            });
            $note.prop('hidden', false).text(
                'Нет объёма, стоимость разделена по весу: ' + numbers.join(', ') + '.'
            );
        } else {
            $note.prop('hidden', true).text('');
        }

        var rows = '';
        $.each(data.clients, function (i, client) {
            var diff = Number(client.diff);
            var diffClass = diff > 0 ? 'diff-up' : (diff < 0 ? 'diff-down' : '');
            rows += '<tr>' +
                '<td>' + (client.invoice_id
                    ? '<a href="invoice.php?id=' + client.invoice_id + '">' + esc(client.name) + '</a>'
                    : esc(client.name)) + '</td>' +
                '<td class="r">' + money(client.before) + '</td>' +
                '<td class="r">' + money(client.after) + '</td>' +
                '<td class="r ' + diffClass + '">' + signedMoney(client.diff) + '</td>' +
                '<td>' + esc(actionName(client.action)) + '</td>' +
                '</tr>';
        });
        if (!data.clients.length) {
            rows = '<tr><td colspan="5" class="empty">За этот месяц рейсов нет</td></tr>';
        }

        $result.html(
            '<table class="grid">' +
            '<thead><tr>' +
            '<th>Клиент</th>' +
            '<th class="r">Сейчас, ₽</th>' +
            '<th class="r">По объёму, ₽</th>' +
            '<th class="r">Разница, ₽</th>' +
            '<th>Что сделано</th>' +
            '</tr></thead><tbody>' +
            rows +
            '<tr class="total">' +
            '<td>Итого</td>' +
            '<td class="r">' + money(data.total_before) + '</td>' +
            '<td class="r">' + money(data.total_after) + '</td>' +
            '<td class="r">' + signedMoney(Number(data.total_after) - Number(data.total_before)) + '</td>' +
            '<td></td>' +
            '</tr></tbody></table>'
        );
    }

    function fail(xhr) {
        var message = 'Не удалось выполнить запрос';
        if (xhr.responseJSON && xhr.responseJSON.error) {
            message = xhr.responseJSON.error;
        }
        $note.prop('hidden', true);
        $msg.prop('hidden', false).text(message);
    }

    function preview() {
        $msg.prop('hidden', true);
        $.ajax({
            url: 'api/billing_preview.php',
            data: $form.serialize(),
            dataType: 'json'
        }).done(function (data) {
            show(data, '');
        }).fail(fail);
    }

    $form.on('submit', function (event) {
        event.preventDefault();
        preview();
    });

    $('#billing-apply').on('click', function () {
        var period = $form.find('[name=period]').val();
        if (!window.confirm('Пересчитать счета за ' + period + ' по объёму?')) {
            return;
        }
        $.ajax({
            url: 'api/billing_apply.php',
            method: 'POST',
            data: { period: period },
            dataType: 'json'
        }).done(function (data) {
            var summary = 'Создано ' + data.created +
                ', пересчитано ' + data.updated +
                ', без изменений ' + data.unchanged + '.';
            show(data, summary);
        }).fail(fail);
    });
});
