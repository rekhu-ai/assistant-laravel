/*! @rekhu/assistant-widget 0.1.3 · https://rekhu.com · generated from src/index.js, do not edit */
(() => {
/*
 * The Rekhu Assistant widget: a chat button and panel as <rekhu-assistant>, in its own shadow DOM so the app's CSS and
 * this one never meet. It speaks protocol 1 (PROTOCOL.md) with a Rekhu server package in the app's back end, for
 * example @rekhu/assistant-node. The conversation lives on the server; the browser only keeps its id.
 *
 * Nothing touches the DOM until define() or mount() runs, so importing it during server rendering (Nuxt, Next) is safe.
 */

const PROTOCOL = 1;

const CSS = `
:host { all: initial; --c: #4f46e5; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; }
* { box-sizing: border-box; }
.launcher { position: fixed; inset-inline-end: 20px; bottom: 20px; z-index: 2147483000; width: 56px; height: 56px; border-radius: 50%; border: 0; background: var(--c); color: #fff; cursor: pointer; box-shadow: 0 8px 24px rgba(0,0,0,.18); display: grid; place-items: center; }
.launcher svg { width: 26px; height: 26px; }
.panel { position: fixed; inset-inline-end: 20px; bottom: 88px; z-index: 2147483000; width: min(400px, calc(100vw - 32px)); height: min(600px, calc(100vh - 120px)); background: #fff; border-radius: 16px; box-shadow: 0 16px 48px rgba(0,0,0,.2); display: none; flex-direction: column; overflow: hidden; }
.panel.open { display: flex; }
header { display: flex; align-items: center; gap: 8px; padding: 12px 14px; background: var(--c); color: #fff; }
header strong { flex: 1; font-size: 15px; }
header button { background: transparent; border: 0; color: #fff; opacity: .85; cursor: pointer; font-size: 12px; padding: 4px 6px; border-radius: 6px; }
header button:hover { opacity: 1; background: rgba(255,255,255,.15); }
.log { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: #f9fafb; }
.msg { max-width: 88%; padding: 8px 12px; border-radius: 14px; white-space: normal; word-wrap: break-word; }
.msg p { margin: 0 0 6px; } .msg p:last-child { margin: 0; } .msg ul, .msg ol { margin: 4px 0; padding-inline-start: 20px; }
.msg a { color: var(--c); }
.user { align-self: flex-end; background: var(--c); color: #fff; border-end-end-radius: 4px; }
.user a { color: #fff; }
.bot { align-self: flex-start; background: #fff; border: 1px solid #e5e7eb; border-end-start-radius: 4px; }
.step { align-self: flex-start; font-size: 12px; color: #6b7280; }
.step::before { content: ""; display: inline-block; width: 8px; height: 8px; margin-inline-end: 6px; border-radius: 50%; background: var(--c); animation: pulse 1s infinite alternate; }
.step.over::before { animation: none; background: #9ca3af; }
@keyframes pulse { from { opacity: .3 } to { opacity: 1 } }
.card { align-self: stretch; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; }
.card .title { font-weight: 600; }
.card .detail { font-size: 12px; color: #4b5563; }
.card.done { border-color: #a7f3d0; background: #ecfdf5; } .card.failed { border-color: #fecaca; background: #fef2f2; }
.row { display: flex; gap: 8px; flex-wrap: wrap; }
.btn { border: 0; border-radius: 8px; padding: 7px 12px; font: inherit; font-weight: 600; cursor: pointer; background: var(--c); color: #fff; text-decoration: none; display: inline-block; }
.btn.light { background: #f3f4f6; color: #374151; } .btn.danger { background: #dc2626; }
.btn:disabled { opacity: .5; cursor: default; }
dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; font-size: 12px; } dt { color: #6b7280; } dd { margin: 0; }
form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e5e7eb; background: #fff; }
textarea { flex: 1; resize: none; border: 1px solid #d1d5db; border-radius: 10px; padding: 8px 10px; font: inherit; max-height: 120px; outline: none; }
textarea:focus { border-color: var(--c); }
.error { align-self: stretch; font-size: 13px; color: #991b1b; background: #fef2f2; border-radius: 10px; padding: 8px 10px; }
`;

const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

const escape = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// a small, safe markdown: everything escaped first, then paragraphs, lists, bold, italics, code and links to http(s) or
// the app's own paths (never javascript:, data: or //other-host)
const markdown = (text) => {
    const inline = (s) => escape(s)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/(?!\/))[^\s)]+)\)/g, '<a href="$2" target="_top" rel="noopener">$1</a>');
    const out = [];
    let list = null;
    for (const line of String(text).split('\n')) {
        const item = line.match(/^\s*(?:[-*]|\d+\.)\s+(.*)$/);
        if (item) {
            const kind = /^\s*\d+\./.test(line) ? 'ol' : 'ul';
            if (!list || list.kind !== kind) { if (list) out.push(`</${list.kind}>`); list = { kind }; out.push(`<${kind}>`); }
            out.push(`<li>${inline(item[1])}</li>`);
            continue;
        }
        if (list) { out.push(`</${list.kind}>`); list = null; }
        if (line.trim()) out.push(`<p>${inline(line)}</p>`);
    }
    if (list) out.push(`</${list.kind}>`);
    return out.join('');
};

