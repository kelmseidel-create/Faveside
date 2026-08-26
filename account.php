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
.premium-box h3{margin:0 0 7px;font-size:1.05rem}.premium-box p{margin:0;color:#aaa4b1;font-size:.84rem;line-height:1.5}.plan-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}.plan-button{border:1px solid #ffffff20;border-radius:16px;padding:13px 10px;background:#ffffff09;color:#fff;font-weight:950;cursor:pointer}.plan-button strong{display:block;font-size:1rem}.plan-button span{display:block;margin-top:3px;color:#aaa4b1;font-size:.72rem;font-weight:700}.plan-button.featured{border-color:#ff65bd66;background:linear-gradient(135deg,#ff3bac1f,#895cff25)}.plan-button:disabled{opacity:.45;cursor:not-allowed}.promo-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:13px}.promo-row input{min-width:0;border:1px solid #302e3a;border-radius:999px;padding:11px 14px;background:#08080d;color:#fff;outline:none}.promo-row button{border:0;border-radius:999px;padding:11px 15px;background:linear-gradient(110deg,#fff,#ff9ed9 50%,#a887ff);color:#08080b;font-weight:950;cursor:pointer}.promo-status{min-height:19px;margin-top:9px;color:#ff9fcf;font-size:.78rem}.promo-status.ok{color:#72e9b3}.plan-note{margin-top:10px;color:#77717e;font-size:.72rem;line-height:1.4}@media(max-width:520px){.plan-grid{grid-template-columns:1fr}}
</style>
<script>
(() => {
  const signed = document.getElementById('signed');
  if (!signed) return;
  const box = document.createElement('div');
  box.className = 'premium-box';
  box.innerHTML = `<h3>Faveside+ Premium</h3><p>Unlock unlimited creators plus flagship Parent Controls. Choose monthly or save with annual billing.</p><div class="plan-grid"><button class="plan-button featured" id="monthlyPlan" type="button" disabled><strong>$4.99/month</strong><span>Monthly Premium</span></button><button class="plan-button" id="annualPlan" type="button" disabled><strong>$39.99/year</strong><span>Save about 33%</span></button></div><div class="promo-status" id="squareStatus" role="status"></div><div class="promo-row"><input id="promoCode" autocomplete="off" maxlength="80" placeholder="Family & Friends code"><button id="redeemPromo" type="button">Redeem</button></div><div class="promo-status" id="promoStatus" role="status"></div><div class="plan-note">Secure paid checkout is handled by Square. Family & Friends access can still be activated with a complimentary code.</div>`;
  signed.insertBefore(box, signed.querySelector('.actions'));

  const entitlement = document.getElementById('entitlement');
  const promoStatus = document.getElementById('promoStatus');
  const squareStatus = document.getElementById('squareStatus');
  const input = document.getElementById('promoCode');
  const redeem = document.getElementById('redeemPromo');
  const monthly = document.getElementById('monthlyPlan');
  const annual = document.getElementById('annualPlan');

  async function jsonApi(url, options = {}) {
    const r = await fetch(url, {credentials:'same-origin', headers:{'Content-Type':'application/json'}, ...options});
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Unable to complete that request.');
    return d;
  }
  async function billing(action, payload) {
    return jsonApi('api/billing.php?action=' + action, payload ? {method:'POST', body:JSON.stringify(payload)} : {});
  }
  async function square(action, payload) {
    return jsonApi('api/square.php?action=' + action, payload ? {method:'POST', body:JSON.stringify(payload)} : {});
  }

  redeem.onclick = async () => {
    const code = input.value.trim();
    if (!code) { promoStatus.textContent = 'Enter your access code.'; return; }
    redeem.disabled = true; promoStatus.classList.remove('ok'); promoStatus.textContent = '';
    try {
      const d = await billing('redeem', {code});
      promoStatus.textContent = d.message || 'Premium access activated.'; promoStatus.classList.add('ok');
      if (entitlement) entitlement.textContent = (d.entitlement || 'complimentary').toUpperCase() + ' ACCOUNT';
      input.value = '';
    } catch (e) { promoStatus.textContent = e.message; }
    finally { redeem.disabled = false; }
  };

  async function startCheckout(plan, button) {
    const old = button.innerHTML;
    button.disabled = true;
    squareStatus.classList.remove('ok');
    squareStatus.textContent = 'Opening secure Square checkout…';
    try {
      const d = await square('checkout', {plan});
      if (!d.url) throw new Error('Square did not return a checkout link.');
      location.href = d.url;
    } catch (e) {
      squareStatus.textContent = e.message;
      button.disabled = false;
      button.innerHTML = old;
    }
  }
  monthly.onclick = () => startCheckout('monthly', monthly);
  annual.onclick = () => startCheckout('annual', annual);

  square('status').then(d => {
    monthly.disabled = !d.monthly;
    annual.disabled = !d.annual;
    if (d.configured) {
      squareStatus.textContent = 'Secure Square checkout is connected.';
      squareStatus.classList.add('ok');
    } else {
      squareStatus.textContent = 'Paid checkout is being connected. Your free account and access codes work now.';
    }
  }).catch(() => { squareStatus.textContent = 'Paid checkout is temporarily unavailable.'; });

  if (new URLSearchParams(location.search).get('checkout') === 'success') {
    squareStatus.textContent = 'Payment received. Square is syncing your Premium access.';
    squareStatus.classList.add('ok');
  }
})();
</script>
HTML;

$html = str_replace('</body>', $enhancement . "\n</body>", $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
