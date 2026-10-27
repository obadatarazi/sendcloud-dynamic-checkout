(function($) {
    function closestRow(el) { return el ? el.closest('tr') : null; }
    function setRowVisibility(row, isVisible) {
        if (!row) return;
        row.style.display = isVisible ? '' : 'none';
        row.classList.toggle('scdc-is-disabled', !isVisible);
    }
    function fieldBySuffix(suffix) {
        return document.querySelector('input[id$="' + suffix + '"], select[id$="' + suffix + '"]');
    }
    function fieldValue(suffix, fallback) {
        var field = fieldBySuffix(suffix);
        if (!field) return fallback || '';
        if (field.type === 'checkbox') return field.checked ? 'yes' : 'no';
        return field.value || fallback || '';
    }
    function boolPill(isOn) {
        return '<span class="scdc-pill ' + (isOn ? 'scdc-pill-on' : 'scdc-pill-off') + '">' + (isOn ? 'ON' : 'OFF') + '</span>';
    }
    function mountStatusCard(table) {
        var card = document.createElement('div');
        card.className = 'scdc-status-card';
        card.innerHTML = '<h4>Sendcloud Dynamic Checkout Control Center</h4><div class="scdc-status-grid" id="scdc-status-grid"></div><div class="scdc-warning-note" id="scdc-warning-note" style="display:none;"></div>';
        table.parentNode.insertBefore(card, table);
        return card;
    }
    function enhanceSectionTitles(table) {
        var rows = table.querySelectorAll('.forminp-title');
        var sectionMeta = {
            'checkout mode': { badge: 'Mode', hint: 'Pick the source of rates shown to shoppers.' },
            'sendcloud credentials': { badge: 'Auth', hint: 'Credentials are required only when Sendcloud mode is active.' },
            'sender address inputs': { badge: 'Source', hint: 'Used by carriers to calculate zonal and remote pricing.' },
            'fallback parcel dimensions': { badge: 'Parcel', hint: 'Applied only when products do not have dimensions.' },
            'pricing and display': { badge: 'Rules', hint: 'Configure markup, free-shipping threshold, and label output.' }
        };
        rows.forEach(function(row) {
            var heading = row.querySelector('h3');
            var cell = row.querySelector('th');
            if (!heading || !cell) return;
            var key = (heading.textContent || '').trim().toLowerCase();
            var meta = sectionMeta[key];
            if (!meta) return;
            if (!heading.querySelector('.scdc-badge')) {
                var badge = document.createElement('span');
                badge.className = 'scdc-badge';
                badge.textContent = meta.badge;
                heading.appendChild(badge);
            }
            if (!cell.querySelector('.scdc-title-meta')) {
                var helper = document.createElement('div');
                helper.className = 'scdc-title-meta';
                helper.textContent = meta.hint;
                cell.appendChild(helper);
            }
        });
    }
    function renderStatusCard(card) {
        var sendcloudEnabled = fieldValue('_use_sendcloud_rates') === 'yes';
        var hideOthers = fieldValue('_hide_other_methods') === 'yes';
        var freeEnabled = fieldValue('_free_shipping_enabled') === 'yes';
        var threshold = parseFloat(fieldValue('_free_shipping_threshold', '0') || '0');
        var markupType = fieldValue('_markup_type', 'none');
        var markupValue = fieldValue('_markup_value', '0');
        var grid = card.querySelector('#scdc-status-grid');
        if (grid) {
            grid.innerHTML = [
                '<div class="scdc-status-item"><strong>Rate Source</strong>' + (sendcloudEnabled ? 'Sendcloud API' : 'WooCommerce Zones') + boolPill(sendcloudEnabled) + '</div>',
                '<div class="scdc-status-item"><strong>Method Visibility</strong>' + (hideOthers ? 'Only Sendcloud shown' : 'All methods shown') + boolPill(hideOthers) + '</div>',
                '<div class="scdc-status-item"><strong>Free Shipping</strong>' + (freeEnabled ? 'Threshold enabled' : 'Disabled') + boolPill(freeEnabled) + '</div>',
                '<div class="scdc-status-item"><strong>Threshold</strong>' + (isNaN(threshold) ? '0.00' : threshold.toFixed(2)) + '</div>',
                '<div class="scdc-status-item"><strong>Price Adjustment</strong>' + markupType + ' (' + markupValue + ')</div>'
            ].join('');
        }
        var warning = card.querySelector('#scdc-warning-note');
        if (!warning) return;
        if (sendcloudEnabled && freeEnabled && (isNaN(threshold) || threshold <= 0)) {
            warning.style.display = 'block';
            warning.textContent = 'Free shipping is enabled with a threshold of 0. This can make all Sendcloud rates free.';
        } else {
            warning.style.display = 'none';
            warning.textContent = '';
        }
    }
    function initShippingSettingsUi() {
        if (!scdcAdmin || !scdcAdmin.isShippingPage) return;
        var useSendcloud = document.querySelector('input[id$="_use_sendcloud_rates"]');
        if (!useSendcloud) return;
        var table = useSendcloud.closest('table');
        if (table) {
            table.classList.add('scdc-modern-settings');
            enhanceSectionTitles(table);
        }
        var statusCard = table ? mountStatusCard(table) : null;
        var freeShipping = document.querySelector('input[id$="_free_shipping_enabled"]');
        var dependent = ['_api_key','_api_secret','_from_country','_from_postal_code','_default_length','_default_width','_default_height','_markup_type','_markup_value','_free_shipping_enabled','_show_carrier_name','_show_description','_enable_caching','_hide_other_methods','_checkout_only_shipping'];
        var freeFields = ['_free_shipping_threshold','_free_shipping_label'];
        function refresh() {
            var sendcloudEnabled = !!useSendcloud.checked;
            dependent.forEach(function(s) { setRowVisibility(closestRow(fieldBySuffix(s)), sendcloudEnabled); });
            var freeEnabled = freeShipping && freeShipping.checked && sendcloudEnabled;
            freeFields.forEach(function(s) { setRowVisibility(closestRow(fieldBySuffix(s)), freeEnabled); });
            if (statusCard) renderStatusCard(statusCard);
        }
        useSendcloud.addEventListener('change', refresh);
        if (freeShipping) freeShipping.addEventListener('change', refresh);
        ['_hide_other_methods','_free_shipping_threshold','_markup_type','_markup_value'].forEach(function(s) {
            var field = fieldBySuffix(s);
            if (field) { field.addEventListener('change', refresh); field.addEventListener('input', refresh); }
        });
        refresh();
    }
    function initGlobalSettingsUi() {
        if (!scdcAdmin || !scdcAdmin.isGlobalPage) return;
        var copyButton = document.getElementById('scdc-copy-webhook-url');
        var urlInput = document.getElementById('scdc-webhook-url');
        var feedback = document.getElementById('scdc-copy-feedback');
        function setRows(selector, visible) {
            document.querySelectorAll(selector).forEach(function(row) {
                row.style.display = visible ? '' : 'none';
                row.classList.toggle('scdc-hidden-row', !visible);
            });
        }
        function refreshRows() {
            var useSendcloud = document.getElementById('scdc-global-use-sendcloud');
            var freeEnabled = document.getElementById('scdc-global-free-enabled');
            var sendcloudOn = !!(useSendcloud && useSendcloud.checked);
            var freeOn = !!(freeEnabled && freeEnabled.checked);
            setRows('.scdc-sendcloud-dependent', sendcloudOn);
            setRows('.scdc-free-dependent', sendcloudOn && freeOn);
        }
        if (copyButton && urlInput && feedback) {
            copyButton.addEventListener('click', function() {
                var value = urlInput.value || '';
                if (!value) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function(){ feedback.textContent='Copied'; setTimeout(function(){feedback.textContent='';},1800); });
                } else {
                    urlInput.select(); urlInput.setSelectionRange(0, 99999);
                    try { document.execCommand('copy'); feedback.textContent='Copied'; } catch (e) { feedback.textContent='Copy failed'; }
                    setTimeout(function(){feedback.textContent='';},1800);
                }
            });
        }
        var useSendcloud = document.getElementById('scdc-global-use-sendcloud');
        var freeEnabled = document.getElementById('scdc-global-free-enabled');
        if (useSendcloud) useSendcloud.addEventListener('change', refreshRows);
        if (freeEnabled) freeEnabled.addEventListener('change', refreshRows);
        refreshRows();

        var refreshBtn = document.getElementById('scdc-refresh-carriers');
        var carriersSelect = document.getElementById('scdc-carriers-select');
        var refreshMsg = document.getElementById('scdc-refresh-carriers-msg');
        var carriersHint = carriersSelect ? carriersSelect.parentNode.querySelector('.description') : null;

        function initCarrierSelectEnhancement() {
            if (!carriersSelect || typeof jQuery === 'undefined') return;
            var $select = jQuery(carriersSelect);
            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }
            if (jQuery.fn.selectWoo) {
                $select.selectWoo({
                    width: 'resolve',
                    placeholder: 'Select carriers to show at checkout',
                    allowClear: false
                });
            } else if (jQuery.fn.select2) {
                $select.select2({
                    width: 'resolve',
                    placeholder: 'Select carriers to show at checkout',
                    allowClear: false
                });
            }
        }

        function refreshCarrierCountHint() {
            if (!carriersSelect || !carriersHint) return;
            var selectedCount = Array.from(carriersSelect.options).filter(function(o){ return o.selected; }).length;
            if (selectedCount === 0) {
                carriersHint.textContent = 'No carrier selected: all available carriers will be shown at checkout.';
            } else {
                carriersHint.textContent = selectedCount + ' carrier(s) selected. Only these carriers will be shown at checkout.';
            }
        }

        if (carriersSelect) {
            carriersSelect.addEventListener('change', refreshCarrierCountHint);
            refreshCarrierCountHint();
            initCarrierSelectEnhancement();
        }

        if (refreshBtn && carriersSelect && refreshMsg) {
            refreshBtn.addEventListener('click', function() {
                refreshMsg.textContent = 'Loading...';
                refreshMsg.style.color = '#1d4ed8';
                var form = new FormData();
                form.append('action', 'scdc_fetch_carriers');
                form.append('nonce', scdcAdmin.nonce);
                fetch(scdcAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form })
                    .then(function(r){ return r.json(); })
                    .then(function(payload){
                        if (!payload || !payload.success) {
                            refreshMsg.textContent = (payload && payload.data && payload.data.message) ? payload.data.message : 'Failed to refresh carriers';
                            refreshMsg.style.color = '#b91c1c';
                            return;
                        }
                        var selected = Array.from(carriersSelect.selectedOptions).map(function(o){ return o.value; });
                        carriersSelect.innerHTML = '';
                        var carriers = (payload.data && payload.data.carriers) ? payload.data.carriers : {};
                        Object.keys(carriers).forEach(function(code){
                            var opt = document.createElement('option');
                            opt.value = code;
                            opt.textContent = carriers[code] + ' (' + code + ')';
                            if (selected.indexOf(code) !== -1) opt.selected = true;
                            carriersSelect.appendChild(opt);
                        });
                        if (typeof jQuery !== 'undefined') {
                            var $sel = jQuery(carriersSelect);
                            $sel.trigger('change.select2');
                            $sel.trigger('change');
                        }
                        refreshCarrierCountHint();
                        refreshMsg.textContent = 'Carriers refreshed';
                        refreshMsg.style.color = '#15803d';
                    })
                    .catch(function(){
                        refreshMsg.textContent = 'Network error while refreshing carriers';
                        refreshMsg.style.color = '#b91c1c';
                    });
            });
        }
    }

    $(document).ready(function() {
        initGlobalSettingsUi();
        initShippingSettingsUi();
    });
})(jQuery);