// the widget's own words, in the user's language (the "locale" option, else the page's lang, else the browser's);
// the "texts" option overrides any of them
const TEXTS = {
    en: {
        signedOut: 'Your session has ended. Reload the page and sign in again.',
        busy: 'That was a lot of messages at once. Wait a minute and try again.',
        unavailable: 'The assistant is not available right now. Please try again in a moment.',
        failed: 'Something went wrong.',
        name: 'Assistant', new: 'New', newTitle: 'Start a new conversation', close: 'Close', message: 'Message',
        placeholder: 'Type a message…', send: 'Send', open: 'Open', confirm: 'Confirm', cancel: 'Cancel',
        confirmed: 'Confirmed', cancelled: 'Cancel that',
    },
    ar: {
        signedOut: 'انتهت جلستك. أعد تحميل الصفحة وسجّل الدخول من جديد.',
        busy: 'رسائل كثيرة في وقت واحد. انتظر دقيقة ثم حاول مرة أخرى.',
        unavailable: 'المساعد غير متاح الآن. حاول مرة أخرى بعد قليل.',
        failed: 'حدث خطأ ما.',
        name: 'المساعد', new: 'جديد', newTitle: 'ابدأ محادثة جديدة', close: 'إغلاق', message: 'الرسالة',
        placeholder: 'اكتب رسالتك…', send: 'إرسال', open: 'فتح', confirm: 'تأكيد', cancel: 'إلغاء',
        confirmed: 'تم التأكيد', cancelled: 'ألغِ ذلك',
    },
    nl: {
        signedOut: 'Je sessie is verlopen. Laad de pagina opnieuw en log weer in.',
        busy: 'Dat waren veel berichten tegelijk. Wacht een minuut en probeer het opnieuw.',
        unavailable: 'De assistent is nu niet beschikbaar. Probeer het zo opnieuw.',
        failed: 'Er ging iets mis.',
        name: 'Assistent', new: 'Nieuw', newTitle: 'Begin een nieuw gesprek', close: 'Sluiten', message: 'Bericht',
        placeholder: 'Typ een bericht…', send: 'Verstuur', open: 'Openen', confirm: 'Bevestigen', cancel: 'Annuleren',
        confirmed: 'Bevestigd', cancelled: 'Annuleer dat',
    },
};

const language = (locale) => {
    const wanted = String(locale || document.documentElement.lang || navigator.language || 'en').toLowerCase().split(/[-_]/)[0];
    return TEXTS[wanted] ? wanted : 'en';
};

