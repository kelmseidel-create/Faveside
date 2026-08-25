<?php
declare(strict_types=1);

$path = __DIR__ . '/account.html';
$html = @file_get_contents($path);
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Faveside accounts are temporarily unavailable.';
    exit;
}

$enhancement = <<<'HTML'
<style>
.premium-box{margin-top:18px;padding:18px;border:1px solid #ffffff18;border-radius:20px;background:linear-gradient(135deg,#ff3bac12,#895cff18)}
.premium-box h3{margin:0 0 7px;font-size:1.05rem}.premium-box p{margin:0;color:#aaa4b1;font-size:.84rem;line-height:1.5}
.promo-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:13px}.promo-row input{min-width:0;border:1px solid #302e3a;border-radius:999px;padding:11px 14px;background:#08080d;color:#fff;outline:none}.promo-row button{border:0;border-radius:999px;padding:11px 15px;background:linear-gradient(110deg,#fff,#ff9ed9 50%,#a887ff);color:#08080b;font-weight:950;cursor:pointer}.promo-status{min-height:19px;margin-top:9px;color:#ff9fcf;font-size:.78rem}.promo-status.ok{color:#72e9b3}.plan-note{margin-top:10px;color:#77717e;font-size:.72rem;line-height:1.4}
</style>
<script>
(() => {
  const signed = document.getElementById('signed');
  if (!signed) return;
  const box = document.createElement('div');
  box.className = 'premium-box';
  box.innerHTML = `<h3>Faveside+ access</h3><p>Premium unlocks the full Faveside experience, including Parent Controls. Have a Family & Friends code? Redeem it here.</p><div class="promo-row"><input id="promoCode" autocomplete="off" maxlength="80" placeholder="Enter access code"><button id="redeemPromo" type="button">Redeem</button></div><div class="promo-status" id="promoStatus" role="status"></div><div class="plan-note">Paid mobile subscriptions will be connected through the app stores before public launch.</div>`;
  signed.insertBefore(box, signed.querySelector('.actions'));

  const entitlement = document.getElementById('entitlement');
  const status = document.getElementById('promoStatus');
  const input = document.getElementById('promoCode');
  const button = document.getElementById('redeemPromo');
  async function billing(action, body) {
    const r = await fetch('api/billing.php?action=' + action, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: {'Content-Type':'application/json'}, body: body ? JSON.stringify(body) : undefined
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Unable to update access.');
    return d;
  }
  button.onclick = async () => {
    const code = input.value.trim();
    if (!code) { status.textContent = 'Enter your access code.'; return; }
    button.disabled = true; status.classList.remove('ok'); status.textContent = '';
    try {
      const d = await billing('redeem', {code});
      status.textContent = d.message || 'Premium access activated.'; status.classList.add('ok');
      if (entitlement) entitlement.textContent = (d.entitlement || 'complimentary').toUpperCase() + ' ACCOUNT';
      input.value = '';
    } catch (e) { status.textContent = e.message; }
    finally { button.disabled = false; }
  };
})();
</script>
HTML;

$html = str_replace('</body>', $enhancement . "\n</body>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
