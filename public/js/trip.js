$(function () {
    var tripId = $('#split').data('trip-id');
    var $split = $('#split');

    function pct(share) {
        return Number(share).toLocaleString('ru-RU', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 2
        });
    }

    $.ajax({
        url: 'api/trip_split.php',
        data: { trip_id: tripId },
        dataType: 'json'
    }).done(function (data) {
        var sumCents = 0;
        var rows = '';
        $.each(data.clients, function (i, client) {
            var cents = Math.round(Number(client.amount) * 100);
            sumCents += cents;
            rows += '<tr>' +
                '<td>' + esc(client.name) + '</td>' +
                '<td class="r">' + client.cargo_count + '</td>' +
                '<td class="r">' + num(client.weight_kg) + '</td>' +
                '<td class="r">' + num(client.volume_m3) + '</td>' +
                '<td class="r">' + pct(client.share * 100) + '</td>' +
                '<td class="r">' + money(client.amount) + '</td>' +
                '</tr>';
        });
        if (!data.clients.length) {
            rows = '<tr><td colspan="6" class="empty">Грузов нет</td></tr>';
        }

        var note = '';
        if (data.basis === 'weight' && data.clients.length) {
            note = '<p class="note">В рейсе нет объёма, поэтому стоимость разделена по весу.</p>';
        }

        $split.html(
            note +
            '<table class="grid">' +
            '<thead><tr>' +
            '<th>Клиент</th>' +
            '<th class="r">Грузов</th>' +
            '<th class="r">Вес, кг</th>' +
            '<th class="r">Объём, м³</th>' +
            '<th class="r">Доля, %</th>' +
            '<th class="r">Сумма, ₽</th>' +
            '</tr></thead><tbody>' +
            rows +
            '<tr class="total"><td colspan="5">Сумма по клиентам</td><td class="r">' + money(sumCents / 100) + '</td></tr>' +
            '<tr class="total"><td colspan="5">Стоимость рейса</td><td class="r">' + money(data.cost) + '</td></tr>' +
            '</tbody></table>'
        );
    }).fail(function (xhr) {
        var message = 'Не удалось загрузить распределение';
        if (xhr.responseJSON && xhr.responseJSON.error) {
            message = xhr.responseJSON.error;
        }
        $split.html('<p class="empty">' + esc(message) + '</p>');
    });
});