const build = () => class RekhuAssistantElement extends HTMLElement {
    /** Options set from JavaScript (mount() or element.configure()) win over the data-settings attribute. */
    configure(options = {}) {
        this.options = { ...(this.options || {}), ...options };
        if (this.shadowRoot && options.color) this.style.setProperty('--c', options.color);
        return this;
    }

    /** The widget's own words in the user's language. */
    get t() {
        return { ...TEXTS[language(this.settings.locale)], ...(this.settings.texts || {}) };
    }

    get settings() {
        let attribute = {};
        try { attribute = JSON.parse(this.dataset.settings || '{}'); } catch { /* a broken attribute is no settings */ }
        return { credentials: 'same-origin', storage: 'session', ...attribute, ...(this.options || {}) };
    }

    connectedCallback() {
        if (this.shadowRoot) return;
        const s = this.settings;
        const t = this.t;
        if (!s.endpoint) { console.error('<rekhu-assistant>: no endpoint; pass data-settings or mount({ endpoint })'); return; }
        this.conversation = this.load('conversation');
        this.busy = false;
        const root = this.attachShadow({ mode: 'open' });
        root.innerHTML = `<style>${CSS}</style>
<button class="launcher" part="launcher" aria-label="${escape(s.name || t.name)}">${ICON}</button>
<section class="panel" part="panel" role="dialog" aria-label="${escape(s.name || t.name)}">
  <header><strong>${escape(s.name || t.name)}</strong><button class="restart" title="${escape(t.newTitle)}">${escape(t.new)}</button><button class="close" aria-label="${escape(t.close)}">✕</button></header>
  <div class="log" aria-live="polite"></div>
  <form><textarea rows="1" placeholder="${escape(s.placeholder || t.placeholder)}" aria-label="${escape(t.message)}"></textarea><button class="btn" type="submit">${escape(t.send)}</button></form>
</section>`;
        // "dir": the page's direction is followed by itself (the host inherits it); an explicit one wins
        if (s.dir === 'rtl' || s.dir === 'ltr') this.setAttribute('dir', s.dir);
        if (s.color) this.style.setProperty('--c', s.color);
        this.panel = root.querySelector('.panel');
        this.log = root.querySelector('.log');
        this.input = root.querySelector('textarea');
        this.sendButton = root.querySelector('form .btn');
        root.querySelector('.launcher').addEventListener('click', () => this.toggle());
        root.querySelector('.close').addEventListener('click', () => this.toggle(false));
        root.querySelector('.restart').addEventListener('click', () => this.restart());
        root.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); this.send(); });
        this.input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); } });
        this.input.addEventListener('input', () => { this.input.style.height = 'auto'; this.input.style.height = `${this.input.scrollHeight}px`; });
        // every link in the chat (page cards, links in answers) goes through the app's router when it has one
        this.log.addEventListener('click', (e) => {
            const link = e.composedPath().find((el) => el instanceof HTMLAnchorElement);
            if (link && this.go(link.getAttribute('href'))) e.preventDefault();
        });
        this.greet();
        // the conversation so far, and the server's greeting when the app gave none here
        if (this.conversation || !s.greeting) this.restore();
        if (this.load('open') === '1') this.toggle(true);
    }

    /** True when the app's own router took the link; false lets the browser follow it. */
    go(href) {
        const navigate = this.settings.navigate;
        if (typeof navigate !== 'function' || !href) return false;
        let url;
        try { url = new URL(href, location.href); } catch { return false; }
        if (url.origin !== location.origin && !this.settings.navigateOrigins?.includes(url.origin)) return false;
        return navigate(url.origin === location.origin ? url.pathname + url.search + url.hash : url.href, url) !== false;
    }

    open() { this.toggle(true); }
    close() { this.toggle(false); }

    // a visitor's conversation and a signed-in user's are kept apart, so signing in starts clean
    key(name) { return `rekhu-assistant:${this.settings.visitor ? 'visitor:' : ''}${name}`; }

    load(name) {
        const store = this.storageArea();
        try { return store ? store.getItem(this.key(name)) : null; } catch { return null; }
    }

    store(name, value) {
        const store = this.storageArea();
        try { if (store) value === null ? store.removeItem(this.key(name)) : store.setItem(this.key(name), value); } catch { /* private mode */ }
    }

    /** The public chat: an anonymous id for this browser, kept across visits so the server can cap it per day. */
    visitorId() {
        if (this.visitorCache) return this.visitorCache;
        let id = null;
        try { id = localStorage.getItem('rekhu-assistant:visitor-id'); } catch { /* private mode: one id for this page */ }
        if (!id || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) {
            // randomUUID needs https or localhost; elsewhere the same shape from getRandomValues
            id = crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
            try { localStorage.setItem('rekhu-assistant:visitor-id', id); } catch { /* kept for this page only */ }
        }
        this.visitorCache = id;
        return id;
    }

    storageArea() {
        const kind = this.settings.storage;
        try { return kind === 'local' ? localStorage : kind === 'none' ? null : sessionStorage; } catch { return null; }
    }

    async headers(accept) {
        const s = this.settings;
        const extra = typeof s.headers === 'function' ? await s.headers() : (s.headers || {});
        return {
            'Content-Type': 'application/json',
            Accept: accept,
            'X-Requested-With': 'XMLHttpRequest',
            'X-Rekhu-Protocol': String(PROTOCOL),
            ...(s.csrf ? { 'X-CSRF-TOKEN': s.csrf } : {}),
            ...(s.visitor ? { 'X-Rekhu-Visitor': this.visitorId() } : {}),
            ...extra,
        };
    }

    async call(method, body, accept = 'application/json', query = '') {
        const s = this.settings;
        return fetch(s.endpoint + query, { method, headers: await this.headers(accept), credentials: s.credentials, body: body ? JSON.stringify(body) : undefined });
    }

    // after a reload, the conversation goes on where it was
    async restore() {
        try {
            const query = this.conversation ? `${this.settings.endpoint.includes('?') ? '&' : '?'}conversation=${encodeURIComponent(this.conversation)}` : '';
            const response = await this.call('GET', null, 'application/json', query);
            if (!response.ok) return;
            const data = await response.json();
            if (!this.settings.greeting && data.greeting) { this.serverGreeting = data.greeting; this.greet(); }
            for (const line of data.messages || []) this.add(line.role, markdown(line.text));
        } catch { /* the greeting stays */ }
    }

    toggle(open = !this.panel.classList.contains('open')) {
        this.panel.classList.toggle('open', open);
        this.store('open', open ? '1' : null);
        if (open) this.input.focus();
    }

    greet() {
        this.log.innerHTML = '';
        const greeting = this.settings.greeting || this.serverGreeting;
        if (greeting) this.add('bot', markdown(greeting));
    }

    async restart() {
        if (this.busy) return;
        const id = this.conversation;
        this.conversation = null;
        this.store('conversation', null);
        this.greet();
        if (id) this.call('DELETE', { conversation: id }).catch(() => {});
    }

    add(kind, html) {
        const el = document.createElement('div');
        el.className = kind === 'user' || kind === 'bot' ? `msg ${kind}` : kind;
        el.innerHTML = html;
        this.log.appendChild(el);
        this.log.scrollTop = this.log.scrollHeight;
        return el;
    }

    send() {
        const text = this.input.value.trim();
        if (!text || this.busy) return;
        this.input.value = '';
        this.input.style.height = 'auto';
        this.add('user', markdown(text));
        this.post({ message: text });
    }

    emit(name, detail) {
        this.dispatchEvent(new CustomEvent(`rekhu:${name}`, { detail, bubbles: true, composed: true }));
    }

    async post(payload) {
        this.busy = true;
        this.sendButton.disabled = true;
        let bubble = null;
        let text = '';
        let step = null;
        const endStep = () => { if (step) { step.classList.add('over'); step = null; } };
        try {
            const t = this.t;
            const locale = this.settings.locale === undefined ? (navigator.language || null) : this.settings.locale;
            const response = await this.call('POST', { ...payload, conversation: this.conversation, locale }, 'text/event-stream');
            // the server says why in the user's language; the widget's own words when it says nothing
            const said = async () => (response.headers.get('content-type') || '').includes('json') ? ((await response.json().catch(() => ({}))).message || '') : '';
            if (response.status === 401 || response.status === 419) { this.emit('unauthorized', { status: response.status }); throw new Error(t.signedOut); }
            if (response.status === 429) throw new Error((await said()) || t.busy);
            if (!response.ok || !response.body) throw new Error((await said()) || t.unavailable);
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            for (;;) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let end;
                while ((end = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, end);
                    buffer = buffer.slice(end + 2);
                    const line = raw.split('\n').find((l) => l.startsWith('data:'));
                    if (!line) continue;
                    const event = JSON.parse(line.slice(5));
                    if (event.type === 'start') { this.conversation = event.conversation; this.store('conversation', event.conversation); }
                    if (event.type === 'text') {
                        endStep();
                        if (!bubble) { bubble = this.add('bot', ''); text = ''; }
                        text += event.text;
                        bubble.innerHTML = markdown(text);
                        this.log.scrollTop = this.log.scrollHeight;
                    }
                    if (event.type === 'step') { endStep(); bubble = null; step = this.add('step', escape(event.label)); }
                    if (event.type === 'card') { endStep(); bubble = null; this.card(event); }
                    if (event.type === 'error') { endStep(); this.add('error', escape(event.message)); this.emit('error', event); }
                }
            }
        } catch (error) {
            this.add('error', escape(error.message || this.t.failed));
        } finally {
            endStep();
            this.busy = false;
            this.sendButton.disabled = false;
        }
    }

    /** The arrow on a link button points the way the text reads. */
    get arrow() {
        return getComputedStyle(this).direction === 'rtl' ? '←' : '→';
    }

    card(event) {
        if (event.kind === 'page') {
            this.add('card', `<div class="row"><a class="btn" href="${escape(event.url)}" target="_top">${escape(event.label)} ${this.arrow}</a></div>`);
        }
        if (event.kind === 'done' || event.kind === 'failed') {
            const link = event.url ? `<div class="row"><a class="btn light" href="${escape(event.url)}" target="_top">${escape(this.t.open)} ${this.arrow}</a></div>` : '';
            this.add(`card ${event.kind}`, `<div class="title">${event.kind === 'done' ? '✓' : '✕'} ${escape(event.title)}</div>${event.message ? `<div class="detail">${escape(event.message)}</div>` : ''}${link}`);
            // the app's data changed: a single page app refreshes what it shows
            if (event.kind === 'done') {
                this.emit('action', event);
                if (typeof this.settings.onAction === 'function') this.settings.onAction(event);
            }
        }
        if (event.kind === 'confirm') {
            const rows = Object.entries(event.input || {}).map(([k, v]) => `<dt>${escape(k.replace(/_/g, ' '))}</dt><dd>${escape(typeof v === 'object' ? JSON.stringify(v) : v)}</dd>`).join('');
            const el = this.add('card', `<div class="title">${escape(event.title)}</div>${rows ? `<dl>${rows}</dl>` : ''}<div class="row"><button class="btn ${event.danger ? 'danger' : ''}" data-yes>${escape(this.t.confirm)}</button><button class="btn light" data-no>${escape(this.t.cancel)}</button></div>`);
            const buttons = el.querySelectorAll('button');
            el.querySelector('[data-yes]').addEventListener('click', () => {
                if (this.busy) return;
                buttons.forEach((b) => { b.disabled = true; });
                this.add('user', markdown(this.t.confirmed));
                this.post({ confirm: event.id });
            });
            el.querySelector('[data-no]').addEventListener('click', () => {
                if (this.busy) return;
                buttons.forEach((b) => { b.disabled = true; });
                this.add('user', markdown(this.t.cancelled));
                this.post({ message: 'No, cancel that.' });
            });
        }
    }
};

/** Registers <rekhu-assistant> once; does nothing outside a browser. */
function define() {
    if (typeof window === 'undefined' || !window.customElements) return;
    if (!customElements.get('rekhu-assistant')) customElements.define('rekhu-assistant', build());
}

/** Adds the assistant to the page (document.body unless "target" is given) and returns the element; call destroy() to remove it. */
function mount(options = {}) {
    if (typeof window === 'undefined') return null;
    define();
    const el = document.createElement('rekhu-assistant');
    el.configure(options);
    (options.target || document.body).appendChild(el);
    el.destroy = () => el.remove();
    return el;
}

define();
window.RekhuAssistant = { mount, define, PROTOCOL };
})();
