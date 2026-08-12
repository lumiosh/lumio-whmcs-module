(function (window, document) {
    'use strict';

    var rootId = 'divModuleSettings';
    var panelId = 'lumio-product-mapper';
    var cycleFields = {
        month: 4,
        quarter: 5,
        semiannual: 6,
        year: 7,
        biennial: 8,
        triennial: 9
    };
    var cycleLabels = {
        month: 'Monthly',
        quarter: 'Quarterly',
        semiannual: 'Semi-Annually',
        year: 'Annually',
        biennial: 'Biennially',
        triennial: 'Triennially'
    };
    var currentState = null;
    var observer = null;
    var scheduled = false;

    function scheduleBoot() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.setTimeout(function () {
            scheduled = false;
            boot();
        }, 0);
    }

    function closestTag(element, tagName) {
        tagName = String(tagName).toLowerCase();
        while (element && element !== document.body) {
            if (String(element.tagName).toLowerCase() === tagName) {
                return element;
            }
            element = element.parentNode;
        }
        return null;
    }

    function optionBinding(root, index) {
        var marker = root.querySelector('.lumio-option-marker[data-lumio-option="' + index + '"]');
        if (!marker) {
            return null;
        }
        var cell = closestTag(marker, 'td');
        var row = closestTag(marker, 'tr');
        if (!row) {
            return null;
        }
        var field = cell || row;
        var controls = field.querySelectorAll('input, textarea, select');
        for (var i = 0; i < controls.length; i += 1) {
            var type = String(controls[i].getAttribute('type') || '').toLowerCase();
            if (type !== 'hidden' && type !== 'button' && type !== 'submit') {
                return {
                    marker: marker,
                    row: row,
                    field: cell,
                    label: cell ? cell.previousElementSibling : null,
                    control: controls[i]
                };
            }
        }
        return null;
    }

    function optionBindings(root) {
        var bindings = {};
        for (var index = 1; index <= 12; index += 1) {
            var binding = optionBinding(root, index);
            if (!binding) {
                return null;
            }
            bindings[index] = binding;
        }
        return bindings;
    }

    function setRawRowsVisible(bindings, visible) {
        for (var index = 1; index <= 9; index += 1) {
            var binding = bindings[index];
            if (binding.field && binding.label) {
                binding.label.style.display = visible ? '' : 'none';
                binding.field.style.display = visible ? '' : 'none';
            } else {
                binding.row.style.display = visible ? '' : 'none';
            }
        }
    }

    function hideCompatibilityRows(bindings) {
        for (var index = 10; index <= 11; index += 1) {
            var binding = bindings[index];
            if (binding.field && binding.label) {
                binding.label.style.display = 'none';
                binding.field.style.display = 'none';
            } else {
                binding.row.style.display = 'none';
            }
        }
    }

    function clear(element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (typeof text === 'string') {
            node.textContent = text;
        }
        return node;
    }

    function formatUsd(cents) {
        var sign = cents < 0 ? '-' : '';
        var absolute = Math.abs(cents);
        return sign + 'USD ' + Math.floor(absolute / 100) + '.' + String(absolute % 100).padStart(2, '0');
    }

    function availabilityText(product) {
        return product && product.availability === 'available'
            ? 'Availability: Available'
            : 'Availability: Unavailable';
    }

    function parseConfiguration(value) {
        if (!value) {
            return {};
        }
        try {
            var parsed = JSON.parse(value);
            if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
                return {};
            }
            return parsed;
        } catch (error) {
            return {};
        }
    }

    function parseAddonIds(value) {
        if (!value) {
            return [];
        }
        return String(value).split(',').map(function (part) {
            return Number(String(part).trim());
        }).filter(function (id, index, ids) {
            return Number.isInteger(id) && id > 0 && ids.indexOf(id) === index;
        });
    }

    function productBySku(products, sku) {
        for (var i = 0; i < products.length; i += 1) {
            if (products[i].sku === sku) {
                return products[i];
            }
        }
        return null;
    }

    function priceForCycle(product, cycle) {
        for (var i = 0; i < product.prices.length; i += 1) {
            if (product.prices[i].billing_cycle === cycle) {
                return product.prices[i];
            }
        }
        return null;
    }

    function createStatus(kind, message, requestId) {
        var status = element('div', 'lumio-mapper-status lumio-mapper-status-' + kind);
        status.textContent = message;
        if (requestId) {
            var request = element('span', 'lumio-mapper-request', 'Request-Id: ' + requestId);
            status.appendChild(request);
        }
        return status;
    }

    function addStyles() {
        if (document.getElementById('lumio-product-mapper-styles')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'lumio-product-mapper-styles';
        style.textContent = [
            '#lumio-product-mapper{margin:0 0 16px;padding:18px;border:1px solid #d7e2ec;border-radius:6px;background:#f8fbfd;text-align:left}',
            '.lumio-mapper-head{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}',
            '.lumio-mapper-title{margin:0;color:#263746;font-size:17px;font-weight:600}',
            '.lumio-mapper-subtitle{margin:4px 0 0;color:#667786;font-size:12px;line-height:1.6}',
            '.lumio-mapper-refresh{flex:0 0 auto;border:1px solid #aebdca;border-radius:4px;background:#fff;color:#334b5f;padding:7px 12px;cursor:pointer}',
            '.lumio-mapper-refresh:disabled{cursor:wait;opacity:.65}',
            '.lumio-mapper-status{margin:0 0 14px;padding:10px 12px;border-radius:4px;line-height:1.55}',
            '.lumio-mapper-status-ready{border:1px solid #b9dfc8;background:#eef9f2;color:#24663d}',
            '.lumio-mapper-status-warning{border:1px solid #edd59b;background:#fff9e9;color:#765616}',
            '.lumio-mapper-status-error{border:1px solid #edb9b9;background:#fff1f1;color:#8b2d2d}',
            '.lumio-mapper-request{display:block;margin-top:3px;font-family:monospace;font-size:11px}',
            '.lumio-mapper-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}',
            '.lumio-mapper-field{min-width:0}',
            '.lumio-mapper-field-wide{grid-column:1/-1}',
            '.lumio-mapper-label{display:block;margin:0 0 6px;color:#314657;font-weight:600}',
            '.lumio-mapper-required{margin-left:4px;color:#c0392b}',
            '.lumio-mapper-select{display:block;width:100%;height:36px;border:1px solid #bdcbd7;border-radius:4px;background:#fff;padding:6px 9px;color:#263746}',
            '.lumio-mapper-note{margin-top:6px;color:#6a7b89;font-size:12px;line-height:1.5}',
            '.lumio-mapper-choice-list{display:flex;flex-wrap:wrap;gap:8px 16px;padding:9px 10px;border:1px solid #d5e0e8;border-radius:4px;background:#fff}',
            '.lumio-mapper-choice{display:flex;align-items:flex-start;gap:7px;margin:0;font-weight:400;line-height:1.45}',
            '.lumio-mapper-choice input{margin:3px 0 0}',
            '.lumio-mapper-costs{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}',
            '.lumio-mapper-cost{padding:9px 10px;border:1px solid #d5e0e8;border-radius:4px;background:#fff}',
            '.lumio-mapper-cost-name{display:block;color:#6b7b88;font-size:11px}',
            '.lumio-mapper-cost-value{display:block;margin-top:2px;color:#263746;font-weight:600}',
            '.lumio-mapper-hidden-warning{margin-top:10px;color:#a05b16;font-size:12px}',
            '@media(max-width:768px){.lumio-mapper-head{display:block}.lumio-mapper-refresh{margin-top:10px}.lumio-mapper-grid{grid-template-columns:1fr}.lumio-mapper-field-wide{grid-column:auto}.lumio-mapper-costs{grid-template-columns:1fr 1fr}}'
        ].join('');
        document.head.appendChild(style);
    }

    function refreshCatalog(button) {
        button.disabled = true;
        button.textContent = 'Refreshing…';
        var productInput = document.getElementById('inputProductId');
        var productId = productInput ? parseInt(productInput.value, 10) : 0;
        if (typeof window.fetchModuleSettings === 'function' && productId > 0) {
            window.fetchModuleSettings(productId);
            return;
        }
        window.location.reload();
    }

    function buildPanelShell(bootstrap) {
        var panel = element('section');
        panel.id = panelId;
        var head = element('div', 'lumio-mapper-head');
        var heading = element('div');
        heading.appendChild(element('h3', 'lumio-mapper-title', 'Lumio Product Mapping'));
        heading.appendChild(element('p', 'lumio-mapper-subtitle', 'Select the Lumio product, product options, and add-ons to sell. The module maintains the product mapping and cost limits automatically.'));
        head.appendChild(heading);
        var refresh = element('button', 'lumio-mapper-refresh', 'Refresh Lumio Products');
        refresh.type = 'button';
        refresh.addEventListener('click', function () {
            refreshCatalog(refresh);
        });
        head.appendChild(refresh);
        panel.appendChild(head);

        if (bootstrap.state !== 'ready') {
            var kind = bootstrap.state === 'error' ? 'error' : 'warning';
            panel.appendChild(createStatus(kind, bootstrap.message, bootstrap.request_id));
        }
        return panel;
    }

    function renderProductControls(state, product, initialConfiguration, initialAddonIds) {
        clear(state.dynamic);
        state.selectedProduct = product;
        state.groupControls = [];
        state.addonControls = [];
        state.mappingWarnings = [];

        if (!product) {
            state.dynamic.appendChild(createStatus('warning', 'Select a Lumio product.'));
            state.invalid = true;
            return;
        }

        var availability = element('div', 'lumio-mapper-note', availabilityText(product));
        if (product.availability === 'unavailable') {
            availability.textContent += ' (new purchases are currently unavailable; the product can still be configured)';
        }
        state.dynamic.appendChild(availability);

        var grid = element('div', 'lumio-mapper-grid');
        product.option_groups.forEach(function (group) {
            var field = element('div', 'lumio-mapper-field');
            var label = element('label', 'lumio-mapper-label', group.name);
            if (group.required) {
                label.appendChild(element('span', 'lumio-mapper-required', '*'));
            }
            field.appendChild(label);
            var saved = Array.isArray(initialConfiguration[String(group.id)])
                ? initialConfiguration[String(group.id)].map(String)
                : [];
            var knownCodes = group.values.map(function (value) { return value.code; });
            var unknownCodes = saved.filter(function (code) { return knownCodes.indexOf(code) === -1; });
            if (unknownCodes.length) {
                state.mappingWarnings.push(group.name + ' contains saved options that are no longer available: ' + unknownCodes.join(', '));
            }

            if (group.input_type === 'checkbox') {
                var list = element('div', 'lumio-mapper-choice-list');
                group.values.forEach(function (value) {
                    var choice = element('label', 'lumio-mapper-choice');
                    var input = document.createElement('input');
                    input.type = 'checkbox';
                    input.value = value.code;
                    input.checked = saved.indexOf(value.code) !== -1;
                    input.setAttribute('data-price-delta', String(value.price_delta_cents));
                    input.addEventListener('change', function () { mappingChanged(state); });
                    choice.appendChild(input);
                    choice.appendChild(element('span', '', value.label + (value.price_delta_cents ? '（' + formatUsd(value.price_delta_cents) + '）' : '')));
                    list.appendChild(choice);
                    state.groupControls.push({ group: group, input: input, value: value });
                });
                field.appendChild(list);
            } else {
                var select = element('select', 'lumio-mapper-select');
                var placeholder = element('option', '', group.required ? 'Select an option' : 'No selection');
                placeholder.value = '';
                select.appendChild(placeholder);
                group.values.forEach(function (value) {
                    var option = element('option', '', value.label + (value.price_delta_cents ? '（' + formatUsd(value.price_delta_cents) + '）' : ''));
                    option.value = value.code;
                    option.setAttribute('data-price-delta', String(value.price_delta_cents));
                    if (saved.indexOf(value.code) !== -1) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                select.addEventListener('change', function () { mappingChanged(state); });
                field.appendChild(select);
                state.groupControls.push({ group: group, input: select, value: null });
            }
            grid.appendChild(field);
        });

        if (product.addons.length) {
            var addonField = element('div', 'lumio-mapper-field lumio-mapper-field-wide');
            addonField.appendChild(element('label', 'lumio-mapper-label', 'Add-ons'));
            var addonList = element('div', 'lumio-mapper-choice-list');
            var knownAddonIds = [];
            product.addons.forEach(function (addon) {
                knownAddonIds.push(addon.id);
                var choice = element('label', 'lumio-mapper-choice');
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.value = String(addon.id);
                input.checked = addon.required || initialAddonIds.indexOf(addon.id) !== -1;
                input.disabled = addon.required;
                input.addEventListener('change', function () { mappingChanged(state); });
                choice.appendChild(input);
                var suffix = addon.billing_type === 'recurring' ? 'recurring fee' : 'one-time fee';
                if (addon.required) {
                    suffix += ', required';
                }
                choice.appendChild(element('span', '', addon.name + '（' + formatUsd(addon.price_cents) + '，' + suffix + '）'));
                addonList.appendChild(choice);
                state.addonControls.push({ addon: addon, input: input });
            });
            initialAddonIds.forEach(function (id) {
                if (knownAddonIds.indexOf(id) === -1) {
                    state.mappingWarnings.push('A saved add-on is no longer available: ' + id);
                }
            });
            addonField.appendChild(addonList);
            grid.appendChild(addonField);
        }

        var costField = element('div', 'lumio-mapper-field lumio-mapper-field-wide');
        costField.appendChild(element('label', 'lumio-mapper-label', 'Lumio Cost Caps (Automatically Calculated)'));
        state.costs = element('div', 'lumio-mapper-costs');
        costField.appendChild(state.costs);
        grid.appendChild(costField);
        state.dynamic.appendChild(grid);

        if (state.mappingWarnings.length) {
            state.dynamic.appendChild(element('div', 'lumio-mapper-hidden-warning', state.mappingWarnings.join('; ') + '. Update the selections before saving.'));
        }
        state.validation = element('div');
        state.dynamic.appendChild(state.validation);
        validateAndPreview(state, false);
    }

    function selectedConfiguration(state) {
        var configuration = {};
        var missing = [];
        var delta = 0;
        var processedSingleGroups = [];
        state.groupControls.forEach(function (entry) {
            var key = String(entry.group.id);
            if (entry.group.input_type === 'checkbox') {
                if (entry.input.checked) {
                    if (!configuration[key]) {
                        configuration[key] = [];
                    }
                    configuration[key].push(entry.value.code);
                    delta += entry.value.price_delta_cents;
                }
                return;
            }
            if (processedSingleGroups.indexOf(key) !== -1) {
                return;
            }
            processedSingleGroups.push(key);
            if (entry.input.value) {
                configuration[key] = [entry.input.value];
                var selected = entry.input.options[entry.input.selectedIndex];
                delta += Number(selected.getAttribute('data-price-delta') || 0);
            }
        });
        state.selectedProduct.option_groups.forEach(function (group) {
            if (group.required && (!configuration[String(group.id)] || configuration[String(group.id)].length === 0)) {
                missing.push(group.name);
            }
        });
        return { configuration: configuration, delta: delta, missing: missing };
    }

    function selectedAddons(state) {
        var ids = [];
        var total = 0;
        state.addonControls.forEach(function (entry) {
            if (entry.addon.required || entry.input.checked) {
                ids.push(entry.addon.id);
                total += entry.addon.price_cents;
            }
        });
        ids.sort(function (left, right) { return left - right; });
        return { ids: ids, total: total };
    }

    function validateAndPreview(state, writeRaw) {
        if (!state.selectedProduct) {
            state.invalid = true;
            return;
        }
        var selection = selectedConfiguration(state);
        var addons = selectedAddons(state);
        var invalid = selection.missing.length > 0 || state.mappingWarnings.length > 0 || state.selectedProduct.prices.length === 0;
        var totals = {};
        Object.keys(cycleFields).forEach(function (cycle) {
            var price = priceForCycle(state.selectedProduct, cycle);
            if (!price) {
                return;
            }
            var total = price.price_cents + price.setup_fee_cents + selection.delta + addons.total;
            if (!Number.isInteger(total) || total < 0 || total > 999999999) {
                invalid = true;
                return;
            }
            totals[cycle] = total;
        });

        clear(state.costs);
        Object.keys(cycleFields).forEach(function (cycle) {
            if (typeof totals[cycle] !== 'number') {
                return;
            }
            var cost = element('div', 'lumio-mapper-cost');
            cost.appendChild(element('span', 'lumio-mapper-cost-name', cycleLabels[cycle]));
            cost.appendChild(element('span', 'lumio-mapper-cost-value', formatUsd(totals[cycle])));
            state.costs.appendChild(cost);
        });
        if (!state.costs.firstChild) {
            state.costs.appendChild(element('div', 'lumio-mapper-note', 'This product has no supported WHMCS billing-cycle price.'));
        }

        clear(state.validation);
        if (selection.missing.length) {
            state.validation.appendChild(createStatus('warning', 'Select all required options: ' + selection.missing.join(', ') + '.'));
        } else if (state.mappingWarnings.length) {
            state.validation.appendChild(createStatus('warning', 'The saved mapping contains unavailable items. Update the selections before saving.'));
        } else if (invalid) {
            state.validation.appendChild(createStatus('error', 'The selected product prices cannot produce valid cost caps.'));
        } else {
            state.validation.appendChild(createStatus('ready', 'The mapping is complete. Click “Save Changes” at the bottom of the page to apply it.'));
        }
        state.invalid = invalid;

        if (!writeRaw) {
            return;
        }
        state.bindings[1].control.value = state.selectedProduct.sku;
        state.bindings[2].control.value = Object.keys(selection.configuration).length
            ? JSON.stringify(selection.configuration)
            : '';
        state.bindings[3].control.value = addons.ids.join(',');
        Object.keys(cycleFields).forEach(function (cycle) {
            state.bindings[cycleFields[cycle]].control.value = typeof totals[cycle] === 'number'
                ? String(totals[cycle])
                : '';
        });
    }

    function mappingChanged(state) {
        state.dirty = true;
        state.mappingWarnings = [];
        validateAndPreview(state, true);
    }

    function installSubmitGuard(state) {
        var form = state.root.closest ? state.root.closest('form') : null;
        if (!form || form.getAttribute('data-lumio-mapper-guard') === '1') {
            return;
        }
        form.setAttribute('data-lumio-mapper-guard', '1');
        form.addEventListener('submit', function (event) {
            if (!currentState || !currentState.dirty || !currentState.invalid) {
                return;
            }
            event.preventDefault();
            currentState.panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            window.alert('Complete the Lumio product mapping before saving.');
        });
    }

    function renderReady(root, panel, bootstrap, bindings) {
        panel.appendChild(createStatus('ready', bootstrap.message, bootstrap.request_id));
        var productField = element('div', 'lumio-mapper-field');
        productField.appendChild(element('label', 'lumio-mapper-label', 'Lumio Product'));
        var productSelect = element('select', 'lumio-mapper-select');
        var placeholder = element('option', '', 'Select a Lumio product');
        placeholder.value = '';
        productSelect.appendChild(placeholder);

        bootstrap.products.forEach(function (product) {
            var option = element('option', '', product.name + ' — ' + product.sku);
            option.value = product.sku;
            productSelect.appendChild(option);
        });
        var savedSku = String(bindings[1].control.value || '').trim();
        if (savedSku && !productBySku(bootstrap.products, savedSku)) {
            var legacy = element('option', '', 'Saved but currently unavailable — ' + savedSku);
            legacy.value = savedSku;
            productSelect.appendChild(legacy);
        }
        productSelect.value = savedSku;
        productField.appendChild(productSelect);
        panel.appendChild(productField);

        var dynamic = element('div', 'lumio-mapper-field-wide');
        panel.appendChild(dynamic);
        var state = {
            root: root,
            panel: panel,
            bindings: bindings,
            products: bootstrap.products,
            productSelect: productSelect,
            dynamic: dynamic,
            selectedProduct: null,
            groupControls: [],
            addonControls: [],
            costs: null,
            validation: null,
            mappingWarnings: [],
            dirty: false,
            invalid: false
        };
        currentState = state;
        setRawRowsVisible(bindings, false);
        renderProductControls(
            state,
            productBySku(bootstrap.products, savedSku),
            parseConfiguration(bindings[2].control.value),
            parseAddonIds(bindings[3].control.value)
        );
        productSelect.addEventListener('change', function () {
            state.dirty = true;
            state.mappingWarnings = [];
            var product = productBySku(state.products, productSelect.value);
            if (!product) {
                bindings[1].control.value = '';
                bindings[2].control.value = '';
                bindings[3].control.value = '';
                renderProductControls(state, null, {}, []);
                return;
            }
            renderProductControls(state, product, {}, []);
            mappingChanged(state);
        });
        installSubmitGuard(state);
    }

    function sourceHash(source) {
        var hash = 0;
        for (var i = 0; i < source.length; i += 1) {
            hash = ((hash << 5) - hash + source.charCodeAt(i)) | 0;
        }
        return String(source.length) + ':' + String(hash);
    }

    function boot() {
        var root = document.getElementById(rootId);
        if (!root) {
            return;
        }
        if (!observer) {
            observer = new MutationObserver(scheduleBoot);
            observer.observe(root, { childList: true, subtree: true });
        }
        var bootstrapNode = root.querySelector('script.lumio-catalog-bootstrap');
        var existing = document.getElementById(panelId);
        if (!bootstrapNode) {
            if (existing) {
                existing.parentNode.removeChild(existing);
            }
            currentState = null;
            return;
        }
        var source = bootstrapNode.textContent || '';
        var hash = sourceHash(source);
        if (existing && existing.getAttribute('data-lumio-source') === hash) {
            return;
        }

        var bootstrap;
        try {
            bootstrap = JSON.parse(source);
        } catch (error) {
            bootstrap = {
                state: 'error',
                message: 'The Lumio product catalog response could not be read. Refresh the catalog.',
                request_id: null,
                products: []
            };
        }
        var bindings = optionBindings(root);
        if (!bindings) {
            return;
        }
        hideCompatibilityRows(bindings);
        if (existing) {
            existing.parentNode.removeChild(existing);
        }
        setRawRowsVisible(bindings, true);
        addStyles();
        var panel = buildPanelShell(bootstrap);
        panel.setAttribute('data-lumio-source', hash);
        root.insertBefore(panel, root.firstChild);
        currentState = null;
        if (bootstrap.state === 'ready' && Array.isArray(bootstrap.products)) {
            renderReady(root, panel, bootstrap, bindings);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBoot);
    } else {
        scheduleBoot();
    }
    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(scheduleBoot);
    }
}(window, document));
