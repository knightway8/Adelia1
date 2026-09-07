import { appRoot, validId, safeLink, copyPost, readPost, expandedMedia } from './dom.js?v=adelia-1';

const endpoint = new URL('imgboard.php', appRoot);
const posts = document.getElementById('posts');
const originalTitle = document.title;
const inThread = /^res\/[0-9]+\.html$/.test(location.pathname.slice(appRoot.pathname.length));

function cookie(name) {
    const entry = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith(`${name}=`));
    try { return entry ? decodeURIComponent(entry.slice(name.length + 1)) : ''; } catch { return ''; }
}
function saveCookie(name, value, seconds) {
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${seconds}; Path=${appRoot.pathname}; SameSite=Strict${location.protocol === 'https:' ? '; Secure' : ''}`;
}
const styleSelector = document.getElementById('switchStylesheet');
function setStyle(style) {
    if (!styleSelector || ![...styleSelector.options].some(option => option.value === style)) return;
    const link = document.getElementById('mainStylesheet');
    if (!link) return;
    link.href = new URL(`stylesheets/${encodeURIComponent(style)}.css`, appRoot).href;
    styleSelector.value = style;
    saveCookie('adelia_style', style, 31536000);
}
setStyle(cookie('adelia_style'));
styleSelector?.addEventListener('change', () => setStyle(styleSelector.value));

const postPassword = document.getElementById('newpostpassword');
const deletePassword = document.getElementById('deletepostpassword');
const savedPassword = cookie('adelia_password');
if (postPassword) postPassword.value = savedPassword;
if (deletePassword) deletePassword.value = savedPassword;
postPassword?.addEventListener('change', () => {
    saveCookie('adelia_password', postPassword.value, postPassword.value ? 220752000 : 0);
    if (deletePassword) deletePassword.value = postPassword.value;
});
function quote(id) {
    const message = document.getElementById('message');
    if (!message || !validId(id)) return;
    message.value += `>>${id}\n`;
    message.focus();
    message.setSelectionRange(message.value.length, message.value.length);
}
function quoteHash() { const match = /^#q([1-9][0-9]{0,17})$/.exec(location.hash); if (match) quote(match[1]); }
const focusField = document.body.dataset.focus;
if (focusField) document.getElementById(focusField)?.focus();
quoteHash();
window.addEventListener('hashchange', quoteHash);
let captchaNonce = 0;
function refreshCaptcha() {
    const image = document.getElementById('captchaimage');
    if (!image) return;
    const input = document.getElementById('captcha');
    if (input) { input.value = ''; input.focus(); }
    const url = new URL(image.src);
    url.searchParams.set('refresh', `${Date.now()}-${++captchaNonce}`);
    image.src = url.href;
}
function expand(id) {
    if (!validId(id)) return;
    const thumb = document.getElementById(`thumbfile${id}`);
    const target = document.getElementById(`file${id}`);
    const encoded = document.getElementById(`expand${id}`);
    if (!thumb || !target || !encoded) return;
    if (thumb.dataset.expanded === 'true') {
        for (const video of target.querySelectorAll('video')) video.pause();
        target.replaceChildren();
        target.hidden = true;
        thumb.hidden = false;
        thumb.dataset.expanded = 'false';
        thumb.scrollIntoView({block: 'nearest', inline: 'nearest'});
    } else {
        try {
            target.replaceChildren(expandedMedia(decodeURIComponent(encoded.textContent), id));
            target.style.removeProperty('display');
            target.hidden = false;
            thumb.hidden = true;
            thumb.dataset.expanded = 'true';
        } catch {
            target.textContent = 'Preview unavailable. Use the original file link.';
            target.style.removeProperty('display');
            target.hidden = false;
        }
    }
}
document.addEventListener('click', event => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const action = event.target.closest('[data-action="captcha-refresh"], a[data-quote], a[data-expand], a[data-expire]');
    if (!action || action.closest('.hoverpost')) return;
    event.preventDefault();
    if (action.dataset.action === 'captcha-refresh') refreshCaptcha();
    else if (action.dataset.quote) quote(action.dataset.quote);
    else if (action.dataset.expand) expand(action.dataset.expand);
    else if (/^[0-9]+$/.test(action.dataset.expire ?? '')) {
        const input = document.getElementById('expire');
        if (input) input.value = action.dataset.expire;
    }
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closePreview();
    if (['Enter', ' '].includes(event.key) && event.target.matches('[data-action="captcha-refresh"]')) {
        event.preventDefault();
        refreshCaptcha();
    }
});
async function request(url, controller) {
    const timer = setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal});
        if (!response.ok) throw new Error('Request failed.');
        return await response.text();
    } finally { clearTimeout(timer); }
}
function reference(link) {
    if (!(link instanceof HTMLAnchorElement) || link.closest('.hoverpost')) return null;
    const match = /^>>([1-9][0-9]{0,17})$/.exec(link.textContent.trim());
    const url = safeLink(link.getAttribute('href'));
    if (!match || !url || url.origin !== appRoot.origin || url.search || url.hash !== `#${match[1]}`) return null;
    const relative = url.pathname.slice(appRoot.pathname.length);
    return url.pathname.startsWith(appRoot.pathname) && /^res\/[1-9][0-9]{0,17}\.html$/.test(relative) ? match[1] : null;
}
let preview = null;
let keyboardMode = false;
const previewCache = new Map();
function closePreview() {
    if (!preview) return;
    preview.controller.abort();
    preview.element.remove();
    preview.link.removeAttribute('aria-describedby');
    preview = null;
}
function positionPreview(state) {
    if (preview !== state) return;
    const rect = state.link.getBoundingClientRect();
    const box = state.element.getBoundingClientRect();
    const left = Math.max(4, Math.min(rect.left + 14, innerWidth - box.width - 8));
    const below = rect.bottom + 8;
    const top = below + box.height <= innerHeight - 8 ? below : Math.max(4, rect.top - box.height - 8);
    state.element.style.left = `${left}px`;
    state.element.style.top = `${top}px`;
}
function fillPreview(state, source) {
    const clean = copyPost(source, true);
    state.element.className = `post hoverpost ${source.classList.contains('reply') ? 'reply' : 'op'}`;
    state.element.replaceChildren(...clean.childNodes);
    state.element.addEventListener('load', () => positionPreview(state), true);
    positionPreview(state);
}
async function showPreview(link) {
    const id = reference(link);
    if (!id || preview?.link === link) return;
    closePreview();
    const element = document.createElement('div');
    element.id = 'quote-preview';
    element.className = 'post hoverpost reply';
    element.setAttribute('role', 'tooltip');
    element.textContent = 'Loading…';
    const state = {link, element, controller: new AbortController()};
    preview = state;
    link.setAttribute('aria-describedby', element.id);
    document.body.append(element);
    positionPreview(state);
    const local = document.getElementById(`post${id}`);
    if (local?.matches('div.post')) { fillPreview(state, local); return; }
    if (previewCache.has(id)) { fillPreview(state, previewCache.get(id)); return; }
    try {
        const url = new URL(endpoint);
        url.searchParams.set('preview', id);
        if (inThread) url.searchParams.set('res', '');
        const response = await request(url, state.controller);
        if (preview !== state) return;
        const post = readPost(response, id);
        if (previewCache.size >= 50) previewCache.delete(previewCache.keys().next().value);
        previewCache.set(id, post);
        fillPreview(state, post);
    } catch {
        if (preview === state) { element.textContent = 'This post is unavailable.'; positionPreview(state); }
    }
}
document.addEventListener('pointerover', event => {
    if (event.pointerType === 'touch') return;
    const link = event.target.closest('a');
    if (link && !link.contains(event.relatedTarget)) void showPreview(link);
});
document.addEventListener('pointerout', event => {
    if (preview?.link.contains(event.target) && !preview.link.contains(event.relatedTarget)) closePreview();
});
document.addEventListener('pointerdown', () => { keyboardMode = false; });
document.addEventListener('keydown', () => { keyboardMode = true; });
document.addEventListener('focusin', event => { if (keyboardMode && event.target.matches('a')) void showPreview(event.target); });
document.addEventListener('focusout', event => { if (preview?.link === event.target) closePreview(); });
window.addEventListener('resize', closePreview);
window.addEventListener('scroll', () => { if (preview) positionPreview(preview); }, {passive: true});

