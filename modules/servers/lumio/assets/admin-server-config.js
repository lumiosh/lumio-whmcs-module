(function (window, document) {
    'use strict';

    var scheduled = false;
    var initialized = false;
    var lumioActive = false;
    var lumioInstallationId = '';
    var lumioBaseUrl = '';

    function serverForms() {
        var forms = [];
        var selectors = ['#preAddForm', '#frmServerConfig'];
        for (var i = 0; i < selectors.length; i += 1) {
            var form = document.querySelector(selectors[i]);
            if (form && forms.indexOf(form) === -1) {
                forms.push(form);
            }
        }
        return forms;
    }

    function all(roots, selectors) {
        var controls = [];
        for (var rootIndex = 0; rootIndex < roots.length; rootIndex += 1) {
            for (var selectorIndex = 0; selectorIndex < selectors.length; selectorIndex += 1) {
                var matches = roots[rootIndex].querySelectorAll(selectors[selectorIndex]);
                for (var matchIndex = 0; matchIndex < matches.length; matchIndex += 1) {
                    if (controls.indexOf(matches[matchIndex]) === -1) {
                        controls.push(matches[matchIndex]);
                    }
                }
            }
        }
        return controls;
    }

    function preferredControl(controls) {
        for (var i = 0; i < controls.length; i += 1) {
            var control = controls[i];
            if (control.offsetWidth > 0
                || control.offsetHeight > 0
                || (typeof control.getClientRects === 'function' && control.getClientRects().length > 0)) {
                return control;
            }
        }
        return controls.length > 0 ? controls[0] : null;
    }

    function closestRow(control) {
        if (!control) {
            return null;
        }
        if (typeof control.closest === 'function') {
            return control.closest('tr, .form-group');
        }
        var current = control.parentNode;
        while (current && current !== document.body) {
            if (String(current.tagName).toLowerCase() === 'tr'
                || String(current.className).split(/\s+/).indexOf('form-group') !== -1) {
                return current;
            }
            current = current.parentNode;
        }
        return null;
    }

    function rowLabel(row) {
        if (!row) {
            return null;
        }
        var label = row.querySelector('label, .fieldlabel, th');
        if (label) {
            return label;
        }
        return row.cells && row.cells.length ? row.cells[0] : null;
    }

    function normalizedLabel(value) {
        return String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/[:：*]+$/, '')
            .trim()
            .toLowerCase();
    }

    function controlsByRowLabel(roots, labels, selector) {
        var expected = labels.map(normalizedLabel);
        var controls = [];
        for (var rootIndex = 0; rootIndex < roots.length; rootIndex += 1) {
            var rows = roots[rootIndex].querySelectorAll('tr, .form-group');
            for (var rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
                var label = rowLabel(rows[rowIndex]);
                if (!label || expected.indexOf(normalizedLabel(label.textContent)) === -1) {
                    continue;
                }
                var matches = rows[rowIndex].querySelectorAll(selector);
                for (var matchIndex = 0; matchIndex < matches.length; matchIndex += 1) {
                    if (controls.indexOf(matches[matchIndex]) === -1) {
                        controls.push(matches[matchIndex]);
                    }
                }
            }
        }
        return controls;
    }

    function combinedControls(roots, selectors, labels, controlSelector) {
        var controls = all(roots, selectors);
        var labelled = controlsByRowLabel(roots, labels, controlSelector);
        for (var i = 0; i < labelled.length; i += 1) {
            if (controls.indexOf(labelled[i]) === -1) {
                controls.push(labelled[i]);
            }
        }
        return controls;
    }

    function remember(row, control) {
        if (row && !row.hasAttribute('data-lumio-original-display')) {
            row.setAttribute('data-lumio-original-display', row.style.display || '');
        }
        if (control && !control.hasAttribute('data-lumio-original-readonly')) {
            control.setAttribute('data-lumio-original-readonly', control.readOnly ? '1' : '0');
        }
        var label = rowLabel(row);
        if (label && !label.hasAttribute('data-lumio-original-label')) {
            label.setAttribute('data-lumio-original-label', label.textContent || '');
        }
    }

    function restore(row, control) {
        if (row && row.hasAttribute('data-lumio-original-display')) {
            row.style.display = row.getAttribute('data-lumio-original-display') || '';
            row.removeAttribute('aria-hidden');
            row.removeAttribute('data-lumio-original-display');
        }
        if (control && control.hasAttribute('data-lumio-original-readonly')) {
            control.readOnly = control.getAttribute('data-lumio-original-readonly') === '1';
            control.removeAttribute('data-lumio-original-readonly');
        }
        var label = rowLabel(row);
        if (label && label.hasAttribute('data-lumio-original-label')) {
            var original = label.getAttribute('data-lumio-original-label') || '';
            if (label.textContent !== original) {
                label.textContent = original;
            }
            label.removeAttribute('data-lumio-original-label');
        }
    }

    function restoreControls(controls) {
        for (var i = 0; i < controls.length; i += 1) {
            restore(closestRow(controls[i]), controls[i]);
        }
    }

    function clearLumioInstallationId(controls) {
        if (lumioInstallationId === '') {
            return;
        }
        for (var i = 0; i < controls.length; i += 1) {
            if (String(controls[i].value || '').trim() === lumioInstallationId) {
                controls[i].value = '';
            }
        }
    }

    function setLumioInstallationId(controls, value) {
        for (var i = 0; i < controls.length; i += 1) {
            if (String(controls[i].value || '').trim() !== value) {
                controls[i].value = value;
            }
        }
    }

    function existingInstallationId(controls) {
        var preferred = ['#inputUsername', '#inputServerUsername'];
        for (var preferredIndex = 0; preferredIndex < preferred.length; preferredIndex += 1) {
            for (var controlIndex = 0; controlIndex < controls.length; controlIndex += 1) {
                if (controls[controlIndex].matches(preferred[preferredIndex])) {
                    var preferredValue = String(controls[controlIndex].value || '').trim();
                    if (preferredValue !== '') {
                        return preferredValue;
                    }
                }
            }
        }
        for (var i = 0; i < controls.length; i += 1) {
            var value = String(controls[i].value || '').trim();
            if (value !== '') {
                return value;
            }
        }
        return '';
    }

    function hideControls(controls) {
        for (var i = 0; i < controls.length; i += 1) {
            var row = closestRow(controls[i]);
            remember(row, controls[i]);
            if (row) {
                row.style.display = 'none';
                row.setAttribute('aria-hidden', 'true');
            }
        }
    }

    function installationId() {
        var bytes = new Uint8Array(16);
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            return '';
        }
        window.crypto.getRandomValues(bytes);
        var value = '';
        for (var i = 0; i < bytes.length; i += 1) {
            value += bytes[i].toString(16).padStart(2, '0');
        }
        return 'whmcs-' + value;
    }

    function moduleIsLumio(moduleSelect) {
        if (!moduleSelect) {
            return false;
        }
        var value = String(moduleSelect.value || '').trim().toLowerCase();
        if (value === 'lumio') {
            return true;
        }
        var option = moduleSelect.options && moduleSelect.selectedIndex >= 0
            ? moduleSelect.options[moduleSelect.selectedIndex]
            : null;
        return option && String(option.textContent || '').trim().toLowerCase() === 'lumio';
    }

    function normalizeBaseUrl(value) {
        var raw = String(value || '').trim();
        if (raw === '') {
            return null;
        }
        var candidate = raw.indexOf('://') === -1 ? 'https://' + raw : raw;
        var parsed;
        try {
            parsed = new URL(candidate);
        } catch (error) {
            return null;
        }
        if (parsed.protocol !== 'https:'
            || parsed.username !== ''
            || parsed.password !== ''
            || parsed.search !== ''
            || parsed.hash !== ''
            || parsed.hostname === '') {
            return null;
        }
        var port = parsed.port === '' ? '443' : parsed.port;
        var numericPort = Number(port);
        if (!Number.isInteger(numericPort) || numericPort < 1 || numericPort > 65535) {
            return null;
        }
        var path = parsed.pathname.replace(/\/+$/, '');
        return {
            full: 'https://' + parsed.host.toLowerCase() + path,
            hostname: parsed.hostname.replace(/^\[|\]$/g, '').toLowerCase(),
            port: port
        };
    }

    function isVisible(control) {
        return control && (control.offsetWidth > 0
            || control.offsetHeight > 0
            || (typeof control.getClientRects === 'function' && control.getClientRects().length > 0));
    }

    function setControlValues(controls, value) {
        for (var i = 0; i < controls.length; i += 1) {
            controls[i].value = value;
        }
    }

    function synchronizeBaseUrl(roots, addresses, accessHashes, forSubmission) {
        if (!lumioActive) {
            return;
        }
        var visibleAddress = preferredControl(addresses);
        var source = visibleAddress ? visibleAddress.value : '';
        var savedBaseUrl = null;
        for (var hashIndex = 0; hashIndex < accessHashes.length; hashIndex += 1) {
            var saved = normalizeBaseUrl(accessHashes[hashIndex].value);
            if (saved) {
                savedBaseUrl = saved;
                lumioBaseUrl = saved.full;
                break;
            }
        }
        var sourceIsComplete = source.indexOf('://') !== -1 || source.indexOf('/') !== -1;
        var normalized = sourceIsComplete
            ? normalizeBaseUrl(source)
            : (savedBaseUrl || normalizeBaseUrl(lumioBaseUrl));
        if (!normalized) {
            return;
        }
        lumioBaseUrl = normalized.full;
        setControlValues(accessHashes, normalized.full);

        for (var addressIndex = 0; addressIndex < addresses.length; addressIndex += 1) {
            addresses[addressIndex].value = forSubmission || !isVisible(addresses[addressIndex])
                ? normalized.hostname
                : normalized.full;
        }

        var secureControls = all(roots, ['#inputSecure', '#newSecure', 'input[name="secure"]']);
        for (var secureIndex = 0; secureIndex < secureControls.length; secureIndex += 1) {
            secureControls[secureIndex].checked = true;
        }
        setControlValues(all(roots, ['#inputPort', '#newPort', 'input[name="port"]']), normalized.port);
    }

    function apply() {
        var roots = serverForms();
        if (roots.length === 0) {
            return;
        }
        var moduleSelects = combinedControls(roots, [
            '#addType',
            '#inputServerType',
            '#inputServerModule',
            '#inputModule',
            'select[name="servertype"]',
            'select[name="servermodule"]',
            'select[name="type"]'
        ], ['Module'], 'select');
        var moduleSelect = preferredControl(moduleSelects);
        if (!moduleSelect) {
            return;
        }

        var usernames = combinedControls(roots, [
            '#newUsername',
            '#inputUsername',
            '#inputServerUsername',
            'input[data-related-id="inputUsername"]',
            'input[name="serverusername"]',
            'input[name="server_user"]',
            'input[name="username"]'
        ], ['Username'], 'input');
        var passwords = combinedControls(roots, [
            '#newPassword',
            '#inputPassword',
            '#inputServerPassword',
            'input[data-related-id="inputPassword"]',
            'input[name="serverpassword"]',
            'input[name="server_secret"]',
            'input[name="password"]'
        ], ['Password', 'API Key'], 'input');
        var accessHashes = combinedControls(roots, [
            '#newHash',
            '#newToken',
            '#serverHash',
            '#apiToken',
            'textarea[data-related-id="serverHash"]',
            'input[data-related-id="apiToken"]',
            'textarea[name="accesshash"]',
            'input[name="accesshash"]'
        ], ['Access Hash'], 'textarea, input');
        var addresses = combinedControls(roots, [
            '#newHostname',
            '#inputHostname',
            'input[data-related-id="inputHostname"]',
            'input[name="hostname"]'
        ], ['Hostname or IP Address'], 'input');

        var isLumio = moduleIsLumio(moduleSelect);
        var isNewServerWizard = roots.some(function (root) {
            return root.id === 'preAddForm';
        });
        if (!initialized) {
            initialized = true;
            if (isLumio && !isNewServerWizard) {
                lumioInstallationId = existingInstallationId(usernames);
            } else if (isLumio) {
                setControlValues(accessHashes, '');
            }
        }

        if (!isLumio) {
            restoreControls(usernames);
            restoreControls(passwords);
            restoreControls(accessHashes);
            if (lumioActive) {
                clearLumioInstallationId(usernames);
                var previousBaseUrl = normalizeBaseUrl(lumioBaseUrl);
                if (previousBaseUrl) {
                    setControlValues(addresses, previousBaseUrl.hostname);
                }
                setControlValues(accessHashes, '');
            } else {
                lumioBaseUrl = '';
            }
            lumioActive = false;
            return;
        }

        if (lumioInstallationId === '') {
            lumioInstallationId = installationId();
        }
        setLumioInstallationId(usernames, lumioInstallationId);
        for (var usernameIndex = 0; usernameIndex < usernames.length; usernameIndex += 1) {
            var username = usernames[usernameIndex];
            remember(closestRow(username), username);
            username.readOnly = true;
            username.setAttribute('autocomplete', 'off');
        }
        hideControls(usernames);
        hideControls(accessHashes);

        for (var passwordIndex = 0; passwordIndex < passwords.length; passwordIndex += 1) {
            var passwordRow = closestRow(passwords[passwordIndex]);
            remember(passwordRow, passwords[passwordIndex]);
            var passwordLabel = rowLabel(passwordRow);
            if (passwordLabel && passwordLabel.textContent !== 'API Key') {
                passwordLabel.textContent = 'API Key';
            }
        }
        lumioActive = true;
        synchronizeBaseUrl(roots, addresses, accessHashes, false);
    }

    function prepareBaseUrlForSubmission() {
        var roots = serverForms();
        if (roots.length === 0) {
            return;
        }
        var moduleSelect = preferredControl(combinedControls(roots, [
            '#addType', '#inputServerType', 'select[name="type"]'
        ], ['Module'], 'select'));
        if (!moduleIsLumio(moduleSelect)) {
            return;
        }
        var addresses = combinedControls(roots, [
            '#newHostname', '#inputHostname', 'input[data-related-id="inputHostname"]', 'input[name="hostname"]'
        ], ['Hostname or IP Address'], 'input');
        var accessHashes = combinedControls(roots, [
            '#newHash', '#serverHash', 'textarea[data-related-id="serverHash"]', 'textarea[name="accesshash"]'
        ], ['Access Hash'], 'textarea, input');
        synchronizeBaseUrl(roots, addresses, accessHashes, true);
        window.setTimeout(function () {
            synchronizeBaseUrl(roots, addresses, accessHashes, false);
        }, 0);
    }

    function schedule() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.setTimeout(function () {
            scheduled = false;
            apply();
        }, 0);
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && String(target.tagName).toLowerCase() === 'select') {
            schedule();
            return;
        }
        if (target && (target.id === 'newHostname' || target.id === 'inputHostname')) {
            schedule();
        }
    });
    document.addEventListener('input', function (event) {
        var target = event.target;
        if (target && (target.id === 'newHostname' || target.id === 'inputHostname')) {
            schedule();
        }
    });
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && ['newTestConn', 'connectionTestBtn', 'btnSave', 'btnSaveChanges'].indexOf(target.id) !== -1) {
            prepareBaseUrlForSubmission();
        }
    }, true);
    document.addEventListener('submit', prepareBaseUrlForSubmission, true);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(schedule);
    }
}(window, document));
