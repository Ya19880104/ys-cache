(() => {
    const form = document.getElementById('ys-cache-import-form');
    const result = document.getElementById('ys-cache-import-result');
    if (!form || !result) {
        return;
    }

    const submit = form.querySelector('[type="submit"]');
    const send = async (body) => {
        const response = await fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            body,
        });
        const data = await response.json();
        if (typeof data?.data?.message !== 'string') {
            throw new Error('Invalid migration response');
        }
        return data;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        result.textContent = '';

        try {
            const body = new FormData(form);
            let data = await send(body);
            if (data.data.restore === true) {
                result.textContent = data.data.message;
                body.set('stage', 'restore');
                data = await send(body);
                result.textContent = '';
            }

            const notice = document.createElement('div');
            notice.className = data.success ? 'notice notice-success inline' : 'notice notice-error inline';
            const message = document.createElement('p');
            message.textContent = data.data.message;
            notice.append(message);
            if (Array.isArray(data.data.warnings) && data.data.warnings.length) {
                const warnings = document.createElement('ul');
                for (const warning of data.data.warnings) {
                    const line = document.createElement('li');
                    line.textContent = warning;
                    warnings.append(line);
                }
                notice.append(warnings);
            }
            result.append(notice);
            form.hidden = data.success;
        } catch (error) {
            result.textContent = ysCacheImport.requestFailed;
        } finally {
            submit.disabled = false;
        }
    });
})();
