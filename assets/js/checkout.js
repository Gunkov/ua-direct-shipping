/* global UADS_CHECKOUT, jQuery */
(function ($) {
    'use strict';

    var ROOT_SEL = '#uads-checkout-fields';
    var FIELDS_SEL = ROOT_SEL + ' .uads-fields-host';

    function getChosenMethod() {
        var $checked = $('input[name^="shipping_method"]:checked').first();
        if (!$checked.length) {
            $checked = $('input[name^="shipping_method"]').first();
        }
        var raw = $checked.val() || '';
        // raw is e.g. "uads_np_warehouse:7"
        var id = raw.split(':')[0];
        return id;
    }

    function syncSelectorRadio() {
        // Reflect the actually-chosen WC shipping method in our top-of-form radio
        var methodId = getChosenMethod();
        $('input[name="uads_method_radio"]').each(function () {
            this.checked = (this.value === methodId);
        });
    }

    function bindSelectorClick() {
        $(document.body).off('change.uadsSelector', 'input[name="uads_method_radio"]');
        $(document.body).on('change.uadsSelector', 'input[name="uads_method_radio"]', function () {
            var methodId = this.value;
            // Find the matching native WC radio (raw value starts with our method id, e.g. uads_np_warehouse:7)
            var $native = $('input[name^="shipping_method"]').filter(function () {
                return String(this.value).indexOf(methodId + ':') === 0 || this.value === methodId;
            }).first();
            if ($native.length) {
                $native.prop('checked', true).trigger('click').trigger('change');
            }
        });
    }

    function detectKind(methodId) {
        var ids = UADS_CHECKOUT.methodIds;
        if (methodId === ids.warehouse) return 'warehouse';
        if (methodId === ids.poshtomat) return 'poshtomat';
        if (methodId === ids.address)   return 'address';
        return null;
    }

    function renderFields(kind) {
        if (!kind) {
            $(FIELDS_SEL).empty();
            return;
        }

        var i18n = UADS_CHECKOUT.i18n;
        var html = '<div class="uads-row">';
        html += '<p class="form-row form-row-wide uads-field uads-field-city">';
        html += '<label>' + i18n.cityLabel + ' <abbr class="required">*</abbr></label>';
        html += '<select name="uads_city_select" class="uads-city-select" style="width:100%"><option></option></select>';
        html += '<input type="hidden" name="uads_city_ref" class="uads-city-ref" />';
        html += '<input type="hidden" name="uads_city_name" class="uads-city-name" />';
        html += '</p>';

        if (kind === 'warehouse' || kind === 'poshtomat') {
            var label = (kind === 'warehouse') ? i18n.whLabel : i18n.pmLabel;
            html += '<p class="form-row form-row-wide uads-field uads-field-warehouse">';
            html += '<label>' + label + ' <abbr class="required">*</abbr></label>';
            html += '<select name="uads_warehouse_select" class="uads-wh-select" style="width:100%" disabled><option></option></select>';
            html += '<input type="hidden" name="uads_warehouse_ref" class="uads-wh-ref" />';
            html += '<input type="hidden" name="uads_warehouse_number" class="uads-wh-number" />';
            html += '<input type="hidden" name="uads_warehouse_description" class="uads-wh-desc" />';
            html += '</p>';
        }

        if (kind === 'address') {
            // Note: По-батькові тепер реєструється як WC standard billing field (billing_middle_name).
            // Воно з'являється у блоці "Платіжні дані" (між Ім'я та Прізвище), не у нашому блоці.

            html += '<p class="form-row form-row-wide uads-field uads-field-street"><label>' + i18n.streetLabel + ' <abbr class="required">*</abbr></label>';
            html += '<select class="uads-street-select" style="width:100%" disabled><option></option></select>';
            html += '<input type="hidden" name="uads_address_street_ref" class="uads-street-ref" />';
            html += '<input type="hidden" name="uads_address_street_name" class="uads-street-name" />';
            html += '</p>';

            html += '<p class="form-row form-row-first uads-field"><label>' + i18n.houseLabel + ' <abbr class="required">*</abbr></label>';
            html += '<input type="text" name="uads_address_house" class="input-text" /></p>';
            html += '<p class="form-row form-row-last uads-field"><label>' + i18n.flatLabel + '</label>';
            html += '<input type="text" name="uads_address_flat" class="input-text" /></p>';
        }

        html += '<p class="form-row form-row-wide uads-field uads-field-phone"><label>' + i18n.phoneLabel + ' <abbr class="required">*</abbr></label>';
        html += '<input type="tel" name="uads_recipient_phone" class="input-text uads-phone-input" placeholder="' + i18n.phonePlaceholder + '" inputmode="tel" maxlength="13" />';
        html += '<span class="uads-phone-hint" style="font-size:0.85em;color:#888;display:block;margin-top:2px;">+380XXXXXXXXX або 0XXXXXXXXX</span>';
        html += '</p>';
        html += '</div>';

        $(FIELDS_SEL).html(html);
        initSelect2(kind);
    }

    function initSelect2(kind) {
        var $cityEl = $(ROOT_SEL + ' .uads-city-select');
        var category = (kind === 'poshtomat') ? 'poshtomat' : 'warehouse';

        $cityEl.selectWoo({
            placeholder: UADS_CHECKOUT.i18n.searchCity,
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: UADS_CHECKOUT.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'uads_search_cities',
                        _wpnonce: UADS_CHECKOUT.nonce,
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
            var data = e.params.data;
            var resolvedCity = data.city_ref || data.ref;
            $(ROOT_SEL + ' .uads-city-ref').val(resolvedCity);
            $(ROOT_SEL + ' .uads-city-name').val(data.name);
            $('#billing_city').val(data.name);

            if (kind === 'warehouse' || kind === 'poshtomat') {
                initWarehouseSelect2(resolvedCity, category);
            } else if (kind === 'address') {
                initStreetSelect2(resolvedCity);
            }
            $(document.body).trigger('update_checkout');
        });

        $cityEl.on('select2:clear', function () {
            $(ROOT_SEL + ' .uads-city-ref').val('');
            $(ROOT_SEL + ' .uads-city-name').val('');
            $(ROOT_SEL + ' .uads-wh-select').empty().prop('disabled', true).trigger('change');
            $(ROOT_SEL + ' .uads-street-select').empty().prop('disabled', true).trigger('change');
            $(ROOT_SEL + ' .uads-street-ref').val('');
            $(ROOT_SEL + ' .uads-street-name').val('');
            $(document.body).trigger('update_checkout');
        });
    }

    function initStreetSelect2(cityRef) {
        var $stEl = $(ROOT_SEL + ' .uads-street-select');
        if (!$stEl.length) return;

        $stEl.empty().prop('disabled', false);
        if ($stEl.data('select2')) {
            $stEl.selectWoo('destroy');
        }

        $stEl.selectWoo({
            placeholder: 'Введіть назву вулиці...',
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: UADS_CHECKOUT.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'uads_search_streets',
                        _wpnonce: UADS_CHECKOUT.nonce,
                        city_ref: cityRef,
                        term: params.term || ''
                    };
                },
                processResults: function (response) {
                    if (!response.success) return { results: [] };
                    return { results: response.data || [] };
                }
            }
        });

        $stEl.on('select2:select', function (e) {
            var d = e.params.data;
            $(ROOT_SEL + ' .uads-street-ref').val(d.ref);
            $(ROOT_SEL + ' .uads-street-name').val(d.name);
        });
        $stEl.on('select2:clear', function () {
            $(ROOT_SEL + ' .uads-street-ref').val('');
            $(ROOT_SEL + ' .uads-street-name').val('');
        });
    }

    function initWarehouseSelect2(cityRef, category) {
        var $whEl = $(ROOT_SEL + ' .uads-wh-select');
        if (!$whEl.length) return;

        $whEl.empty().prop('disabled', false);

        if ($whEl.data('select2')) {
            $whEl.selectWoo('destroy');
        }

        $whEl.selectWoo({
            placeholder: UADS_CHECKOUT.i18n.searchWarehouse,
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: UADS_CHECKOUT.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'uads_search_warehouses',
                        _wpnonce: UADS_CHECKOUT.nonce,
                        city_ref: cityRef,
                        term: params.term || '',
                        category: category
                    };
                },
                processResults: function (response) {
                    if (!response.success) return { results: [] };
                    return { results: response.data || [] };
                }
            }
        });

        $whEl.on('select2:select', function (e) {
            var data = e.params.data;
            $(ROOT_SEL + ' .uads-wh-ref').val(data.ref);
            $(ROOT_SEL + ' .uads-wh-number').val(data.number);
            $(ROOT_SEL + ' .uads-wh-desc').val(data.text);
        });

        $whEl.on('select2:clear', function () {
            $(ROOT_SEL + ' .uads-wh-ref').val('');
            $(ROOT_SEL + ' .uads-wh-number').val('');
            $(ROOT_SEL + ' .uads-wh-desc').val('');
        });
    }

    var lastKind = null;

    function applyBodyClass(kind) {
        var $b = $(document.body);
        $b.removeClass('uads-np-active uads-np-warehouse uads-np-poshtomat uads-np-address');
        if (kind) {
            $b.addClass('uads-np-active uads-np-' + kind);
        }
        // Drop HTML5 required attribute on standard WC fields we replace, otherwise
        // browser blocks Place Order with "Please fill in this field" prompt.
        var hidden = ['#billing_country', '#billing_state', '#billing_postcode',
                      '#billing_address_1', '#billing_address_2', '#billing_city',
                      '#billing_phone', '#billing_email'];
        if (kind) {
            hidden.forEach(function (sel) { $(sel).removeAttr('required').prop('required', false); });
        }

        // Toggle required state of billing_middle_name (registered as WC standard field).
        // For address (courier) it's required → show red asterisk; for warehouse/poshtomat — optional.
        var $middleField = $('#billing_middle_name_field');
        var $middleInput = $('#billing_middle_name');
        var $middleLabel = $middleField.find('label').first();
        if ($middleInput.length) {
            if (kind === 'address') {
                $middleInput.prop('required', true).attr('required', 'required');
                $middleField.addClass('validate-required');
                $middleLabel.find('.optional').hide();
                if (!$middleLabel.find('abbr.required').length) {
                    $middleLabel.append(' <abbr class="required" title="' + 'required' + '">*</abbr>');
                }
            } else {
                $middleInput.prop('required', false).removeAttr('required');
                $middleField.removeClass('validate-required');
                $middleLabel.find('abbr.required').remove();
                // Always hide "(optional)" — controlled by CSS too
                $middleLabel.find('.optional').hide();
            }
        }
    }

    function snapshotState() {
        return {
            city_ref:    $(ROOT_SEL + ' .uads-city-ref').val()  || '',
            city_name:   $(ROOT_SEL + ' .uads-city-name').val() || '',
            phone:       $(ROOT_SEL + ' input[name="uads_recipient_phone"]').val() || '',
            street_ref:  $(ROOT_SEL + ' .uads-street-ref').val()  || '',
            street_name: $(ROOT_SEL + ' .uads-street-name').val() || '',
            house:       $(ROOT_SEL + ' input[name="uads_address_house"]').val() || '',
            flat:        $(ROOT_SEL + ' input[name="uads_address_flat"]').val()  || ''
        };
    }

    function restoreState(state, kind) {
        if (state.phone) {
            $(ROOT_SEL + ' input[name="uads_recipient_phone"]').val(state.phone);
        }

        // Restore city across any kind (city is needed in all methods)
        if (state.city_ref && state.city_name) {
            var $city = $(ROOT_SEL + ' .uads-city-select');
            if ($city.length) {
                var opt = new Option(state.city_name, state.city_ref, true, true);
                $city.append(opt).trigger('change');
                $(ROOT_SEL + ' .uads-city-ref').val(state.city_ref);
                $(ROOT_SEL + ' .uads-city-name').val(state.city_name);

                // Re-init warehouse select2 with new include_poshtomats filter
                if (kind === 'warehouse' || kind === 'poshtomat') {
                    initWarehouseSelect2(state.city_ref, kind);
                }
            }
        }

        // Restore address fields when switching TO address
        if (kind === 'address') {
            if (state.house)  $(ROOT_SEL + ' input[name="uads_address_house"]').val(state.house);
            if (state.flat)   $(ROOT_SEL + ' input[name="uads_address_flat"]').val(state.flat);

            if (state.city_ref && state.street_ref && state.street_name) {
                // city_ref restore вище ініціалізує street select; pre-populate street option
                var $st = $(ROOT_SEL + ' .uads-street-select');
                if ($st.length) {
                    var opt = new Option(state.street_name, state.street_ref, true, true);
                    $st.append(opt).trigger('change');
                    $(ROOT_SEL + ' .uads-street-ref').val(state.street_ref);
                    $(ROOT_SEL + ' .uads-street-name').val(state.street_name);
                }
            }
        }
    }

    function refresh(force) {
        var methodId = getChosenMethod();
        var kind = detectKind(methodId);
        applyBodyClass(kind);
        syncSelectorRadio();
        if (!force && kind === lastKind) {
            // Same shipping method — leave existing select2 widgets and user input untouched.
            return;
        }

        // Save state before rebuild so user doesn't lose city/phone/address when switching methods
        var saved = (lastKind !== null) ? snapshotState() : null;

        lastKind = kind;
        renderFields(kind);

        if (saved) {
            restoreState(saved, kind);
        }
    }

    // updated_checkout fires after every WC AJAX recalc — don't rebuild fields if method didn't change.
    // Phone validation visual feedback
    $(document.body).on('blur', '.uads-phone-input', function () {
        var v = String(this.value || '').replace(/[\s\-()]/g, '');
        var ok = /^(\+?38)?0\d{9}$/.test(v);
        $(this).css('border-color', v === '' ? '' : (ok ? '' : '#b32d2e'));
        var $hint = $(this).next('.uads-phone-hint');
        if (v === '' || ok) {
            $hint.css('color', '#888').text('+380XXXXXXXXX або 0XXXXXXXXX');
        } else {
            $hint.css('color', '#b32d2e').text('⚠️ Невірний формат. Введіть +380XXXXXXXXX (12 цифр з кодом країни) або 0XXXXXXXXX');
        }
    });

    $(document.body).on('updated_checkout', function () { refresh(false); });
    // Method radio change → must rebuild (different kind = different fields).
    $(document.body).on('change', 'input[name^="shipping_method"]', function () { refresh(true); });
    bindSelectorClick();
    $(function () { setTimeout(function () { refresh(true); }, 200); });
}(jQuery));
