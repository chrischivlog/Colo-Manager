/**
 * Lädt das zentrale Plattform-Branding einmal pro Seite und überträgt es auf
 * die vorhandenen Tailwind-Komponenten. Ohne API bleibt das Standarddesign
 * vollständig erhalten, damit Login und Statusseite immer benutzbar bleiben.
 */
(() => {
  'use strict';

  const themeStorageKey = 'colo_manager_theme';
  const storedTheme = (() => { try { return localStorage.getItem(themeStorageKey); } catch { return null; } })();
  let activeTheme = ['light', 'dark'].includes(storedTheme)
    ? storedTheme
    : (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.dataset.theme = activeTheme;

  const defaults = { companyName: 'COLO MANAGER', primaryColor: '#0667F9', logoUrl: null, hasLogo: false, heroVideoType: null, heroVideoUrl: null, heroYoutubeVideoId: null };
  let current = defaults;

  function installThemeStyles() {
    if (document.getElementById('colo-theme-styles')) return;
    const style = document.createElement('style');
    style.id = 'colo-theme-styles';
    style.textContent = `
      html { color-scheme:light; }
      html[data-theme="dark"] { color-scheme:dark; }
      body, header, footer, main, section, article, aside, dialog, .field, input, select, textarea { transition:background-color .2s ease,border-color .2s ease,color .2s ease; }
      .colo-theme-toggle { display:inline-grid;height:40px;width:40px;flex:0 0 auto;place-items:center;border:1px solid #dbe3ef;border-radius:999px;background:#fff;color:#001966;box-shadow:0 6px 18px rgba(0,25,102,.06);font-size:17px;line-height:1;cursor:pointer; }
      .colo-theme-toggle:hover { border-color:var(--colo-primary,#0667F9);color:var(--colo-primary,#0667F9); }
      .colo-theme-toggle--floating { position:fixed;right:18px;top:18px;z-index:100; }
      html[data-theme="dark"] body { background-color:#07111F !important;color:#DCE7F7 !important; }
      html[data-theme="dark"] .bg-white,html[data-theme="dark"] [class~="bg-white/90"],html[data-theme="dark"] [class~="bg-white/95"] { background-color:#0F1B2D !important; }
      html[data-theme="dark"] .bg-mist,html[data-theme="dark"] .bg-slate-50,html[data-theme="dark"] .bg-slate-100 { background-color:#091525 !important; }
      html[data-theme="dark"] .bg-blue-50,html[data-theme="dark"] .bg-indigo-50,html[data-theme="dark"] .bg-cyan-50,html[data-theme="dark"] .bg-violet-50 { background-color:#102A46 !important; }
      html[data-theme="dark"] .bg-emerald-50 { background-color:#0D2B25 !important; }
      html[data-theme="dark"] .bg-amber-50 { background-color:#332711 !important; }
      html[data-theme="dark"] .bg-red-50 { background-color:#351820 !important; }
      html[data-theme="dark"] .text-ink,html[data-theme="dark"] .text-text { color:#F4F7FC !important; }
      html[data-theme="dark"] .text-slate-600 { color:#C1CDE0 !important; }
      html[data-theme="dark"] .text-slate-500 { color:#AAB8CE !important; }
      html[data-theme="dark"] .text-slate-400 { color:#8495AE !important; }
      html[data-theme="dark"] .label { color:#DCE7F7 !important; }
      html[data-theme="dark"] .border-slate-100,html[data-theme="dark"] .border-slate-200,html[data-theme="dark"] .border-slate-300 { border-color:#26364D !important; }
      html[data-theme="dark"] .divide-slate-100 > :not([hidden]) ~ :not([hidden]) { border-color:#26364D !important; }
      html[data-theme="dark"] body > header,html[data-theme="dark"] .min-h-screen > header { background-color:rgba(9,21,37,.92) !important;border-color:#26364D !important; }
      html[data-theme="dark"] footer { background-color:#0A1627 !important;border-color:#26364D !important; }
      html[data-theme="dark"] .field,html[data-theme="dark"] input:not([type="color"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]),html[data-theme="dark"] select,html[data-theme="dark"] textarea { background-color:#0A1627 !important;border-color:#304159 !important;color:#EEF4FC !important; }
      html[data-theme="dark"] input::placeholder,html[data-theme="dark"] textarea::placeholder { color:#71839D !important; }
      html[data-theme="dark"] select option { background:#0A1627;color:#EEF4FC; }
      html[data-theme="dark"] .shadow-card,html[data-theme="dark"] .shadow-soft { box-shadow:0 16px 42px rgba(0,0,0,.28) !important; }
      html[data-theme="dark"] .colo-theme-toggle { background:#132238;border-color:#304159;color:#F8C55A;box-shadow:0 8px 24px rgba(0,0,0,.24); }
      html[data-theme="dark"] [class~="hover:bg-slate-50"]:hover,html[data-theme="dark"] [class~="hover:bg-slate-100"]:hover { background-color:#17263B !important; }
      @media (prefers-reduced-motion:reduce) { body,header,footer,main,section,article,aside,dialog,.field,input,select,textarea { transition:none !important; } }
    `;
    document.head.appendChild(style);
  }

  function updateThemeButton() {
    const button = document.getElementById('coloThemeToggle');
    if (!button) return;
    const dark = activeTheme === 'dark';
    button.innerHTML = `<span aria-hidden="true">${dark ? '☀' : '☾'}</span>`;
    button.setAttribute('aria-label', dark ? 'Hellen Modus aktivieren' : 'Dark Mode aktivieren');
    button.setAttribute('title', dark ? 'Heller Modus' : 'Dark Mode');
    button.setAttribute('aria-pressed', String(dark));
  }

  function setTheme(theme, persist = true) {
    activeTheme = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.theme = activeTheme;
    if (persist) { try { localStorage.setItem(themeStorageKey, activeTheme); } catch {} }
    updateThemeButton();
    document.dispatchEvent(new CustomEvent('colo:theme-changed', { detail:{ theme:activeTheme } }));
    return activeTheme;
  }

  function mountThemeButton() {
    if (document.getElementById('coloThemeToggle')) return;
    const button = document.createElement('button');
    button.id = 'coloThemeToggle';
    button.type = 'button';
    button.className = 'colo-theme-toggle';
    button.addEventListener('click', () => setTheme(activeTheme === 'dark' ? 'light' : 'dark'));

    const header = document.querySelector('header');
    const nearbyLink = header?.querySelector('a[href="/konto"],a[href="konto.html"],a[href="login.html"],a[href="/login"]');
    const host = nearbyLink?.parentElement;
    if (host) host.insertBefore(button, nearbyLink);
    else {
      button.classList.add('colo-theme-toggle--floating');
      document.body.appendChild(button);
    }
    updateThemeButton();
  }

  installThemeStyles();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountThemeButton, { once:true });
  else mountThemeButton();
  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', event => {
    if (!storedTheme) setTheme(event.matches ? 'dark' : 'light', false);
  });

  function normalize(data = {}) {
    const companyName = String(data.companyName || defaults.companyName).trim().slice(0, 80) || defaults.companyName;
    const primaryColor = /^#[0-9A-F]{6}$/i.test(String(data.primaryColor || ''))
      ? String(data.primaryColor).toUpperCase()
      : defaults.primaryColor;
    const heroVideoType = ['youtube', 'direct'].includes(data.heroVideoType) ? data.heroVideoType : null;
    const heroYoutubeVideoId = /^[A-Za-z0-9_-]{11}$/.test(String(data.heroYoutubeVideoId || '')) ? String(data.heroYoutubeVideoId) : null;
    const heroVideoUrl = /^https?:\/\//i.test(String(data.heroVideoUrl || '')) ? String(data.heroVideoUrl) : null;
    const content = data.content && typeof data.content === 'object' ? data.content : {};
    return { ...defaults, ...data, companyName, primaryColor, hasLogo: Boolean(data.hasLogo && data.logoUrl), heroVideoType, heroVideoUrl, heroYoutubeVideoId, content };
  }

  function contentValue(path) {
    return String(path || '').split('.').reduce((value, key) => value && typeof value === 'object' ? value[key] : undefined, current.content);
  }

  /**
   * Setzt ausschließlich Textinhalte in explizit markierten Elementen. Damit
   * bleiben Links, Icons und dynamische Kundendaten unverändert. FAQ-Inhalte
   * werden als DOM-Knoten erstellt und niemals als ungeprüftes HTML eingefügt.
   */
  function applyContent() {
    document.querySelectorAll('[data-branding-text]').forEach(element => {
      const value = contentValue(element.dataset.brandingText);
      if (typeof value === 'string' && value.trim() !== '') element.textContent = value;
    });

    document.querySelectorAll('[data-branding-faq-list]').forEach(container => {
      const faqs = contentValue('landing.faqs');
      if (!Array.isArray(faqs)) return;
      container.replaceChildren(...faqs.slice(0, 20).map(item => {
        const details = document.createElement('details');
        details.className = 'group py-5';
        const summary = document.createElement('summary');
        summary.className = 'flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-ink';
        const question = document.createElement('span');
        question.textContent = String(item?.question || '');
        const icon = document.createElement('i');
        icon.dataset.lucide = 'plus';
        icon.className = 'h-5 w-5 shrink-0 text-action transition group-open:rotate-45';
        const answer = document.createElement('p');
        answer.className = 'mt-3 max-w-3xl text-sm leading-relaxed text-slate-500';
        answer.textContent = String(item?.answer || '');
        summary.append(question, icon);
        details.append(summary, answer);
        return details;
      }));
      container.closest('section')?.classList.toggle('hidden', faqs.length === 0);
    });
    window.lucide?.createIcons?.();
  }

  function installColorOverrides() {
    if (document.getElementById('colo-branding-styles')) return;
    const style = document.createElement('style');
    style.id = 'colo-branding-styles';
    style.textContent = `
      :root { --colo-primary: #0667F9; --colo-primary-hover: #055AD9; --colo-primary-soft: #EAF2FF; }
      .bg-action { background-color: var(--colo-primary) !important; }
      .text-action { color: var(--colo-primary) !important; }
      .border-action { border-color: var(--colo-primary) !important; }
      .ring-action { --tw-ring-color: var(--colo-primary) !important; }
      .focus\\:border-action:focus, .focus-within\\:border-action:focus-within { border-color: var(--colo-primary) !important; }
      .focus\\:ring-action:focus, .focus-within\\:ring-action:focus-within { --tw-ring-color: color-mix(in srgb, var(--colo-primary) 22%, transparent) !important; }
      .hover\\:bg-action:hover, .hover\\:bg-blue-500:hover { background-color: var(--colo-primary-hover) !important; }
      .hover\\:text-action:hover { color: var(--colo-primary) !important; }
      .hover\\:border-action:hover { border-color: var(--colo-primary) !important; }
      .nav-item.active::before, .nav-button.active::before { background: var(--colo-primary) !important; }
      [data-brand-lockup] > img { display:block; max-width:100%; object-fit:contain; object-position:left center; }
    `;
    document.head.appendChild(style);
  }

  function shade(hex, amount) {
    const value = hex.replace('#', '');
    const channels = [0, 2, 4].map(offset => parseInt(value.slice(offset, offset + 2), 16));
    return `#${channels.map(channel => Math.max(0, Math.min(255, Math.round(channel * (1 + amount)))).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
  }

  function apply(data) {
    const previousCompanyName = current.companyName;
    current = normalize(data);
    installColorOverrides();
    document.documentElement.style.setProperty('--colo-primary', current.primaryColor);
    document.documentElement.style.setProperty('--colo-primary-hover', shade(current.primaryColor, -0.14));
    document.documentElement.style.setProperty('--colo-primary-soft', `${current.primaryColor}18`);

    document.querySelectorAll('[data-brand-name]').forEach(element => {
      element.textContent = current.companyName;
    });
    applyContent();

    // Auch Seitentitel, Fußzeilen und beschreibende Standardtexte übernehmen
    // den konfigurierten Namen, ohne Nutzdaten oder Formularwerte anzufassen.
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);
    textNodes.forEach(node => {
      if (['SCRIPT','STYLE','TEXTAREA','OPTION'].includes(node.parentElement?.tagName || '')) return;
      let value = node.nodeValue || '';
      [defaults.companyName, previousCompanyName].filter(Boolean).forEach(name => {
        if (name !== current.companyName) value = value.replaceAll(name, current.companyName);
      });
      node.nodeValue = value;
    });

    document.querySelectorAll('[data-brand-lockup]').forEach(element => {
      if (!element.dataset.brandFallback) element.dataset.brandFallback = element.innerHTML;
      if (current.hasLogo) {
        const maxHeight = element.dataset.brandLogoHeight || '48';
        element.innerHTML = `<img src="${String(current.logoUrl).replace(/"/g, '&quot;')}" alt="${current.companyName.replace(/[&<>"']/g, '')}" style="height:${maxHeight}px;max-width:210px">`;
        element.setAttribute('aria-label', current.companyName);
      } else if (current.companyName !== defaults.companyName) {
        const safeName = current.companyName.replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[character]));
        element.innerHTML = `<strong style="font-size:1.15rem;font-weight:900;letter-spacing:-.035em;color:inherit;line-height:1.1">${safeName}</strong>`;
        element.setAttribute('aria-label', current.companyName);
      } else if (element.dataset.brandFallback) {
        element.innerHTML = element.dataset.brandFallback;
      }
    });

    if (document.title.includes(defaults.companyName)) {
      document.title = document.title.replaceAll(defaults.companyName, current.companyName);
    }
    document.dispatchEvent(new CustomEvent('colo:branding-applied', { detail: current }));
    return current;
  }

  const ready = fetch('/api/v1/public/branding', { headers: { Accept: 'application/json' }, cache: 'no-store' })
    .then(response => response.ok ? response.json() : Promise.reject(new Error('Branding nicht verfügbar')))
    .then(payload => payload?.data || defaults)
    .catch(() => defaults)
    .then(data => {
      if (document.readyState === 'loading') {
        return new Promise(resolve => document.addEventListener('DOMContentLoaded', () => resolve(apply(data)), { once: true }));
      }
      return apply(data);
    });

  window.ColoBranding = { ready, apply, get current() { return current; } };
  window.ColoTheme = { set:setTheme, get current() { return activeTheme; } };
})();
