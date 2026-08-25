(() => {
  const KEY = 'faveside-mvp-v1';
  const rawSet = Storage.prototype.setItem;
  let signedIn = false;
  let syncing = false;

  async function api(action, options = {}) {
    const response = await fetch('api/account.php?action=' + action, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Account sync failed.');
    return data;
  }

  function pushState(value) {
    if (!signedIn || syncing) return;
    let state;
    try { state = JSON.parse(value); } catch { return; }
    fetch('api/account.php?action=state', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ state }),
      keepalive: true
    }).catch(() => {});
  }

  Storage.prototype.setItem = function(key, value) {
    rawSet.call(this, key, value);
    if (this === localStorage && key === KEY) pushState(value);
  };

  async function initialSync() {
    try {
      const me = await api('me');
      signedIn = !!me.user;
      window.FavesideAccount = me.user || null;
      if (!signedIn) return;

      const remote = await api('state');
      const localRaw = localStorage.getItem(KEY);
      const local = localRaw ? JSON.parse(localRaw) : null;
      const hasRemote = remote.state && Object.keys(remote.state).length > 0;

      if (hasRemote) {
        const remoteRaw = JSON.stringify(remote.state);
        if (remoteRaw !== localRaw && sessionStorage.getItem('faveside-synced') !== remote.updated_at) {
          syncing = true;
          rawSet.call(localStorage, KEY, remoteRaw);
          sessionStorage.setItem('faveside-synced', remote.updated_at || '1');
          location.reload();
          return;
        }
      } else if (local) {
        await api('state', { method: 'POST', body: JSON.stringify({ state: local }) });
      }
    } catch (error) {
      console.warn('Faveside account sync unavailable:', error.message);
    }
  }

  function addAccountUI() {
    const actions = document.querySelector('.top-actions');
    if (!actions || document.getElementById('accountLink')) return;
    const link = document.createElement('a');
    link.id = 'accountLink';
    link.className = 'btn';
    link.href = 'account.html';
    link.textContent = signedIn ? 'Account ✓' : 'Sign in';
    actions.insertBefore(link, actions.firstChild);

    const foot = document.querySelector('.sidebar-foot');
    if (foot) {
      foot.innerHTML = signedIn
        ? '<strong>Synced to your account.</strong>Your creator choices and preferences can follow you across devices.'
        : '<strong>Your data stays yours.</strong>Sign in to sync this Faveside across devices.';
    }
  }

  initialSync().finally(() => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addAccountUI);
    else addAccountUI();
  });
})();
