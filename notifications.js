(() => {
  const KEY = 'faveside-notifications-v1';

  function supported() {
    return 'Notification' in window;
  }

  function currentState() {
    if (!supported()) return { supported: false, permission: 'unsupported', enabled: false };
    return {
      supported: true,
      permission: Notification.permission,
      enabled: Notification.permission === 'granted'
    };
  }

  async function requestPermission() {
    if (!supported()) return currentState();
    let permission = Notification.permission;
    if (permission === 'default') permission = await Notification.requestPermission();
    const state = { supported: true, permission, enabled: permission === 'granted' };
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch {}
    window.dispatchEvent(new CustomEvent('faveside:notifications', { detail: state }));
    return state;
  }

  function showLocalUpdate(title, options = {}) {
    const state = currentState();
    if (!state.enabled || document.visibilityState === 'visible') return false;
    try {
      new Notification(title, {
        icon: 'favicon.svg',
        badge: 'favicon.svg',
        tag: options.tag || 'faveside-update',
        body: options.body || 'There is something new on your Faveside.',
        data: options.data || {}
      });
      return true;
    } catch {
      return false;
    }
  }

  window.FavesideNotifications = { currentState, requestPermission, showLocalUpdate };
})();
