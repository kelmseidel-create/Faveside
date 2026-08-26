<?php
declare(strict_types=1);

$path = __DIR__ . '/app.html';
$html = @file_get_contents($path);
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Faveside is temporarily unavailable.';
    exit;
}

$enhancement = <<<'HTML'
<style>
.faveside-notify{position:fixed;right:18px;bottom:84px;z-index:45;max-width:330px;padding:14px;border:1px solid #ffffff1b;border-radius:18px;background:#111119ee;box-shadow:0 24px 70px #0008;backdrop-filter:blur(18px)}
.faveside-notify strong{display:block;margin-bottom:4px}.faveside-notify p{margin:0 0 10px;color:#aaa4b1;font-size:.8rem;line-height:1.45}.faveside-notify button{border:0;border-radius:999px;padding:10px 14px;background:linear-gradient(110deg,#fff,#ff9ed9 50%,#a887ff);color:#08080b;font-weight:900;cursor:pointer}.faveside-notify .status{margin-top:8px;color:#aaa4b1;font-size:.74rem}.faveside-notify.done{display:none}@media(min-width:901px){.faveside-notify{bottom:22px}}
</style>
<script src="notifications.js"></script>
<script>
(() => {
  function mount() {
    if (!window.FavesideNotifications || document.getElementById('favesideNotifyPrompt')) return;
    const state = window.FavesideNotifications.currentState();
    if (!state.supported || state.permission === 'granted') return;
    const box = document.createElement('aside');
    box.id = 'favesideNotifyPrompt';
    box.className = 'faveside-notify';
    box.setAttribute('aria-live','polite');
    box.innerHTML = '<strong>Never miss an update</strong><p>Allow Faveside notifications for updates from creators you follow.</p><button type="button" id="favesideEnableNotifications">Enable notifications</button><div class="status" id="favesideNotifyStatus"></div>';
    document.body.appendChild(box);
    const button = document.getElementById('favesideEnableNotifications');
    const status = document.getElementById('favesideNotifyStatus');
    button.onclick = async () => {
      button.disabled = true;
      try {
        const result = await window.FavesideNotifications.requestPermission();
        if (result.permission === 'granted') {
          status.textContent = 'Notifications are on.';
          setTimeout(() => box.classList.add('done'), 700);
        } else if (result.permission === 'denied') {
          status.textContent = 'Notifications are blocked in your device/browser settings.';
        } else {
          status.textContent = 'Notifications were not enabled.';
        }
      } catch {
        status.textContent = 'Notifications could not be enabled on this device.';
      } finally { button.disabled = false; }
    };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
</script>
HTML;

$html = str_replace('</body>', $enhancement . "\n</body>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
