(() => {
  'use strict';

  const SESSION_KEY = 'colo_manager_session';
  const ACTIVITY_KEY = 'colo_manager_last_activity_at';
  const DEFAULT_IDLE_SECONDS = 300;
  let session;
  let idleSeconds;
  let lastActivity;
  let lastStoredActivity = 0;
  let logoutStarted = false;
  let warningVisible = false;

  try {
    session = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
  } catch {
    session = null;
  }
  if (!session?.accessToken) return;

  idleSeconds = Number(session.idleTimeoutSeconds || DEFAULT_IDLE_SECONDS);
  lastActivity = Number(localStorage.getItem(ACTIVITY_KEY) || Date.now());
  if (!Number.isFinite(lastActivity) || lastActivity <= 0) lastActivity = Date.now();
  localStorage.setItem(ACTIVITY_KEY, String(lastActivity));

  const loginUrl = reason => `/login.html${reason === 'inactive' ? '?reason=inactive' : ''}`;

  async function logout(reason = 'manual') {
    if (logoutStarted) return;
    logoutStarted = true;
    // keepalive gibt der API auch bei einer sofortigen Weiterleitung Zeit, die
    // serverseitige Sitzung zu widerrufen.
    fetch('/api/v1/auth/logout', {
      method: 'POST',
      headers: { Authorization: `Bearer ${session.accessToken}`, Accept: 'application/json' },
      keepalive: true
    }).catch(() => {});
    localStorage.removeItem(SESSION_KEY);
    localStorage.removeItem(ACTIVITY_KEY);
    window.location.replace(loginUrl(reason));
  }

  // Eine bereits lokal abgelaufene Sitzung darf auch durch einen neu
  // geöffneten Tab nicht wiederbelebt werden. Ist sie noch gültig, zählt das
  // bewusste Öffnen beziehungsweise Neuladen der Seite als Aktivität.
  const absoluteExpiry = Date.parse(session.expiresAt || '');
  if ((Number.isFinite(absoluteExpiry) && absoluteExpiry <= Date.now()) || Date.now() - lastActivity >= idleSeconds * 1000) {
    logout('inactive');
    return;
  }

  function recordActivity() {
    if (logoutStarted) return;
    const now = Date.now();
    lastActivity = now;
    // Mausbewegungen können sehr häufig eintreffen; für die tabübergreifende
    // Synchronisierung genügt höchstens ein Storage-Update pro Sekunde.
    if (now - lastStoredActivity >= 1000) {
      localStorage.setItem(ACTIVITY_KEY, String(now));
      lastStoredActivity = now;
    }
    hideWarning();
  }

  recordActivity();

  function createUi() {
    if (document.getElementById('coloSessionTimer')) return;
    const timer = document.createElement('a');
    timer.id = 'coloSessionTimer';
    timer.href = '/konto';
    timer.setAttribute('aria-label', 'Verbleibende Sitzungszeit und Kontoeinstellungen öffnen');
    timer.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:9998;display:flex;align-items:center;gap:9px;padding:10px 14px;border:1px solid #dbe3f0;border-radius:999px;background:rgba(255,255,255,.96);box-shadow:0 12px 34px rgba(0,25,102,.16);color:#001966;font:700 12px/1 Inter,ui-sans-serif,system-ui,sans-serif;text-decoration:none;backdrop-filter:blur(12px)';
    timer.innerHTML = '<span aria-hidden="true" style="width:8px;height:8px;border-radius:999px;background:#10b981"></span><span>Sitzung</span><strong data-session-time style="font-variant-numeric:tabular-nums">05:00</strong>';
    document.body.appendChild(timer);

    const warning = document.createElement('div');
    warning.id = 'coloSessionWarning';
    warning.style.cssText = 'position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,15,61,.58);backdrop-filter:blur(5px)';
    warning.innerHTML = '<section role="alertdialog" aria-modal="true" aria-labelledby="sessionWarningTitle" style="width:min(100%,430px);border-radius:28px;background:#fff;padding:30px;box-shadow:0 30px 90px rgba(0,15,61,.28);font-family:Inter,ui-sans-serif,system-ui,sans-serif"><div style="display:grid;width:48px;height:48px;place-items:center;border-radius:18px;background:#fff7ed;color:#c2410c;font-size:22px">⌛</div><h2 id="sessionWarningTitle" style="margin:20px 0 0;color:#001966;font-size:23px;line-height:1.2">Ihre Sitzung läuft gleich ab</h2><p style="margin:10px 0 0;color:#64748b;font-size:14px;line-height:1.6">Aus Sicherheitsgründen werden Sie nach fünf Minuten Inaktivität automatisch abgemeldet.</p><p style="margin:18px 0 0;color:#001966;font-size:14px;font-weight:700">Automatische Abmeldung in <strong data-warning-time style="font-variant-numeric:tabular-nums;color:#c2410c">01:00</strong></p><button type="button" data-continue-session style="width:100%;margin-top:22px;border:0;border-radius:999px;background:#0667f9;padding:14px 20px;color:#fff;font-size:14px;font-weight:700;cursor:pointer">Sitzung fortsetzen</button></section>';
    document.body.appendChild(warning);
    warning.querySelector('[data-continue-session]').addEventListener('click', recordActivity);
  }

  function hideWarning() {
    const warning = document.getElementById('coloSessionWarning');
    if (warning) warning.style.display = 'none';
    warningVisible = false;
  }

  function renderTimer() {
    if (logoutStarted) return;
    const stored = Number(localStorage.getItem(ACTIVITY_KEY));
    if (Number.isFinite(stored) && stored > lastActivity) lastActivity = stored;
    const remaining = Math.max(0, idleSeconds - Math.floor((Date.now() - lastActivity) / 1000));
    if (remaining <= 0) {
      logout('inactive');
      return;
    }
    const formatted = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
    const timer = document.getElementById('coloSessionTimer');
    if (timer) {
      timer.querySelector('[data-session-time]').textContent = formatted;
      const dot = timer.firstElementChild;
      dot.style.background = remaining <= 60 ? '#f59e0b' : '#10b981';
      timer.style.borderColor = remaining <= 60 ? '#fed7aa' : '#dbe3f0';
    }
    const warning = document.getElementById('coloSessionWarning');
    if (remaining <= 60 && warning) {
      warning.querySelector('[data-warning-time]').textContent = formatted;
      if (!warningVisible) {
        warning.style.display = 'flex';
        warningVisible = true;
      }
    }
  }

  async function heartbeat() {
    if (logoutStarted || document.visibilityState !== 'visible') return;
    // Nur eine tatsächlich kürzlich aktive Browser-Sitzung wird serverseitig
    // verlängert; ein vergessenes Hintergrundfenster hält sich nicht selbst am Leben.
    if (Date.now() - lastActivity > 70_000) return;
    try {
      const response = await fetch('/api/v1/auth/session/heartbeat', {
        method: 'POST',
        headers: { Authorization: `Bearer ${session.accessToken}`, Accept: 'application/json' }
      });
      if (response.status === 401) logout('inactive');
    } catch {
      // Ein kurzzeitiger Netzfehler löst keine lokale Sofortabmeldung aus; der
      // verbindliche Countdown läuft unverändert weiter.
    }
  }

  ['pointerdown', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(type => {
    document.addEventListener(type, recordActivity, { passive: true, capture: true });
  });
  document.addEventListener('click', event => {
    if (event.target.closest?.('#logoutButton,#logout,[data-secure-logout]')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      logout('manual');
    }
  }, true);
  window.addEventListener('storage', event => {
    if (event.key === ACTIVITY_KEY && event.newValue) {
      lastActivity = Math.max(lastActivity, Number(event.newValue) || 0);
      hideWarning();
    }
    if (event.key === SESSION_KEY && event.newValue === null) logoutStarted || logout('manual');
  });
  document.addEventListener('visibilitychange', renderTimer);
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', createUi) : createUi();
  window.ColoSession = { logout, recordActivity, idleTimeoutSeconds: idleSeconds };
  renderTimer();
  setInterval(renderTimer, 1000);
  setInterval(heartbeat, 60_000);
  // Neue Seiten bestätigen die Sitzung sofort. Dadurch entscheidet nicht jede
  // Unterseite mit leicht abweichender Logik selbst über einen Redirect.
  heartbeat();
})();
