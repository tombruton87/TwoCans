<?php
/**
 * Notification settings: Mailgun email and an Uptime Kuma heartbeat.
 *
 * @var Store $store
 */
$repo = new NotificationRepository();
$config = $repo->get();
$canEdit = Auth::can('notifications');
?>
<div class="tc-stack tc-narrow">

  <section class="tc-card">
    <div class="tc-card__head">
      <div>
        <h2 class="tc-card__title">Notifications</h2>
        <div class="tc-card__hint">Email alerts (Mailgun) and an Uptime Kuma heartbeat.</div>
      </div>
      <?php if ($canEdit): ?>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="notifications_toggle">
          <button type="submit" class="tc-switch <?= $config['enabled'] ? 'is-on' : '' ?>" role="switch"
                  aria-checked="<?= $config['enabled'] ? 'true' : 'false' ?>" aria-label="Notifications"></button>
        </form>
      <?php else: ?>
        <span class="tc-switch <?= $config['enabled'] ? 'is-on' : '' ?>" role="img"
              title="Only the Owner can change this"></span>
      <?php endif; ?>
    </div>

    <?php if (!$config['enabled']): ?>
      <div class="tc-empty">Notifications are off.</div>
    <?php endif; ?>
  </section>

  <?php if ($config['enabled'] && $canEdit): ?>
    <form class="tc-card" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="notifications_save">
      <input type="hidden" name="enabled" value="1">

      <div class="tc-card__head"><h2 class="tc-card__title">Email — Mailgun</h2></div>
      <p class="tc-card__hint">
        Email is optional. Leave it blank and only the Uptime Kuma heartbeat runs.
      </p>

      <label class="tc-label">Mailgun API key
        <input class="tc-input" type="password" name="mailgun_api_key" autocomplete="off"
               placeholder="<?= $config['hasKey'] ? '•••••• (leave blank to keep the saved key)' : 'key-…' ?>">
      </label>
      <label class="tc-label">Region
        <select class="tc-input" name="mailgun_region">
          <option value="us" <?= $config['region'] === 'us' ? 'selected' : '' ?>>US — api.mailgun.net</option>
          <option value="eu" <?= $config['region'] === 'eu' ? 'selected' : '' ?>>EU — api.eu.mailgun.net</option>
        </select>
      </label>
      <label class="tc-label">Sending domain
        <input class="tc-input" type="text" name="mailgun_domain" value="<?= e($config['domain']) ?>"
               placeholder="mg.example.com">
      </label>
      <label class="tc-label">From
        <input class="tc-input" type="text" name="mailgun_from" value="<?= e($config['from']) ?>"
               placeholder="twocans@mg.example.com">
      </label>
      <label class="tc-label">To (comma-separated)
        <input class="tc-input" type="text" name="mailgun_to" value="<?= e($config['to']) ?>"
               placeholder="you@example.com">
      </label>

      <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

      <div class="tc-card__head"><h2 class="tc-card__title">Uptime Kuma</h2></div>
      <p class="tc-card__hint">
        Create a <b>Push</b> monitor in Uptime Kuma and paste its URL here. twocans
        heartbeats it every minute; when the heartbeats stop, Kuma marks twocans down.
      </p>
      <label class="tc-label">Push URL
        <input class="tc-input" type="text" name="uptime_kuma_url" value="<?= e($config['kumaUrl']) ?>"
               placeholder="https://kuma.example.com/api/push/…">
      </label>

      <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

      <div class="tc-card__head"><h2 class="tc-card__title">What to email about</h2></div>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_asks" <?= $config['notifyAsks'] ? 'checked' : '' ?>> New ask-to-call requests
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_offline" <?= $config['notifyOffline'] ? 'checked' : '' ?>> A phone going offline
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_low_credit" <?= $config['notifyLowCredit'] ? 'checked' : '' ?>> Low call credit
      </label>

      <div style="margin-top:18px">
        <button class="tc-btn tc-btn--teal" type="submit">Save</button>
      </div>
    </form>

    <?php if ($config['mailgunConfigured'] || $config['kumaUrl'] !== ''): ?>
      <div class="tc-card">
        <div class="tc-card__head"><h2 class="tc-card__title">Test</h2></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($config['mailgunConfigured']): ?>
            <form method="post" action="/" class="tc-inline-form">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="notifications_test_email">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Send test email</button>
            </form>
          <?php endif; ?>
          <?php if ($config['kumaUrl'] !== ''): ?>
            <form method="post" action="/" class="tc-inline-form">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="notifications_test_kuma">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Send test heartbeat</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php elseif ($config['enabled']): ?>
    <div class="tc-card"><div class="tc-card__hint">Only the Owner can change these settings.</div></div>
  <?php endif; ?>

</div>
