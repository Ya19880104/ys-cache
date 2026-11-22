(() => {
    const admin = document.getElementById('ys-cache-admin');
    if (!admin) return;

    const form = document.getElementById('ys-cache-settings');
    const list = document.getElementById('ys-cache-cdn-list');
    const template = document.getElementById('ys-cache-cdn-template');
    const status = admin.querySelector('[data-settings-status]');
    const purgeStatus = admin.querySelector('[data-purge-status]');
    const results = admin.querySelector('.ys-cache-purge-results');
    const enhanceStatus = admin.querySelector('[data-enhance-status]');
    const enhancePaths = admin.querySelector('[data-enhance-paths]');
    const addCdn = admin.querySelector('[data-cdn-add]');
    const sections = Array.from(admin.querySelectorAll('[data-tab-section]'));
    const tabGroups = Array.from(admin.querySelectorAll('[data-tab-group]'));
    const panels = Array.from(admin.querySelectorAll('[data-tab-panel]'));
    const tabs = Array.from(admin.querySelectorAll('[role="tab"]'));
    const lastTab = new Map();
    const lifetime = form.elements.namedItem('ttl_value');
    const lifetimeUnit = form.elements.namedItem('ttl_unit');
    const lifetimeFactors = Object.fromEntries(Array.from(lifetimeUnit.options, (option) => [option.value, Number(option.dataset.seconds)]));
    const maxLifetime = 604800;
    let previousUnit = lifetimeUnit.value;

    const read = (field) => field.type === 'checkbox' ? field.checked : field.value;
    const field = (name) => read(form.elements.namedItem(name));
    const rowData = (row) => Object.fromEntries(
        Array.from(row.querySelectorAll('[data-key]:not(:disabled)'), (input) => [input.dataset.key, read(input)])
    );
    const enhanceConnection = () => ({
        enabled: field('enhance_enabled'),
        delegate_html: field('enhance_delegate_html'),
        rules_verified: field('enhance_rules_verified'),
        url: field('enhance_url'),
        domain_id: field('enhance_domain_id'),
        org_id: field('enhance_org_id'),
        token: field('enhance_token'),
        clear_token: field('enhance_clear_token'),
    });

    const lifetimeSeconds = (unit = lifetimeUnit.value) => {
        const duration = lifetime.valueAsNumber * lifetimeFactors[unit];
        const seconds = Math.round(duration);
        return seconds >= 1 && seconds <= maxLifetime && Math.abs(duration - seconds) < 1e-8 ? seconds : null;
    };

    const validateLifetime = () => {
        const seconds = lifetimeSeconds();
        lifetime.setCustomValidity(lifetime.value && seconds === null ? lifetime.dataset.invalid : '');
        return seconds;
    };

    lifetime.addEventListener('input', validateLifetime);
    lifetimeUnit.addEventListener('change', () => {
        const seconds = lifetimeSeconds(previousUnit);
        const units = lifetimeFactors[lifetimeUnit.value];
        previousUnit = lifetimeUnit.value;
        lifetime.max = String(maxLifetime / units);
        if (seconds !== null) lifetime.value = String(seconds / units);
        validateLifetime();
    });

    const selectTab = (key, remember = true) => {
        const selected = tabs.find((tab) => tab.dataset.tab === key);
        if (!selected) return;
        const section = selected.closest('[data-tab-group]').dataset.tabGroup;
        lastTab.set(section, key);
        sections.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.tabSection === section)));
        tabGroups.forEach((group) => { group.hidden = group.dataset.tabGroup !== section; });
        tabs.forEach((tab) => {
            tab.setAttribute('aria-selected', String(tab === selected));
            tab.tabIndex = tab === selected ? 0 : -1;
        });
        panels.forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== key; });
        admin.querySelector('.ys-cache-form-footer').hidden = section === 'help';
        if (remember) window.history.replaceState(window.history.state, '', `#ys-cache-panel-${key}`);
    };

    const refreshCdnRows = () => {
        const rows = Array.from(list.querySelectorAll('[data-cdn-row]'));
        rows.forEach((row, index) => {
            const type = row.querySelector('[data-key="provider"]').value;
            row.querySelectorAll('[data-api-only]').forEach((item) => {
                item.hidden = type === 'cname';
                item.querySelectorAll('input, select').forEach((input) => { input.disabled = item.hidden; });
            });
            const scope = row.querySelector('[data-key="scope"]');
            Array.from(scope.options).forEach((option) => {
                option.disabled = type === 'cname' && !['code', 'static'].includes(option.value);
            });
            if (scope.selectedOptions[0]?.disabled) scope.value = 'static';
            row.querySelectorAll('[data-cloudflare-only]').forEach((item) => {
                item.hidden = type !== 'cloudflare' || (item.hasAttribute('data-full-zone-only') && scope.value !== 'all');
            });
            row.querySelectorAll('[data-rewrite-option]').forEach((item) => {
                item.hidden = scope.value === 'html';
                item.querySelector('input').disabled = item.hidden;
            });
            const htmlRules = row.querySelector('[data-html-only]');
            htmlRules.hidden = !['all', 'html', 'html_code'].includes(scope.value);
            htmlRules.querySelector('input').disabled = htmlRules.hidden;
            row.querySelector('[data-cdn-up]').disabled = index === 0;
            row.querySelector('[data-cdn-down]').disabled = index === rows.length - 1;
        });
        addCdn.disabled = rows.length >= Number(admin.dataset.maxLayers);
    };

    const showResults = (layers) => {
        results.replaceChildren();
        for (const layer of layers) {
            const item = document.createElement('li');
            item.dataset.status = layer.status;
            item.textContent = [layer.layer, layer.message].filter(Boolean).join(' — ');
            results.append(item);
        }
    };

    const request = async (action, fields) => {
        let response;
        let payload;
        try {
            response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ action, nonce: admin.dataset.nonce, ...fields }),
            });
            payload = await response.json();
        } catch {
            throw new Error(admin.dataset.error);
        }
        if (!response.ok || !payload?.success) {
            throw new Error(payload?.data?.message || admin.dataset.error);
        }
        return payload.data;
    };

    const run = async (button, work, feedback = status) => {
        button.disabled = true;
        feedback.textContent = '';
        feedback.classList.remove('ys-cache-error');
        try {
            await work();
        } catch (error) {
            feedback.textContent = error.message || admin.dataset.error;
            feedback.classList.add('ys-cache-error');
        } finally {
            button.disabled = false;
        }
    };

    const checkEnhance = async (operation) => {
        const fields = { operation };
        if (operation === 'status') fields.connection = JSON.stringify(enhanceConnection());
        const data = await request('ys_cache_enhance', fields);
        enhanceStatus.textContent = operation === 'status' && typeof data.enabled === 'boolean'
            ? enhanceStatus.dataset[data.enabled ? 'enabled' : 'disabled'] : data.message;
        enhanceStatus.classList.toggle('ys-cache-error', data.status === 'error');
        enhancePaths.replaceChildren();
        for (const [paths, label] of [[data.missing, enhancePaths.dataset.missing], [data.added, enhancePaths.dataset.added]]) {
            for (const path of paths || []) {
                const item = document.createElement('li');
                item.textContent = `${label} — ${path}`;
                enhancePaths.append(item);
            }
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const seconds = validateLifetime();
        if (seconds === null) {
            selectTab('html');
            lifetime.reportValidity();
            return;
        }
        run(event.submitter || form.querySelector('[type="submit"]'), async () => {
            const cdn = Array.from(list.querySelectorAll('[data-cdn-row]'), rowData);
            const settings = {
                enabled: field('enabled'),
                ttl_seconds: seconds,
                ttl_unit: lifetimeUnit.value,
                auto_purge: field('auto_purge'),
                auto_detect: field('auto_detect'),
                exclude_paths: field('exclude_paths'),
                exclude_cookies: field('exclude_cookies'),
                exclude_agents: field('exclude_agents'),
                assets: {
                    css_minify: field('css_minify'),
                    js_minify: field('js_minify'),
                    js_defer: field('js_defer'),
                    css_excludes: field('css_excludes'),
                    js_excludes: field('js_excludes'),
                },
                providers: {
                    enhance: enhanceConnection(),
                    opcache: { enabled: field('opcache_enabled') },
                    cdn,
                },
            };
            const data = await request('ys_cache_save', { settings: JSON.stringify(settings) });
            status.textContent = data.message;
            const owner = admin.querySelector('[data-cache-owner]');
            const ownership = data.delegated ? 'external' : settings.enabled ? 'local' : 'disabled';
            owner.textContent = owner.dataset[ownership];
            if (data.results?.length) showResults(data.results);
            const savedCdn = data.cdn || [];
            list.querySelectorAll('[data-cdn-row]').forEach((row) => {
                const fields = rowData(row);
                const index = savedCdn.findIndex((saved) => saved.provider === fields.provider
                    && saved.zone_id === (fields.zone_id || '').trim().toLowerCase()
                    && saved.hostname === fields.hostname.trim().toLowerCase()
                    && saved.auth_email === (fields.provider === 'cloudflare' ? fields.auth_email.trim() : ''));
                row.dataset.savedIndex = index === -1 ? '' : index;
                row.querySelector('[data-key="html_cache_verified"]').checked = Boolean(savedCdn[index]?.html_cache_verified);
                if (index !== -1) row.querySelector('[data-key="auto_purge"]').value = savedCdn[index].auto_purge === 'off' ? 'off' : 'inherit';
            });
            form.querySelectorAll('[type="password"]').forEach((input) => {
                if (input.value) input.placeholder = admin.dataset.credentialSaved;
                input.value = '';
            });
            form.querySelectorAll('[data-key="clear_token"], [name="enhance_clear_token"]').forEach((input) => {
                if (input.checked) input.closest('fieldset, section').querySelector('input[type="password"]').placeholder = admin.dataset.credentialMissing;
                input.checked = false;
            });
        });
    });

    form.addEventListener('change', (event) => {
        const input = event.target;
        if (['provider', 'scope', 'hostname'].includes(input.dataset.key)) {
            input.closest('[data-cdn-row]').querySelector('[data-key="html_cache_verified"]').checked = false;
            refreshCdnRows();
        }
        if (input.name === 'enhance_delegate_html' && input.checked) form.elements.enhance_enabled.checked = true;
        if (input.name === 'enhance_enabled' && !input.checked) form.elements.enhance_delegate_html.checked = false;
        if (['enhance_url', 'enhance_domain_id', 'enhance_org_id'].includes(input.name)) {
            form.elements.enhance_rules_verified.checked = false;
            enhanceStatus.textContent = '';
            enhancePaths.replaceChildren();
        }
    });

    form.addEventListener('invalid', (event) => {
        const panel = event.target.closest('[data-tab-panel]');
        if (panel?.hidden) selectTab(panel.dataset.tabPanel);
    }, true);

    admin.addEventListener('keydown', (event) => {
        const tab = event.target.closest('[role="tab"]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const groupTabs = Array.from(tab.closest('[data-tab-group]').querySelectorAll('[role="tab"]'));
        const position = groupTabs.indexOf(tab);
        const offset = event.key === 'ArrowLeft' ? -1 : 1;
        const target = event.key === 'Home' ? groupTabs[0] : event.key === 'End' ? groupTabs.at(-1)
            : groupTabs[(position + offset + groupTabs.length) % groupTabs.length];
        event.preventDefault();
        selectTab(target.dataset.tab);
        target.focus();
    });

    admin.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.tabSection) {
            const section = button.dataset.tabSection;
            const group = tabGroups.find((item) => item.dataset.tabGroup === section);
            selectTab(lastTab.get(section) || group.querySelector('[role="tab"]').dataset.tab);
        } else if (button.dataset.tab) {
            selectTab(button.dataset.tab);
        } else if (button.hasAttribute('data-cdn-add')) {
            const row = template.content.cloneNode(true);
            const focus = row.querySelector('select');
            list.append(row);
            refreshCdnRows();
            focus.focus();
        } else if (button.hasAttribute('data-cdn-remove')) {
            if (!window.confirm(admin.dataset.confirmCdnRemoval)) return;
            button.closest('[data-cdn-row]').remove();
            refreshCdnRows();
            addCdn.focus();
        } else if (button.hasAttribute('data-cdn-up') || button.hasAttribute('data-cdn-down')) {
            const row = button.closest('[data-cdn-row]');
            const target = button.hasAttribute('data-cdn-up') ? row.previousElementSibling : row.nextElementSibling;
            if (!target) return;
            if (button.hasAttribute('data-cdn-up')) target.before(row);
            else target.after(row);
            refreshCdnRows();
            (button.disabled ? row.querySelector('select') : button).focus();
        } else if (button.hasAttribute('data-cdn-discover')) {
            const row = button.closest('[data-cdn-row]');
            run(button, async () => {
                const rowStatus = row.querySelector('[data-cdn-status]');
                rowStatus.textContent = '';
                const data = await request('ys_cache_cdn_discover', {
                    row: JSON.stringify(rowData(row)),
                    index: row.dataset.savedIndex,
                });
                row.querySelector('[data-key="zone_id"]').value = data.resource.id;
                rowStatus.textContent = [data.message, data.resource.name].filter(Boolean).join(' — ');
            });
        } else if (button.dataset.enhance) {
            run(button, () => checkEnhance(button.dataset.enhance));
        } else if (button.dataset.purge) {
            run(button, async () => {
                results.replaceChildren();
                const data = await request('ys_cache_purge', { scope: button.dataset.purge });
                showResults(data.results);
            }, purgeStatus);
        }
    });
    selectTab(window.location.hash.replace(/^#ys-cache-panel-/, '') || 'html', false);
    validateLifetime();
    refreshCdnRows();
    if (admin.dataset.hasEnhance === '1') {
        const check = admin.querySelector('[data-enhance="status"]');
        run(check, () => checkEnhance('status'));
    }
})();
