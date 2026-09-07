// HTML responses come from Adelia, but are still treated as data when inserted.
export const appRoot = new URL('../', import.meta.url);
export const validId = value => typeof value === 'string' && /^[1-9][0-9]{0,17}$/.test(value);

export function safeLink(value) {
    try {
        const url = new URL(value, location.href);
        return ['https:', 'http:', 'mailto:'].includes(url.protocol) && !url.username && !url.password ? url : null;
    } catch { return null; }
}

function mediaUrl(value) {
    const url = safeLink(value);
    if (!url || url.origin !== appRoot.origin || !url.pathname.startsWith(appRoot.pathname)) return null;
    const path = url.pathname.slice(appRoot.pathname.length);
    return /^(?:(?:src|thumb)\/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|gif|webp|mp4|webm)|(?:sticky|lock)\.png)$/i.test(path) ? url : null;
}

function parseInert(html) {
    if (typeof html !== 'string' || html.length > 2 * 1024 * 1024) throw new Error('Invalid post response.');
    // A detached template is an inert parser. Its content is NEVER attached to
    // the page: only fresh nodes constructed by the allowlist below are used.
    const template = document.createElement('template');
    template.innerHTML = html;
    return template.content;
}

const allowedTags = new Set('a abbr b blockquote br caption code del div em h1 h2 h3 h4 hr i img input label li ol p pre s small span strong sub sup table tbody td tfoot th thead time tr u ul'.split(' '));
const blockedTags = new Set('script style link meta base iframe object embed svg math template form button textarea select option video audio source'.split(' '));
const postIdPattern = /^(?:(?:post|backlinks|thumbnail|thumbfile|file|expand)?[1-9][0-9]{0,17})$/;

export function copyPost(source, preview = false) {
    let remaining = 12000;
    const owner = /^post([1-9][0-9]{0,17})$/.exec(source.id)?.[1];
    const usedIds = new Set();
    function copy(node, depth = 0) {
        if (--remaining < 0 || depth > 80) return document.createTextNode('');
        if (node.nodeType === Node.TEXT_NODE) return document.createTextNode(node.textContent);
        if (node.nodeType !== Node.ELEMENT_NODE) return document.createTextNode('');
        const tag = node.localName;
        if (blockedTags.has(tag)) return document.createTextNode('');
        if (!allowedTags.has(tag)) {
            const fragment = document.createDocumentFragment();
            for (const child of node.childNodes) fragment.append(copy(child, depth + 1));
            return fragment;
        }
        if (tag === 'input' && (preview || node.type !== 'checkbox' || node.name !== 'delete[]' || !validId(node.value))) return document.createTextNode('');
        const element = document.createElement(tag);
        const classes = [...node.classList].filter(value => /^[a-zA-Z][a-zA-Z0-9_-]{0,60}$/.test(value));
        if (classes.length) element.className = classes.slice(0, 30).join(' ');
        if (!preview && owner && postIdPattern.test(node.id) && node.id.match(/[0-9]+$/)?.[0] === owner && !usedIds.has(node.id)) {
            element.id = node.id;
            usedIds.add(node.id);
        }
        if (node.hasAttribute('title')) element.title = node.getAttribute('title').slice(0, 500);
        // Preserve configured staff name colours without copying arbitrary CSS.
        if (tag === 'span' && /^(?:#[0-9a-f]{3,8}|[a-z]+|rgba?\([0-9., %]+\))$/i.test(node.style.color)) element.style.color = node.style.color;
        if (node.style.display === 'none' || node.hidden) element.hidden = true;
        if (tag === 'a') {
            const href = safeLink(node.getAttribute('href'));
            if (node.hasAttribute('href') && href) element.href = href.href;
            if (node.target === '_blank') { element.target = '_blank'; element.rel = 'noopener noreferrer'; }
            for (const key of ['quote', 'expand']) {
                if (!preview && validId(node.dataset[key])) element.dataset[key] = node.dataset[key];
            }
        }
        if (tag === 'img') {
            const src = mediaUrl(node.getAttribute('src'));
            if (!src) return document.createTextNode('');
            element.src = src.href;
            element.alt = node.getAttribute('alt') ?? '';
            for (const key of ['width', 'height']) {
                if (/^[1-9][0-9]{0,4}$/.test(node.getAttribute(key) ?? '')) element[key] = Number(node.getAttribute(key));
            }
        }
        if (tag === 'input') { element.type = 'checkbox'; element.name = 'delete[]'; element.value = node.value; }
        if (tag === 'time' && node.hasAttribute('datetime')) element.dateTime = node.dateTime;
        for (const child of node.childNodes) element.append(copy(child, depth + 1));
        return element;
    }
    return copy(source);
}

export function readPost(html, id) {
    const fragment = parseInert(html);
    const candidates = [...fragment.children].filter(node => node.matches('div.post'));
    if (candidates.length !== 1 || candidates[0].id !== `post${id}`) throw new Error('This post is unavailable.');
    return copyPost(candidates[0]);
}

export function expandedMedia(html, id) {
    const source = parseInert(html);
    const image = source.querySelector('img');
    const video = source.querySelector('video');
    const frame = source.querySelector('iframe');
    let element;
    let original;
    if (image) {
        const url = mediaUrl(image.getAttribute('src'));
        if (!url || !/\.(?:jpe?g|png|gif|webp)$/i.test(url.pathname)) throw new Error('Image preview unavailable.');
        const link = document.createElement('a');
        link.href = url.href;
        link.dataset.expand = id;
        element = document.createElement('img');
        element.src = url.href;
        element.alt = 'Expanded image; click to collapse';
        link.append(element);
        original = image;
        dimensions(element, original);
        return link;
    }
    if (video) {
        const url = mediaUrl(video.getAttribute('src') || video.querySelector('source')?.getAttribute('src'));
        if (!url || !/\.(?:mp4|webm)$/i.test(url.pathname)) throw new Error('Video preview unavailable.');
        element = document.createElement('video');
        element.src = url.href;
        element.controls = true;
        element.autoplay = true;
        element.loop = true;
        element.playsInline = true;
        original = video;
    } else if (frame) {
        const url = safeLink(frame.getAttribute('src'));
        const hosts = new Set(['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'w.soundcloud.com']);
        if (!url || url.protocol !== 'https:' || !hosts.has(url.hostname) || (url.port && url.port !== '443')) throw new Error('Embed preview unavailable.');
        element = document.createElement('iframe');
        element.src = url.href;
        element.title = 'Embedded media';
        element.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
        element.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        element.allowFullscreen = true;
        element.referrerPolicy = 'strict-origin-when-cross-origin';
        original = frame;
    } else { throw new Error('Media preview unavailable.'); }
    dimensions(element, original);
    const wrapper = document.createElement('div');
    const collapse = document.createElement('a');
    collapse.href = '#';
    collapse.dataset.expand = id;
    collapse.textContent = 'Close preview';
    wrapper.append(element, document.createElement('br'), collapse);
    return wrapper;
}

function dimensions(element, source) {
    for (const key of ['width', 'height']) {
        const value = source.getAttribute(key);
        if (/^[1-9][0-9]{0,4}$/.test(value ?? '')) element.setAttribute(key, value);
    }
    for (const key of ['minWidth', 'minHeight', 'maxWidth', 'maxHeight', 'width', 'height']) {
        const value = source.style[key];
        if (/^(?:auto|[0-9]+(?:\.[0-9]+)?(?:px|vw|vh|%))$/.test(value)) element.style[key] = value;
    }
    if (element.localName !== 'iframe') element.style.height = 'auto';
}
