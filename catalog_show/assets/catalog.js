document.querySelectorAll('[data-demo-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const root = form.closest('[data-catalog-show]');
        const output = root ? root.querySelector('[data-demo-output]') : null;
        if (output) output.textContent = '執行中...';
        try {
            const res = await fetch(form.dataset.endpoint || 'api_proxy.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const json = await res.json();
            const payload = json && typeof json === 'object' && json.json && typeof json.json === 'object' ? json.json : json;
            ['audio_id', 'image_id'].forEach((key) => {
                if (!payload || !payload[key]) return;
                const input = form.querySelector(`input[name="${key}"]`);
                if (input) input.value = payload[key];
            });
            if (output) output.textContent = JSON.stringify(json, null, 2);
        } catch (error) {
            if (output) output.textContent = JSON.stringify({ ok: false, error: 'request_failed', message: String(error) }, null, 2);
        }
    });
});