let cursor = posts?.dataset.since ?? '0';
const threadId = posts?.dataset.thread;
const refreshDelay = Number(posts?.dataset.refresh);
const canRefresh = inThread && validId(threadId) && /^(?:0|[1-9][0-9]{0,17})$/.test(cursor) && refreshDelay > 0 && Number.isFinite(refreshDelay);
let refreshTimer;
let refreshing = null;
let paused = false;
let unread = 0;
let notice;
let refreshStatus;
function scheduleRefresh(delay = refreshDelay * 1000) {
    clearTimeout(refreshTimer);
    if (canRefresh && !paused && !refreshing) refreshTimer = setTimeout(refresh, Math.max(0, delay));
}
function backlinks(post, id) {
    if (posts.dataset.backlinks !== '1') return;
    const targets = new Set([...post.querySelectorAll('.body a')].map(reference).filter(Boolean));
    for (const targetId of targets) {
        const target = document.getElementById(`backlinks${targetId}`);
        if (!target || [...target.querySelectorAll('a')].some(link => link.textContent.trim() === `>>${id}`)) continue;
        const link = document.createElement('a');
        link.href = new URL(`res/${threadId}.html#${id}`, appRoot).href;
        link.textContent = `>>${id}`;
        link.className = 'refreply';
        target.append(document.createTextNode(target.textContent.trim() ? ', ' : ' '), link);
    }
}
async function refresh() {
    if (!canRefresh || paused || refreshing) return;
    const controller = new AbortController();
    refreshing = controller;
    try {
        const url = new URL(endpoint);
        url.searchParams.set('posts', threadId);
        url.searchParams.set('since', cursor);
        const text = await request(url, controller);
        if (text.length > 8 * 1024 * 1024) throw new Error('Response too large.');
        const data = JSON.parse(text);
        if (!data || typeof data !== 'object' || (Array.isArray(data) && data.length)) throw new Error('Invalid reply response.');
        const ids = Object.keys(data);
        if (ids.some(id => !validId(id))) throw new Error('Invalid post identifiers.');
        ids.sort((a, b) => BigInt(a) < BigInt(b) ? -1 : BigInt(a) > BigInt(b) ? 1 : 0);
        // Validate the whole batch before changing the DOM or advancing the cursor.
        const batch = ids.filter(id => BigInt(id) > BigInt(cursor)).map(id => [id, readPost(data[id], id)]);
        if (paused || controller.signal.aborted) return;
        if (refreshStatus) refreshStatus.hidden = true;
        for (const [id, post] of batch) {
            if (!document.getElementById(`post${id}`)) {
                if (!notice) { notice = document.createElement('div'); notice.id = 'newreplies'; notice.textContent = 'New'; notice.setAttribute('role', 'status'); notice.hidden = true; }
                if (notice.hidden) { posts.append(notice); notice.hidden = false; }
                const clear = document.createElement('br');
                clear.className = 'clear';
                posts.append(post, clear);
                backlinks(post, id);
                if (document.hidden || !document.hasFocus()) unread++;
            }
            cursor = id;
            posts.dataset.since = id;
        }
        document.title = unread ? `(${unread} new) ${originalTitle}` : originalTitle;
    } catch {
        if (!paused) {
            if (!refreshStatus) { refreshStatus = document.createElement('p'); refreshStatus.id = 'refresh-status'; refreshStatus.setAttribute('role', 'status'); posts.after(refreshStatus); }
            refreshStatus.textContent = 'Could not check for new replies. Retrying automatically.';
            refreshStatus.hidden = false;
        }
    } finally { refreshing = null; scheduleRefresh(); }
}
window.addEventListener('focus', () => { unread = 0; document.title = originalTitle; scheduleRefresh(0); });
window.addEventListener('blur', () => { if (notice) notice.hidden = true; });
window.addEventListener('pagehide', () => { paused = true; clearTimeout(refreshTimer); refreshing?.abort(); closePreview(); });
window.addEventListener('pageshow', event => { if (event.persisted) { paused = false; scheduleRefresh(0); } });
scheduleRefresh();
