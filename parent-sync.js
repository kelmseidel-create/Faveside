(() => {
  async function account() {
    try {
      const r = await fetch('api/account.php?action=me', { credentials: 'same-origin' });
      const d = await r.json();
      return d.user || null;
    } catch { return null; }
  }
  function wire() {
    document.querySelectorAll('[data-view="parents"]').forEach(button => {
      button.addEventListener('click', async event => {
        event.preventDefault();
        event.stopImmediatePropagation();
        const user = await account();
        if (!user) { location.href = 'account.html'; return; }
        location.href = 'family.html';
      }, true);
    });
    const parentSection = document.getElementById('parents');
    if (parentSection) {
      const notice = parentSection.querySelector('.notice');
      if (notice) notice.textContent = 'Signed-in Faveside+ accounts use server-enforced Parent Controls. Open Parent Controls from the navigation to manage protected child profiles.';
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire);
  else wire();
})();
