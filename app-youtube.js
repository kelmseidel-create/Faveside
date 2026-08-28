(() => {
  const LAST_SEEN_KEY = 'faveside-youtube-last-seen-v1';
  let configured = false;
  let activityAvailable = true;
  let installed = false;
  let debounce;

  async function yt(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch('api/youtube.php?' + qs.toString(), { credentials: 'same-origin' });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) {
      const err = new Error(d.error || 'YouTube request failed.');
      err.setupRequired = !!d.setup_required;
      throw err;
    }
    return d;
  }

  function fmt(n) {
    if (n == null) return '';
    return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n);
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function readLastSeen() {
    try { return JSON.parse(localStorage.getItem(LAST_SEEN_KEY) || '{}'); } catch { return {}; }
  }

  function writeLastSeen(value) {
    try { localStorage.setItem(LAST_SEEN_KEY, JSON.stringify(value)); } catch {}
  }

  async function checkStatus() {
    try {
      const d = await yt('status');
      configured = !!d.configured;
      activityAvailable = d.activity_available !== false;
    } catch {
      configured = false;
      activityAvailable = true;
    }
  }

  async function renderLiveResults(query) {
    const box = document.getElementById('creatorResults');
    if (!box || !configured || query.trim().length < 2) return;
    box.innerHTML = '<div class="notice">Searching YouTube…</div>';
    try {
      const d = await yt('search', { q: query.trim() });
      const results = d.results || [];
      if (!results.length) {
        box.innerHTML = '<div class="notice">No YouTube channels matched that search.</div>';
        return;
      }
      box.innerHTML = results.map(c => `<div class="search-result"><div class="creator-avatar">${c.image ? `<img src="${esc(c.image)}" alt="${esc(c.name)} profile photo" referrerpolicy="no-referrer">` : esc((c.name||'?').slice(0,2).toUpperCase())}</div><div><strong>${esc(c.name)} <span class="platform">YouTube</span></strong><small>${esc(c.handle || c.youtubeChannelId)}${c.subscriberCount != null ? ' · ' + esc(fmt(c.subscriberCount)) + ' subscribers' : ''}</small></div><button class="btn primary" data-yt-id="${esc(c.youtubeChannelId)}">Add</button></div>`).join('');
      box.querySelectorAll('[data-yt-id]').forEach(btn => {
        btn.onclick = () => {
          const creator = results.find(x => x.youtubeChannelId === btn.dataset.ytId);
          if (!creator || typeof window.addCreator !== 'function') return;
          window.addCreator({
            id: 'youtube-' + creator.youtubeChannelId,
            name: creator.name,
            handle: creator.handle || '',
            category: 'YouTube',
            platform: 'YouTube',
            image: creator.image || '',
            youtubeChannelId: creator.youtubeChannelId,
            url: creator.url || '',
            activity: 70
          });
        };
      });
    } catch (e) {
      box.innerHTML = `<div class="notice">${esc(e.message)}</div>`;
    }
  }

  function enhanceSearch() {
    if (installed) return;
    const input = document.getElementById('creatorSearch');
    if (!input) return;
    installed = true;

    input.addEventListener('input', () => {
      clearTimeout(debounce);
      if (!configured) return;
      debounce = setTimeout(() => renderLiveResults(input.value), 350);
    }, true);

    const helper = document.querySelector('.search-help');
    if (helper) {
      helper.textContent = configured
        ? 'Live YouTube search and followed-channel updates are connected. TikTok integration is next.'
        : 'Followed YouTube-channel updates are connected. Live channel search will turn on when the server API key is installed.';
    }
  }

  function installNotificationControl() {
    if (!('Notification' in window) || document.getElementById('favesideNotifyButton')) return;
    const actions = document.querySelector('.top-actions');
    if (!actions) return;

    const button = document.createElement('button');
    button.id = 'favesideNotifyButton';
    button.className = 'btn';
    button.type = 'button';

    const refresh = () => {
      button.textContent = Notification.permission === 'granted' ? 'Alerts ✓' : 'Turn on alerts';
      button.disabled = Notification.permission === 'denied';
      button.title = Notification.permission === 'denied' ? 'Notifications are blocked in your browser settings.' : 'Get alerts for newly detected creator videos.';
    };

    button.addEventListener('click', async () => {
      try { await Notification.requestPermission(); } catch {}
      refresh();
    });
    refresh();
    actions.insertBefore(button, actions.firstChild);
  }

  function maybeNotify(creator, latest, lastSeen) {
    const channelId = creator.youtubeChannelId;
    if (!channelId || !latest?.videoId) return;
    const previous = lastSeen[channelId];
    lastSeen[channelId] = latest.videoId;
    if (!previous || previous === latest.videoId || !('Notification' in window) || Notification.permission !== 'granted') return;

    try {
      const n = new Notification(`${creator.name} posted on YouTube`, {
        body: latest.title || 'There is a new video on your Faveside.',
        icon: creator.image || latest.thumbnail || undefined,
        tag: `faveside-${channelId}-${latest.videoId}`
      });
      n.onclick = () => {
        window.focus();
        if (latest.url) window.open(latest.url, '_blank', 'noopener,noreferrer');
        n.close();
      };
    } catch {}
  }

  async function enrichCatchup() {
    if (!activityAvailable) return;
    let state;
    try { state = JSON.parse(localStorage.getItem('faveside-mvp-v1') || '{}'); } catch { return; }
    const creators = (state.creators || []).filter(c => c.youtubeChannelId).slice(0, 12);
    if (!creators.length) return;

    const list = document.getElementById('catchupList');
    if (!list) return;
    const updates = [];
    const lastSeen = readLastSeen();

    for (const creator of creators) {
      try {
        const d = await yt('activity', { channel_id: creator.youtubeChannelId });
        const latest = (d.videos || [])[0];
        if (latest) {
          updates.push({ creator, latest });
          maybeNotify(creator, latest, lastSeen);
        }
      } catch {}
    }
    writeLastSeen(lastSeen);

    if (!updates.length) return;
    updates.sort((a, b) => new Date(b.latest.publishedAt || 0) - new Date(a.latest.publishedAt || 0));
    list.innerHTML = updates.map(({creator, latest}) => `<article class="card update"><span class="dot"></span><div><h3>${esc(creator.name)} posted on YouTube</h3><p><a href="${esc(latest.url)}" target="_blank" rel="noopener noreferrer">${esc(latest.title)}</a> · ${latest.publishedAt ? esc(new Date(latest.publishedAt).toLocaleDateString()) : 'recently'}</p></div></article>`).join('');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    await checkStatus();
    enhanceSearch();
    installNotificationControl();
    enrichCatchup();
  });
})();
