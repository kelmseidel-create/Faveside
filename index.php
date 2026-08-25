<?php
$path = __DIR__ . '/index.html';
$html = @file_get_contents($path);

if ($html === false) {
    http_response_code(500);
    echo 'Faveside is temporarily unavailable.';
    exit;
}

$extraCss = <<<'CSS'

    .launch-form {
      width: min(680px, 100%);
      margin: 32px auto 0;
      display: flex;
      gap: 10px;
      padding: 8px;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 999px;
      background: rgba(5,5,9,0.64);
      box-shadow: 0 18px 60px rgba(0,0,0,0.28);
    }

    .launch-form input[type="email"] {
      min-width: 0;
      flex: 1;
      height: 52px;
      padding: 0 18px;
      border: 0;
      outline: 0;
      border-radius: 999px;
      color: white;
      background: transparent;
      font: inherit;
      font-size: 1rem;
    }

    .launch-form input[type="email"]::placeholder { color: #817c89; }

    .launch-form button {
      border: 0;
      cursor: pointer;
      white-space: nowrap;
    }

    .launch-form button:disabled {
      cursor: wait;
      opacity: 0.7;
    }

    .launch-message {
      min-height: 26px;
      margin: 14px auto 0 !important;
      font-size: 0.92rem !important;
      line-height: 1.45 !important;
    }

    .launch-message.success { color: #75f2ba; }
    .launch-message.error { color: #ff9ccf; }

    .launch-note {
      margin-top: 10px !important;
      color: #716c78 !important;
      font-size: 0.78rem !important;
    }

    .launch-hp {
      position: absolute !important;
      left: -10000px !important;
      width: 1px !important;
      height: 1px !important;
      overflow: hidden !important;
    }

    @media (max-width: 600px) {
      .launch-form {
        border-radius: 24px;
        display: grid;
        padding: 10px;
      }
      .launch-form button { width: 100%; }
      .launch-form input[type="email"] { text-align: center; }
    }
CSS;

$html = str_replace("\n    footer {", $extraCss . "\n\n    footer {", $html);

$oldLaunch = <<<'OLD'
          <p>
            Faveside is currently in development. Follow along as we
            build a smarter, cleaner way to stay connected to the
            creators and moments you care about most.
          </p>

          <a class="button-primary" href="app.html">
            Open Faveside
          </a>
OLD;

$newLaunch = <<<'NEW'
          <p>
            Faveside is currently in development. Join the launch list for
            early access news, launch updates, and your first chance to try it.
          </p>

          <form class="launch-form" id="launchForm" action="launch-list.php" method="post" novalidate>
            <label class="launch-hp" aria-hidden="true">
              Website
              <input type="text" name="website" tabindex="-1" autocomplete="off" />
            </label>
            <input
              id="launchEmail"
              type="email"
              name="email"
              inputmode="email"
              autocomplete="email"
              placeholder="Enter your email address"
              aria-label="Email address"
              required
            />
            <button class="button-primary" id="launchSubmit" type="submit">Join the Launch List</button>
          </form>
          <p class="launch-message" id="launchMessage" role="status" aria-live="polite"></p>
          <p class="launch-note">No spam. Just Faveside launch news and early-access updates.</p>
NEW;

$html = str_replace($oldLaunch, $newLaunch, $html);

$script = <<<'JS'
<script>
(() => {
  const form = document.getElementById('launchForm');
  if (!form) return;

  const email = document.getElementById('launchEmail');
  const submit = document.getElementById('launchSubmit');
  const message = document.getElementById('launchMessage');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    message.className = 'launch-message';

    if (!email.value || !email.checkValidity()) {
      message.textContent = 'Please enter a valid email address.';
      message.classList.add('error');
      email.focus();
      return;
    }

    submit.disabled = true;
    const original = submit.textContent;
    submit.textContent = 'Joining…';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new URLSearchParams(new FormData(form)),
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }
      });

      let data = {};
      try { data = await response.json(); } catch (_) {}

      if (!response.ok || !data.ok) {
        throw new Error(data.message || 'We could not save your signup right now. Please try again.');
      }

      message.textContent = data.message;
      message.classList.add('success');
      form.reset();
    } catch (error) {
      message.textContent = error.message || 'We could not save your signup right now. Please try again.';
      message.classList.add('error');
    } finally {
      submit.disabled = false;
      submit.textContent = original;
    }
  });
})();
</script>
JS;

$html = str_replace("\n</body>", "\n" . $script . "\n</body>", $html);

echo $html;
