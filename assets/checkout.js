(function($) {
    var isUserInteractingWithSelect = false;
    var interactionResetTimer = null;

    function normalizeLabel(text) {
        return (text || '').replace(/\s+/g, ' ').trim();
    }

    function extractCarrierName($li, labelText) {
        var marker = $li.find('.scdc-carrier-name').first().data('scdc-carrier');
        if (marker) {
            return String(marker).trim();
        }
        var fallback = normalizeLabel(labelText).split(' ')[0] || 'Delivery';
        return fallback.trim();
    }

    function detectSimpleType(labelText) {
        var txt = normalizeLabel(labelText).toLowerCase();
        if (/(service point|pickup|drop ?off|punt|shop|parcelshop|locker)/i.test(txt)) {
            return 'Pickup Point';
        }
        if (/(mailbox|letter|brief)/i.test(txt)) {
            return 'Mailbox';
        }
        return 'Home Delivery';
    }

    function extractPrice(labelText) {
        var txt = normalizeLabel(labelText);
        var match = txt.match(/(€\s*[\d.,]+)/);
        return match ? match[1].replace(/\s+/g, ' ').trim() : '';
    }

    function extractArrivalText(labelText) {
        var txt = normalizeLabel(labelText);
        var match = txt.match(/(Arrives[^)]+(?:\)|$))/i);
        if (!match) return '';
        return String(match[1]).replace(/\)$/, '').trim();
    }

    function priceToNumber(priceText) {
        if (!priceText) return Number.POSITIVE_INFINITY;
        var raw = String(priceText).replace(/[^\d.,-]/g, '');
        if (!raw) return Number.POSITIVE_INFINITY;
        // Convert "6,71" / "6.71" / "1.234,56" to a float.
        raw = raw.replace(/\./g, '').replace(',', '.');
        var num = parseFloat(raw);
        return isNaN(num) ? Number.POSITIVE_INFINITY : num;
    }

    function buildSimpleCustomerLabel($li, rawLabelText) {
        var carrier = extractCarrierName($li, rawLabelText);
        var type = detectSimpleType(rawLabelText);
        var price = extractPrice(rawLabelText);
        var arrival = extractArrivalText(rawLabelText);
        var label = carrier + ' - ' + type;
        if (price) {
            label += ' (' + price + ')';
        }
        if (arrival) {
            label += ' - ' + arrival;
        }
        return label;
    }

    function buildGroupedDropdown($methodList) {
        var $sendcloudLis = $methodList.find('> li').filter(function() {
            var value = $(this).find('input[name^="shipping_method"]').val() || '';
            return value.indexOf('sendcloud_dynamic_checkout') !== -1;
        });

        if (isUserInteractingWithSelect) return;

        $methodList.find('> li.scdc-dropdown-row').remove();
        $methodList.find('> li').show().removeClass('scdc-hidden-by-dropdown');

        if ($sendcloudLis.length <= 1) {
            return;
        }

        var selectedValue = $sendcloudLis.find('input[name^="shipping_method"]:checked').val() || $sendcloudLis.first().find('input[name^="shipping_method"]').val();
        var items = [];
        var pickupDetailsByValue = {};
        var cheapestByCarrierType = {};

        $sendcloudLis.each(function() {
            var $li = $(this);
            var $input = $li.find('input[name^="shipping_method"]').first();
            var value = $input.val();
            var labelText = normalizeLabel($li.find('label').first().text());
            var isStorePickup = value.indexOf('scdc_store_pickup') !== -1;
            if (isStorePickup) {
                var marker = $li.find('.scdc-pickup-details').first();
                pickupDetailsByValue[value] = {
                    address: marker.data('scdc-address') || '',
                    hours: marker.data('scdc-hours') || ''
                };
            }
            if (isStorePickup) {
                items.push({
                    value: value,
                    label: labelText
                });
                return;
            }

            var carrier = extractCarrierName($li, labelText);
            var type = detectSimpleType(labelText);
            var priceText = extractPrice(labelText);
            var candidate = {
                value: value,
                label: buildSimpleCustomerLabel($li, labelText),
                price: priceToNumber(priceText)
            };
            var key = carrier + '|' + type;
            if (!cheapestByCarrierType[key] || candidate.price < cheapestByCarrierType[key].price) {
                cheapestByCarrierType[key] = candidate;
            }
        });

        Object.keys(cheapestByCarrierType).forEach(function(key) {
            items.push({
                value: cheapestByCarrierType[key].value,
                label: cheapestByCarrierType[key].label,
                price: cheapestByCarrierType[key].price
            });
        });

        // Keep store pickup first, then sort remaining options by cheapest price.
        items.sort(function(a, b) {
            var aIsPickup = String(a.value || '').indexOf('scdc_store_pickup') !== -1;
            var bIsPickup = String(b.value || '').indexOf('scdc_store_pickup') !== -1;
            if (aIsPickup && !bIsPickup) return -1;
            if (!aIsPickup && bIsPickup) return 1;
            var aPrice = typeof a.price === 'number' ? a.price : Number.POSITIVE_INFINITY;
            var bPrice = typeof b.price === 'number' ? b.price : Number.POSITIVE_INFINITY;
            return aPrice - bPrice;
        });

        var stillExists = items.some(function(item) { return item.value === selectedValue; });
        if (!stillExists && items.length > 0) {
            selectedValue = items[0].value;
        }

        var $select = $('<select class="scdc-shipping-select"></select>');
        items.forEach(function(item) {
            var $opt = $('<option></option>').attr('value', item.value).text(item.label);
            if (item.value === selectedValue) {
                $opt.prop('selected', true);
            }
            $select.append($opt);
        });

        var $row = $('<li class="scdc-dropdown-row"><label>Delivery</label></li>');
        $row.append($select);
        var $details = $('<div class="scdc-pickup-info" style="display:none;"></div>');
        $row.append($details);
        $sendcloudLis.first().before($row);
        $sendcloudLis.hide().addClass('scdc-hidden-by-dropdown');

        function refreshPickupDetails() {
            var selected = $select.val();
            var data = pickupDetailsByValue[selected];
            if (!data) {
                $details.hide().html('');
                return;
            }
            var fallbackAddress = (typeof scdcCheckout !== 'undefined' && scdcCheckout.pickupAddress) ? String(scdcCheckout.pickupAddress) : '';
            var fallbackHours = (typeof scdcCheckout !== 'undefined' && scdcCheckout.pickupHours) ? String(scdcCheckout.pickupHours) : '';
            var address = (data.address || '').toString().trim() || fallbackAddress;
            var hours = (data.hours || '').toString().trim() || fallbackHours;

            if (!address && !hours) {
                $details.hide().html('');
                return;
            }

            var html = '<strong>Pickup from Store</strong>';
            if (address) {
                html += '<div class="scdc-pickup-line">' + address + '</div>';
            }
            if (hours) {
                html += '<div class="scdc-pickup-line">Opening hours: ' + hours + '</div>';
            }
            $details.html(html).show();
        }

        $select.on('change', function() {
            var chosen = $(this).val();
            $sendcloudLis.each(function() {
                var $li = $(this);
                var $input = $li.find('input[name^="shipping_method"]').first();
                $input.prop('checked', $input.val() === chosen);
            });
            $sendcloudLis.find('input[name^="shipping_method"]:checked').trigger('change');
            refreshPickupDetails();
        });

        $select.on('focus mousedown touchstart', function() {
            isUserInteractingWithSelect = true;
            if (interactionResetTimer) clearTimeout(interactionResetTimer);
            interactionResetTimer = setTimeout(function() {
                isUserInteractingWithSelect = false;
            }, 1200);
        });
        $select.on('blur', function() {
            setTimeout(function() {
                isUserInteractingWithSelect = false;
            }, 150);
        });

        refreshPickupDetails();
    }

    function renderApiWarning($methodList) {
        if (!$methodList || !$methodList.length) return;
        $methodList.prev('.scdc-shipping-warning').remove();
        var warning = (typeof scdcCheckout !== 'undefined' && scdcCheckout.apiWarning) ? String(scdcCheckout.apiWarning).trim() : '';
        if (!warning) return;
        $methodList.before($('<div class="scdc-shipping-warning"></div>').text(warning));
    }

    function initGroupedDropdown() {
        $('ul#shipping_method, ul.shipping_method, ul.woocommerce-shipping-methods').each(function() {
            var $list = $(this);
            renderApiWarning($list);
            buildGroupedDropdown($list);
        });
    }

    $(document.body).on('updated_checkout updated_shipping_method', function() {
        // Woo can rebuild the shipping block while the dropdown is focused.
        // Force unlock so we always re-apply dropdown mode after refresh.
        isUserInteractingWithSelect = false;
        initGroupedDropdown();
    });
    $(document).ready(initGroupedDropdown);
    $(document).on('updated_wc_div', function() {
        isUserInteractingWithSelect = false;
        initGroupedDropdown();
    });

    if (typeof MutationObserver !== 'undefined') {
        var timer = null;
        var observer = new MutationObserver(function() {
            if (isUserInteractingWithSelect) {
                return;
            }
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(function() {
                if (!isUserInteractingWithSelect) {
                    initGroupedDropdown();
                }
            }, 200);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})(jQuery);
