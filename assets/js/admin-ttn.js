/* global UADS_TTN, jQuery */
(function ($) {
    'use strict';

    $(function () {
        var $box = $('#uads-np-ttn').length ? $('#uads-np-ttn') : $('.uads-ttn-create, .uads-ttn-existing').closest('.postbox, .uads-postbox-host, body');

        // CoD toggle
        $box.on('change', 'input[name="enable_cod"]', function () {
            $box.find('.uads-cod-amount').prop('disabled', !this.checked);
        });

        // Live volumetric weight calc
        function recalcVolumetric() {
            var l = parseFloat($box.find('input[name="length_cm"]').val()) || 0;
            var w = parseFloat($box.find('input[name="width_cm"]').val()) || 0;
            var h = parseFloat($box.find('input[name="height_cm"]').val()) || 0;
            var actual = parseFloat($box.find('input[name="weight"]').val()) || 0;
            var vol = (l * w * h) / 4000;
            var billable = Math.max(actual, vol);
            $box.find('.uads-vol-weight').text(vol.toFixed(2));
            $box.find('.uads-billable-weight').text(billable.toFixed(2));
        }
        $box.on('input change', '.uads-dim, .uads-w-actual', recalcVolumetric);
        recalcVolumetric();

        // Dry-run
        $box.on('click', '.uads-ttn-dryrun', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $out = $box.find('.uads-dryrun-output');
            var orderId = $box.find('.uads-ttn-create').data('order-id');

            $btn.prop('disabled', true).text(UADS_TTN.i18n.dryRunRunning);
            $out.hide().empty();

            $.post(UADS_TTN.ajaxurl, $.extend({
                action: 'uads_admin_dryrun_ttn',
                _wpnonce: UADS_TTN.nonce,
                order_id: orderId
            }, collectForm()))
            .done(function (resp) {
                if (!resp.success) {
                    $out.html('<p style="color:#b32d2e">❌ ' + (resp.data && resp.data.message ? resp.data.message : 'Error') + '</p>').show();
                    return;
                }
                var html = '<div style="background:#fff; border:1px solid #ccc; padding:8px; max-height:300px; overflow:auto;">';
                html += '<strong>NP API payload:</strong>';
                html += '<pre style="white-space:pre-wrap; font-size:11px; margin:4px 0;">' + escapeHtml(JSON.stringify(resp.data.payload, null, 2)) + '</pre>';
                if (resp.data.errors && resp.data.errors.length) {
                    html += '<p style="color:#b32d2e;"><strong>⚠️ Validation issues:</strong></p><ul style="color:#b32d2e;">';
                    resp.data.errors.forEach(function (err) {
                        html += '<li>' + escapeHtml(err) + '</li>';
                    });
                    html += '</ul>';
                } else {
                    html += '<p style="color:#46b450;">✅ Payload валідний — готовий до реального створення</p>';
                }
                html += '</div>';
                $out.html(html).show();

                // Show live NP rate estimate (calculated server-side from current form values)
                if (resp.data.rate !== null && typeof resp.data.rate !== 'undefined') {
                    var rateBlock = '<div style="margin-top:6px; padding:8px; background:#e7f5e7; border:1px solid #46b450; border-radius:4px;">';
                    rateBlock += '<strong>💵 Ставка НП за цими даними: ' + parseFloat(resp.data.rate).toFixed(2) + ' ₴</strong>';
                    if (resp.data.cod_fee !== null && typeof resp.data.cod_fee !== 'undefined' && resp.data.cod_fee > 0) {
                        rateBlock += '<br /><small>💰 Комісія НП за накладений платіж: ' + parseFloat(resp.data.cod_fee).toFixed(2) + ' ₴ (платить отримувач)</small>';
                    }
                    rateBlock += '</div>';
                    $out.append(rateBlock);
                } else if (resp.data.rate_error) {
                    $out.append('<p style="color:#b32d2e;">Не вдалося отримати ставку: ' + escapeHtml(resp.data.rate_error) + '</p>');
                }
            })
            .fail(function (xhr) {
                $out.html('<p style="color:#b32d2e">❌ HTTP ' + xhr.status + '</p>').show();
            })
            .always(function () {
                $btn.prop('disabled', false).text('🔍 Перегляд payload (dry-run)');
            });
        });

        // Real create
        $box.on('click', '.uads-ttn-create-real', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var $out = $box.find('.uads-ttn-result');
            var orderId = $box.find('.uads-ttn-create').data('order-id');

            $btn.prop('disabled', true).text(UADS_TTN.i18n.creating);
            $out.html('<p>' + UADS_TTN.i18n.creating + '</p>');

            $.post(UADS_TTN.ajaxurl, $.extend({
                action: 'uads_admin_create_ttn',
                _wpnonce: UADS_TTN.nonce,
                order_id: orderId
            }, collectForm()))
            .done(function (resp) {
                if (!resp.success) {
                    var msg = resp.data && resp.data.message ? resp.data.message : 'Error';
                    if (resp.data && resp.data.errors) {
                        msg += '<ul>';
                        resp.data.errors.forEach(function (e) { msg += '<li>' + escapeHtml(e) + '</li>'; });
                        msg += '</ul>';
                    }
                    $out.html('<p style="color:#b32d2e">❌ ' + msg + '</p>');
                    $btn.prop('disabled', false).text('🚚 Створити ТТН');
                    return;
                }
                $out.html('<p style="color:#46b450">✅ ТТН створено: <code>' + escapeHtml(resp.data.ttn) +
                          '</code> | Cost: ' + escapeHtml(String(resp.data.cost)) + ' ₴ | ETA: ' + escapeHtml(resp.data.eta) + '</p>' +
                          '<p>Перезавантажую сторінку через 2 сек...</p>');
                setTimeout(function () { window.location.reload(); }, 2000);
            })
            .fail(function (xhr) {
                $out.html('<p style="color:#b32d2e">❌ HTTP ' + xhr.status + '</p>');
                $btn.prop('disabled', false).text('🚚 Створити ТТН');
            });
        });

        // Delete TTN
        $box.on('click', '.uads-ttn-delete', function (e) {
            e.preventDefault();
            if (!confirm(UADS_TTN.i18n.confirmDelete)) return;

            var $btn = $(this);
            var $out = $box.find('.uads-ttn-result');
            var orderId = $box.find('.uads-ttn-existing').data('order-id');

            $btn.prop('disabled', true).text(UADS_TTN.i18n.deleting);

            $.post(UADS_TTN.ajaxurl, {
                action: 'uads_admin_delete_ttn',
                _wpnonce: UADS_TTN.nonce,
                order_id: orderId
            })
            .done(function (resp) {
                if (resp.success) {
                    $out.html('<p style="color:#46b450">✅ ТТН видалено. Перезавантаження...</p>');
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    $out.html('<p style="color:#b32d2e">❌ ' + (resp.data && resp.data.message ? resp.data.message : 'Error') + '</p>');
                    $btn.prop('disabled', false).text('🗑 Видалити ТТН');
                }
            });
        });

        function collectForm() {
            var data = {};
            $box.find('input, select').each(function () {
                var name = this.name;
                if (!name) return;
                if (this.type === 'checkbox') {
                    if (this.checked) data[name] = '1';
                } else {
                    data[name] = $(this).val();
                }
            });
            return data;
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    });
}(jQuery));
