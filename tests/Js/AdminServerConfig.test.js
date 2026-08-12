'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class FakeEvent {
    constructor(type, options = {}) {
        this.type = type;
        this.bubbles = options.bubbles === true;
        this.target = null;
    }
}

class FakeElement {
    constructor(tagName, attributes = {}) {
        this.tagName = String(tagName).toUpperCase();
        this.parentNode = null;
        this.children = [];
        this.style = { display: '' };
        this.className = '';
        this.id = '';
        this.value = '';
        this.readOnly = false;
        this.textContent = '';
        this.options = [];
        this.selectedIndex = -1;
        this.listeners = new Map();
        this.attributes = new Map();
        for (const [name, value] of Object.entries(attributes)) {
            this.setAttribute(name, value);
        }
    }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    setAttribute(name, value) {
        const normalized = String(name);
        const stringValue = String(value);
        this.attributes.set(normalized, stringValue);
        if (normalized === 'id') {
            this.id = stringValue;
        }
        if (normalized === 'class') {
            this.className = stringValue;
        }
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
        if (name === 'class') {
            this.className = '';
        }
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatchEvent(event) {
        if (!event.target) {
            event.target = this;
        }
        for (const listener of this.listeners.get(event.type) || []) {
            listener.call(this, event);
        }
        if (event.bubbles && this.parentNode) {
            this.parentNode.dispatchEvent(event);
        }
        return true;
    }

    matches(selector) {
        const value = selector.trim();
        if (value.startsWith('#')) {
            return this.id === value.slice(1);
        }
        if (value.startsWith('.')) {
            return this.className.split(/\s+/).includes(value.slice(1));
        }
        const match = value.match(/^([a-z]+)?(?:\[([a-z-]+)="([^"]*)"\])?$/i);
        if (!match) {
            return false;
        }
        if (match[1] && this.tagName.toLowerCase() !== match[1].toLowerCase()) {
            return false;
        }
        return !match[2] || this.getAttribute(match[2]) === match[3];
    }

    querySelectorAll(selector) {
        const selectors = selector.split(',').map((value) => value.trim());
        const matches = [];
        const visit = (element) => {
            for (const child of element.children) {
                if (selectors.some((candidate) => child.matches(candidate))) {
                    matches.push(child);
                }
                visit(child);
            }
        };
        visit(this);
        return matches;
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    closest(selector) {
        const selectors = selector.split(',').map((value) => value.trim());
        let current = this;
        while (current) {
            if (selectors.some((candidate) => current.matches(candidate))) {
                return current;
            }
            current = current.parentNode;
        }
        return null;
    }

    isVisible() {
        let current = this;
        while (current) {
            if (current.style.display === 'none'
                || current.className.split(/\s+/).includes('hidden')) {
                return false;
            }
            current = current.parentNode;
        }
        return true;
    }

    get offsetWidth() {
        return this.isVisible() ? 100 : 0;
    }

    get offsetHeight() {
        return this.isVisible() ? 20 : 0;
    }

    getClientRects() {
        return this.isVisible() ? [{}] : [];
    }
}

class FakeDocument extends FakeElement {
    constructor() {
        super('document');
        this.readyState = 'complete';
        this.documentElement = this.appendChild(new FakeElement('html'));
        this.body = this.documentElement.appendChild(new FakeElement('body'));
    }
}

function control(tagName, id, value = '', attributes = {}) {
    const element = new FakeElement(tagName, { id, ...attributes });
    element.value = value;
    return element;
}

function row(labelText, input) {
    const container = new FakeElement('div', { class: 'form-group' });
    const label = container.appendChild(new FakeElement('label'));
    label.textContent = labelText;
    container.appendChild(input);
    return { container, label, input };
}

function appendRow(form, labelText, input) {
    const result = row(labelText, input);
    form.appendChild(result.container);
    return result;
}

function addUnrelatedForm(document) {
    const form = document.body.appendChild(new FakeElement('form', { id: 'unrelatedForm' }));
    return {
        username: appendRow(form, 'Username', control('input', 'unrelatedUsername', 'agent', { name: 'username' })),
        password: appendRow(form, 'Password', control('input', 'unrelatedPassword', 'secret', { name: 'password' })),
        accessHash: appendRow(form, 'Access Hash', control('textarea', 'unrelatedHash', 'hash', { name: 'accesshash' })),
    };
}

function createNewServerPage(initialModule, staleUsername) {
    const document = new FakeDocument();
    const unrelated = addUnrelatedForm(document);
    const basicForm = document.body.appendChild(new FakeElement('form', { id: 'preAddForm' }));
    const advancedForm = document.body.appendChild(new FakeElement('form', { id: 'frmServerConfig' }));
    advancedForm.style.display = 'none';

    const basic = {
        module: appendRow(basicForm, 'Module', control('select', 'addType', initialModule)).input,
        hostname: appendRow(basicForm, 'Hostname or IP Address', control('input', 'newHostname', '', { 'data-related-id': 'inputHostname' })),
        username: appendRow(basicForm, 'Username', control('input', 'newUsername', staleUsername, { 'data-related-id': 'inputUsername' })),
        password: appendRow(basicForm, 'Password', control('input', 'newPassword', '', { 'data-related-id': 'inputPassword' })),
        accessHash: appendRow(basicForm, 'Access Hash', control('textarea', 'newHash', '', { 'data-related-id': 'serverHash' })),
    };
    const advanced = {
        module: appendRow(advancedForm, 'Module', control('select', 'inputServerType', initialModule, { name: 'type' })).input,
        hostname: appendRow(advancedForm, 'Hostname or IP Address', control('input', 'inputHostname', '', { name: 'hostname' })),
        secure: appendRow(advancedForm, 'Secure', control('input', 'inputSecure', '', { name: 'secure' })).input,
        port: appendRow(advancedForm, 'Port', control('input', 'inputPort', '80', { name: 'port' })).input,
        username: appendRow(advancedForm, 'Username', control('input', 'inputUsername', staleUsername, { name: 'username' })),
        password: appendRow(advancedForm, 'Password', control('input', 'inputPassword', '', { name: 'password' })),
        accessHash: appendRow(advancedForm, 'Access Hash', control('textarea', 'serverHash', '', { name: 'accesshash' })),
    };
    return { document, unrelated, basic, advanced };
}

function createExistingServerPage(initialModule, installationId, hostname = '', baseUrl = '') {
    const document = new FakeDocument();
    const unrelated = addUnrelatedForm(document);
    const form = document.body.appendChild(new FakeElement('form', { id: 'frmServerConfig' }));
    const advanced = {
        module: appendRow(form, 'Module', control('select', 'inputServerType', initialModule, { name: 'type' })).input,
        hostname: appendRow(form, 'Hostname or IP Address', control('input', 'inputHostname', hostname, { name: 'hostname' })),
        secure: appendRow(form, 'Secure', control('input', 'inputSecure', '', { name: 'secure' })).input,
        port: appendRow(form, 'Port', control('input', 'inputPort', '443', { name: 'port' })).input,
        username: appendRow(form, 'Username', control('input', 'inputUsername', installationId, { name: 'username' })),
        password: appendRow(form, 'Password', control('input', 'inputPassword', '', { name: 'password' })),
        accessHash: appendRow(form, 'Access Hash', control('textarea', 'serverHash', baseUrl, { name: 'accesshash' })),
    };
    return { document, unrelated, advanced };
}

function executeModule(page) {
    const source = fs.readFileSync(
        path.resolve(__dirname, '../../modules/servers/lumio/assets/admin-server-config.js'),
        'utf8',
    );
    const window = {
        document: page.document,
        crypto: {
            getRandomValues(bytes) {
                for (let index = 0; index < bytes.length; index += 1) {
                    bytes[index] = index + 1;
                }
                return bytes;
            },
        },
        setTimeout(callback) {
            callback();
            return 1;
        },
    };
    window.window = window;
    class FakeMutationObserver {
        observe() {}
    }
    vm.runInNewContext(source, {
        document: page.document,
        Event: FakeEvent,
        MutationObserver: FakeMutationObserver,
        Uint8Array,
        URL,
        window,
    });
}

function switchModule(page, value) {
    page.advanced.module.value = value;
    if (page.basic) {
        page.basic.module.value = value;
        page.basic.module.dispatchEvent(new FakeEvent('change', { bubbles: true }));
        return;
    }
    page.advanced.module.dispatchEvent(new FakeEvent('change', { bubbles: true }));
}

function assertUnrelatedFormUnchanged(unrelated) {
    assert.equal(unrelated.username.container.style.display, '');
    assert.equal(unrelated.password.label.textContent, 'Password');
    assert.equal(unrelated.accessHash.container.style.display, '');
}

test('new Lumio servers replace stale usernames and keep one hidden installation ID', () => {
    const page = createNewServerPage('other', 'legacy@example.com');
    executeModule(page);
    switchModule(page, 'lumio');

    const installationId = 'whmcs-0102030405060708090a0b0c0d0e0f10';
    assert.equal(page.basic.username.input.value, installationId);
    assert.equal(page.advanced.username.input.value, installationId);
    assert.equal(page.basic.username.container.style.display, 'none');
    assert.equal(page.advanced.username.container.style.display, 'none');
    assert.equal(page.basic.accessHash.container.style.display, 'none');
    assert.equal(page.advanced.accessHash.container.style.display, 'none');
    assert.equal(page.basic.password.label.textContent, 'API Key');
    assert.equal(page.advanced.password.label.textContent, 'API Key');
    assertUnrelatedFormUnchanged(page.unrelated);

    switchModule(page, 'other');
    assert.equal(page.basic.username.input.value, '');
    assert.equal(page.advanced.username.input.value, '');
    assert.equal(page.basic.username.container.style.display, '');
    assert.equal(page.basic.password.label.textContent, 'Password');

    page.basic.username.input.value = 'root';
    page.advanced.username.input.value = 'root';
    switchModule(page, 'lumio');
    assert.equal(page.basic.username.input.value, installationId);
    assert.equal(page.advanced.username.input.value, installationId);
    assertUnrelatedFormUnchanged(page.unrelated);
});

test('an existing Lumio server keeps its saved installation ID across module switches', () => {
    const page = createExistingServerPage('lumio', 'shop-example-01');
    executeModule(page);

    assert.equal(page.advanced.username.input.value, 'shop-example-01');
    assert.equal(page.advanced.username.container.style.display, 'none');
    assert.equal(page.advanced.password.label.textContent, 'API Key');

    switchModule(page, 'other');
    assert.equal(page.advanced.username.input.value, '');
    assert.equal(page.advanced.username.container.style.display, '');
    assert.equal(page.advanced.password.label.textContent, 'Password');

    page.advanced.username.input.value = 'other-provider-user';
    switchModule(page, 'lumio');
    assert.equal(page.advanced.username.input.value, 'shop-example-01');
    assert.equal(page.advanced.username.container.style.display, 'none');
    assertUnrelatedFormUnchanged(page.unrelated);
});

test('a complete API base URL is shown to the administrator but WHMCS receives a valid hostname', () => {
    const page = createNewServerPage('lumio', '');
    page.basic.accessHash.input.value = 'stale-access-hash';
    page.advanced.accessHash.input.value = 'stale-access-hash';
    page.basic.hostname.input.value = 'https://API.EXAMPLE.COM/api/v1/integration/';
    executeModule(page);

    assert.equal(page.basic.hostname.input.value, 'https://api.example.com/api/v1/integration');
    assert.equal(page.advanced.hostname.input.value, 'api.example.com');
    assert.equal(page.basic.accessHash.input.value, 'https://api.example.com/api/v1/integration');
    assert.equal(page.advanced.accessHash.input.value, 'https://api.example.com/api/v1/integration');
    assert.equal(page.advanced.secure.checked, true);
    assert.equal(page.advanced.port.value, '443');
});

test('an existing server restores the complete API base URL from the hidden compatibility field', () => {
    const page = createExistingServerPage(
        'lumio',
        'shop-example-01',
        'api.example.com',
        'https://api.example.com/integration/v2',
    );
    executeModule(page);

    assert.equal(page.advanced.hostname.input.value, 'https://api.example.com/integration/v2');
    assert.equal(page.advanced.accessHash.input.value, 'https://api.example.com/integration/v2');
});
