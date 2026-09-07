'use strict';

(() => {
    const root = new URL(document.querySelector('script[src$="security.js"]').src).pathname.replace(/js\/security\.js$/, '');
    const endpoint = `${root}imgboard.php`;
    const mutating = ['delete', 'deleteaccount', 'deletekeyword', 'approve', 'sticky', 'lock', 'clearreports', 'rebuildall', 'logout', 'verify', 'report'];
    let tokenRequest;
    const token = () => tokenRequest ??= fetch(`${endpoint}?csrf`, {credentials: 'same-origin', cache: 'no-store'})
        .then(response => { if (!response.ok) throw new Error('Could not prepare the form. Reload the page.'); return response.json(); })
        .then(result => result.token);

    function insertToken(form, value) {
        let field = form.querySelector('input[name="_csrf"]');
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = '_csrf'; form.append(field); }
        field.value = value;
    }
    document.addEventListener('submit', async event => {
        const form = event.target;
        const url = new URL(form.action, location.href);
        const modifies = form.method.toLowerCase() === 'post' || mutating.some(key => url.searchParams.has(key) || form.elements.namedItem(key));
        if (!modifies) return;
        event.preventDefault();
        try {
            insertToken(form, await token());
            if (form.method.toLowerCase() !== 'post') {
                for (const [key, value] of new FormData(form)) {
                    if (key !== '_csrf' && typeof value === 'string') url.searchParams.append(key, value);
                }
                form.action = url.href;
                form.method = 'post';
            }
            if (event.submitter?.name) {
                const button = document.createElement('input');
                button.type = 'hidden';
                button.name = event.submitter.name;
                button.value = event.submitter.value;
                form.append(button);
            }
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) { alert(error.message); }
    });
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[href]');
        if (!link) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !mutating.some(key => url.searchParams.has(key))) return;
        event.preventDefault();
        try {
            const form = document.createElement('form');
            form.action = url.href;
            form.method = 'post';
            insertToken(form, await token());
            document.body.append(form);
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) { alert(error.message); }
    });
    token().catch(() => {});
})();
