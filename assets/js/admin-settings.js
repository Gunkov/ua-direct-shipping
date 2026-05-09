/* global UADS_SETTINGS, jQuery */
(function ($) {
    'use strict';

    $(function () {
        // Graceful degrade if neither selectWoo nor select2 available
        var sw = $.fn.selectWoo || $.fn.select2;
        if (typeof sw !== 'function') {
            $('#uads_sender_city_select, #uads_sender_warehouse_select').replaceWith(
                '<p style="color:#b32d2e">⚠️ selectWoo не завантажився — спробуй Ctrl+F5.</p>'
            );
        }

        var $btn    = $('#uads-test-conn');
        var $result = $('#uads-test-result');
        var $key    = $('#uads_np_api_key');

        // Manual sync trigger
        $('#uads-sync-now').on('click', function (e) {
            e.preventDefault();
            if (!window.confirm(UADS_SETTINGS.i18n.syncConfirm)) return;
            var $b = $(this);
            var $r = $('#uads-sync-result');
            $b.prop('disabled', true);
            $r.text(UADS_SETTINGS.i18n.syncStart);

            $.post(UADS_SETTINGS.ajaxurl, {
                action: 'uads_admin_trigger_sync',
                _wpnonce: UADS_SETTINGS.syncNonce
            }).done(function (response) {
                if (response && response.success) {
                    $r.html('<span style="color:#46b450;">✅ ' + (response.data && response.data.message ? response.data.message : 'OK') + '</span>');
                } else {
                    var msg = response && response.data && response.data.message ? response.data.message : 'Error';
                    $r.html('<span style="color:#b32d2e;">❌ ' + msg + '</span>');
                    $b.prop('disabled', false);
                }
            }).fail(function (xhr) {
                $r.html('<span style="color:#b32d2e;">❌ HTTP ' + xhr.status + '</span>');
                $b.prop('disabled', false);
            });
        });

        // Sender city + warehouse selectWoo dropdowns
        var $cityEl = $('#uads_sender_city_select');
        var $whEl   = $('#uads_sender_warehouse_select');

        // Use whichever dropdown plugin is available
        function applyDropdown($el, opts) {
            if (typeof $el.selectWoo === 'function') return $el.selectWoo(opts);
            if (typeof $el.select2 === 'function')  return $el.select2(opts);
            return $el; // fallback — plain native select
        }
        function destroyDropdown($el) {
            if ($el.data('select2')) {
                if (typeof $el.selectWoo === 'function') $el.selectWoo('destroy');
                else if (typeof $el.select2 === 'function') $el.select2('destroy');
            }
        }

        if ($cityEl.length) {
            applyDropdown($cityEl, {
                placeholder: 'Введіть назву міста...',
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: UADS_SETTINGS.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'uads_search_cities',
                            _wpnonce: UADS_SETTINGS.checkoutNonce,
                            term: params.term,
                            limit: 20
                        };
                    },
                    processResults: function (response) {
                        if (!response.success) return { results: [] };
                        return { results: response.data || [] };
                    }
                }
            });
            $cityEl.on('select2:select', function (e) {
                var d = e.params.data;
                var cityRef = d.city_ref || d.ref;
                $('#uads_sender_city_ref').val(cityRef);
                $('#uads_sender_city_name').val(d.name);
                // Reset warehouse and re-init
                $whEl.empty().prop('disabled', false).append('<option></option>').trigger('change');
                $('#uads_sender_wh_ref').val('');
                $('#uads_sender_wh_label').val('');
                initSenderWarehouse(cityRef);
            });
            $cityEl.on('select2:clear', function () {
                $('#uads_sender_city_ref').val('');
                $('#uads_sender_city_name').val('');
                $whEl.empty().prop('disabled', true).trigger('change');
                $('#uads_sender_wh_ref').val('');
                $('#uads_sender_wh_label').val('');
            });

            // If city already selected — init warehouse picker
            var existingCityRef = $('#uads_sender_city_ref').val();
            if (existingCityRef) {
                initSenderWarehouse(existingCityRef);
            }
        }

        function initSenderWarehouse(cityRef) {
            if (!$whEl.length) return;
            destroyDropdown($whEl);
            $whEl.prop('disabled', false);
            applyDropdown($whEl, {
                placeholder: 'Введіть номер або частину назви...',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: UADS_SETTINGS.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'uads_search_warehouses',
                            _wpnonce: UADS_SETTINGS.checkoutNonce,
                            city_ref: cityRef,
                            term: params.term || '',
                            category: 'warehouse'
                        };
                    },
                    processResults: function (response) {
                        if (!response.success) return { results: [] };
                        return { results: response.data || [] };
                    }
                }
            });
            $whEl.on('select2:select', function (e) {
                var d = e.params.data;
                $('#uads_sender_wh_ref').val(d.ref);
                $('#uads_sender_wh_label').val(d.text);
            });
            $whEl.on('select2:clear', function () {
                $('#uads_sender_wh_ref').val('');
                $('#uads_sender_wh_label').val('');
            });
        }

        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            $btn.prop('disabled', true);
            $result
                .removeClass('notice-success notice-error')
                .addClass('notice notice-info')
                .html('<p>' + UADS_SETTINGS.i18n.testing + '</p>');

            $.post(UADS_SETTINGS.ajaxurl, {
                action: 'uads_test_connection',
                _wpnonce: UADS_SETTINGS.nonce,
                api_key: $key.val()
            }).done(function (response) {
                if (response && response.success) {
                    $result
                        .removeClass('notice-info notice-error')
                        .addClass('notice notice-success')
                        .html('<p>✅ ' + (response.data && response.data.message ? response.data.message : UADS_SETTINGS.i18n.success) + '</p>');
                } else {
                    var msg = response && response.data && response.data.message ? response.data.message : UADS_SETTINGS.i18n.failed;
                    $result
                        .removeClass('notice-info notice-success')
                        .addClass('notice notice-error')
                        .html('<p>❌ ' + msg + '</p>');
                }
            }).fail(function (xhr) {
                $result
                    .removeClass('notice-info notice-success')
                    .addClass('notice notice-error')
                    .html('<p>❌ HTTP ' + xhr.status + '</p>');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
}(jQuery));
