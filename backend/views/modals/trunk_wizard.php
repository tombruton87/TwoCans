<?php
/**
 * Connect-a-phone-line wizard: pick provider → paste keys → test the line.
 *
 * @var Store $store
 * @var int   $step 1–3
 */
$draft = $store->trunkDraft();
$closeUrl = url(['screen' => 'trunk']);
?>
<div class="tc-modal" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>" role="dialog" aria-modal="true" aria-label="Connect a phone line">
  <div class="tc-modal__panel tc-modal__panel--sm">

    <div class="tc-modal__head">
      <div class="tc-modal__title">Connect a phone line</div>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <div class="tc-modal__body">

      <?php if ($step === 1): ?>
        <div class="tc-wizard-title" style="margin-bottom:14px">Pick a provider</div>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php
          $providers = [
              'Twilio' => ['mark' => 't', 'style' => 'background:var(--tc-twilio)', 'sub' => 'Recommended · pay-as-you-go'],
              'SIP.IO' => ['mark' => 's', 'style' => 'background:var(--tc-teal-deep)', 'sub' => 'Cheaper · free trial'],
          ];
          foreach ($providers as $key => $p): ?>
            <form method="post" action="/" style="display:flex">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="trunk_wizard_step">
              <input type="hidden" name="step" value="2">
              <input type="hidden" name="provider" value="<?= e($key) ?>">
              <button class="tc-provider-row tc-provider-row--active" type="submit">
                <span class="tc-provider-row__mark" style="<?= e($p['style']) ?>"><?= e($p['mark']) ?></span>
                <span class="tc-grow">
                  <span class="tc-provider-row__name" style="display:block"><?= e($key) ?></span>
                  <span class="tc-card__hint" style="display:block"><?= e($p['sub']) ?></span>
                </span>
                <span style="color:var(--tc-teal-deep);font:800 18px var(--tc-display)">→</span>
              </button>
            </form>
          <?php endforeach; ?>

          <?php foreach ([['V', 'Vonage'], ['B', 'Bring your own SIP']] as [$mark, $name]): ?>
            <div class="tc-provider-row tc-provider-row--soon">
              <span class="tc-provider-row__mark tc-provider-row__mark--soon"><?= e($mark) ?></span>
              <span class="tc-grow">
                <span class="tc-provider-row__name" style="display:block"><?= e($name) ?></span>
                <span class="tc-card__hint" style="display:block">Coming soon</span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

      <?php elseif ($step === 2): ?>
        <?php $isSipio = $draft['provider'] === 'SIP.IO'; ?>
        <div class="tc-wizard-title"><?= $isSipio ? 'Paste your SIP.IO details' : 'Paste your Twilio keys' ?></div>
        <div class="tc-wizard-sub" style="font-size:12px;margin-bottom:16px"><?= $isSipio ? "Copy these from your SIP.IO console → we'll handle the rest." : "Copy these from your Twilio console → we'll handle the rest." ?></div>

        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="trunk_wizard_step">
          <input type="hidden" name="step" value="3">
          <input type="hidden" name="provider" value="<?= e((string) $draft['provider']) ?>">

          <div style="display:flex;flex-direction:column;gap:12px">
            <?php if ($isSipio): ?>
              <label class="tc-label tc-label--sm">API key
                <input class="tc-input tc-input--white" type="password" name="apiKey" value="<?= e((string) $draft['apiKey']) ?>" placeholder="sk_…" autocomplete="off">
              </label>
              <label class="tc-label tc-label--sm">Your SIP.IO number
                <input class="tc-input tc-input--white" type="text" name="number" value="<?= e((string) $draft['number']) ?>" placeholder="+1 (628) 555-0100">
              </label>
              <label class="tc-label tc-label--sm">SIP edge host (proxy)
                <input class="tc-input tc-input--white" type="text" name="proxy" value="<?= e((string) $draft['proxy']) ?>" placeholder="sip.your-account.sip.io" autocomplete="off">
              </label>
            <?php else: ?>
              <label class="tc-label tc-label--sm">Account SID
                <input class="tc-input tc-input--white" type="text" name="sid" value="<?= e((string) $draft['sid']) ?>" placeholder="ACxxxxxxxxxxxxxxxx" autocomplete="off">
              </label>
              <label class="tc-label tc-label--sm">Auth token
                <input class="tc-input tc-input--white" type="password" name="token" value="<?= e((string) $draft['token']) ?>" placeholder="••••••••••••" autocomplete="off">
              </label>
              <label class="tc-label tc-label--sm">Your Twilio number
                <input class="tc-input tc-input--white" type="text" name="number" value="<?= e((string) $draft['number']) ?>" placeholder="+1 (628) 555-0100">
              </label>
              <label class="tc-label tc-label--sm">Termination SIP URI
                <input class="tc-input tc-input--white" type="text" name="termination" value="<?= e((string) $draft['termination']) ?>" placeholder="your-trunk.pstn.twilio.com" autocomplete="off">
              </label>
            <?php endif; ?>
          </div>

          <div class="tc-wizard-actions" style="margin-top:18px">
            <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'trunk', 'trunkwizard' => 1])) ?>" style="padding:13px 18px">Back</a>
            <button class="tc-btn tc-btn--coral tc-btn--grow" type="submit" style="padding:13px;font-size:15px">Connect →</button>
          </div>
        </form>

      <?php else: ?>
        <div class="tc-spinner-step">
          <div class="tc-spinner tc-spinner--twilio"></div>
          <h3>Connecting…</h3>
          <p>Verifying credentials and writing the trunk config.</p>
        </div>
        <form method="post" action="/" data-tc-advance data-tc-delay="1600">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="trunk_connect">
          <noscript><button class="tc-btn tc-btn--ghost tc-btn--block" type="submit">Continue</button></noscript>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>
