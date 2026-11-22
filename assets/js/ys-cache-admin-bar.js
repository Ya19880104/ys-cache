(() => {
    if (typeof ysCacheToolbar === 'undefined') return;

    const toolbar = document.getElementById('wp-admin-bar-ys-cache-purge');
    if (!toolbar) return;

    const config = ysCacheToolbar;
    const actions = {
        'wp-admin-bar-ys-cache-purge-page': 'page',
        'wp-admin-bar-ys-cache-purge-site': 'site',
    };
    let busy = false;

    const promptForPage = () => {
        const input = window.prompt(config.prompt, config.home);
        if (input === null) return null;
        const page = input.trim();
        if (!page.startsWith('/') && !/^https?:\/\//i.test(page)) throw new Error(config.invalid);

        const home = new URL(config.home);
        const target = new URL(page, home);
        const allowedHost = target.hostname === home.hostname || (config.aliases || []).includes(target.hostname);
        if (!allowedHost || target.protocol !== home.protocol || target.port !== home.port || target.username || target.password || target.search) {
            throw new Error(config.invalid);
        }
        target.hash = '';
        return target.href;
    };

    toolbar.addEventListener('click', async (event) => {
        const anchor = event.target.closest('a');
        const scope = actions[anchor?.closest('li')?.id];
        if (!scope) return;
        event.preventDefault();
        if (busy) return;

        let page = '';
        if (scope === 'page') {
            try {
                page = config.page || promptForPage();
                if (page === null) return;
            } catch {
                window.alert(config.invalid);
                return;
            }
        }

        busy = true;
        toolbar.setAttribute('aria-busy', 'true');
        window.wp?.a11y?.speak?.(config.pending);
        try {
            const response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ action: config.action, nonce: config.nonce, scope, url: page }),
            });
            const payload = await response.json();
            if (!response.ok || !payload?.success) {
                window.alert(payload?.data?.message || config.failed);
                return;
            }
            const results = payload.data.results || [];
            const heading = results.some((result) => result.status === 'error') ? config.failed : config.complete;
            const detail = results.map((result) => [result.layer, result.message].filter(Boolean).join(' — '));
            window.alert([heading, ...detail].join('\n'));
        } catch {
            window.alert(config.failed);
        } finally {
            busy = false;
            toolbar.removeAttribute('aria-busy');
        }
    });
})();
