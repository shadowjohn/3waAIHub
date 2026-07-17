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
            if (output) output.textContent = JSON.stringify(json, null, 2);
        } catch (error) {
            if (output) output.textContent = JSON.stringify({ ok: false, error: 'request_failed', message: String(error) }, null, 2);
        }
    });
});
