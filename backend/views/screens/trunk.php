<?php
/** @var Store $store */
$trunk = $store->trunk();
$low = $store->isLowCredit();
?>
<div class="tc-stack tc-narrow">

  <?php if ($trunk['connected']): ?>
    <section class="tc-card tc-card--lg">
      <div class="tc-trunk-head">
        <div class="tc-trunk-mark"><?= $trunk['provider'] === 'SIP.IO' ? 's' : 't' ?></div>
        <div class="tc-grow">
          <div class="tc-trunk-name">
            <h2><?= e($trunk['provider']) ?></h2>
            <span class="tc-pill tc-pill--ok">Connected</span>
          </div>
          <div class="tc-card__hint" style="font-size:13px">Your line's number · <?= e($trunk['number']) ?></div>
        </div>
      </div>

      <div class="tc-divider tc-divider--fine" style="margin:20px 0"></div>

      <?php if ($trunk['provider'] === 'Twilio'): ?>
        <div class="tc-credit-row">
          <div>
            <div class="tc-card__hint" style="font-weight:700">Call credit left</div>
            <div class="tc-credit <?= $low ? 'is-low' : '' ?>"><?= e(Presenter::money($trunk)) ?></div>
            <?php if ($low): ?>
              <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:2px">Running low — top up soon</div>
            <?php endif; ?>
          </div>
          <?php if (Auth::can('billing')): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="trunk_topup">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 22px;font-size:15px">+ Top up $20</button>
            </form>
          <?php else: ?>
            <span class="tc-micro">Only the Owner can top up the line.</span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="tc-credit-row">
          <div>
            <div class="tc-card__hint" style="font-weight:700">Billing</div>
            <div class="tc-credit">Usage-based</div>
          </div>
          <span class="tc-micro">No credit to top up — SIP.IO bills you monthly.</span>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($trunk['provider'] === 'Twilio'): ?>
      <div class="tc-grid tc-grid--stats">
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">This month</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-teal-deep)"><?= (int) $trunk['minutesThisMonth'] ?> min</div>
        </div>
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">Rate</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-ink)"><?= e($trunk['rate']) ?></div>
        </div>
        <div class="tc-card tc-card--sm">
          <div class="tc-card__hint" style="font-weight:700">Auto top-up</div>
          <div style="font:800 24px var(--tc-display);color:var(--tc-lav)"><?= $trunk['autoTopUp'] ? 'On' : 'Off' ?></div>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="tc-trunk-empty">
      <div class="tc-trunk-empty__string"></div>
      <div style="font:800 19px var(--tc-display)">No phone line yet</div>
      <div class="tc-card__hint" style="font-size:13px;max-width:340px">
        Connect a SIP trunk so your cans can actually reach the outside world — Twilio or SIP.IO.
      </div>
      <?php if (Auth::can('billing')): ?>
        <a class="tc-btn tc-btn--coral" href="<?= e(url(['screen' => 'trunk', 'trunkwizard' => 1])) ?>"
           style="padding:14px 24px;font-size:15px">Connect a provider</a>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can connect a phone line.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php
  /*
   * Where the outside world finds you.
   *
   * Two separate things live here. The external address is the name that points
   * at this house — what away-from-home phones provision against. Cloudflare is
   * an optional layer that keeps that name's record updated; someone with a
   * static IP or another DDNS service sets the name and skips Cloudflare.
   */
  $ddns = Presenter::ddns($store->dynamicDns());
  $ddnsDraft = $store->ddnsDraft();
  $canEditDdns = Auth::can('billing');
  $cloudflareSaved = (bool) $ddns['configured'];
  $cloudflareActive = (bool) $ddns['enabled'] && $cloudflareSaved;
  ?>
  <section class="tc-card tc-card--lg">
    <div class="tc-trunk-name" style="margin-bottom:6px">
      <h2>Where the outside world finds you</h2>
      <?php if ($cloudflareActive): ?>
        <span class="tc-pill tc-pill--<?= e($ddns['statusMod']) ?>"><?= e($ddns['statusLabel']) ?></span>
      <?php endif; ?>
    </div>

    <div class="tc-divider tc-divider--fine" style="margin:14px 0"></div>

    <div style="display:flex;gap:28px;flex-wrap:wrap">
      <div>
        <div class="tc-card__hint" style="font-weight:700">This network's address</div>
        <div style="font:800 19px var(--tc-display);color:var(--tc-teal-deep)">
          <?= $ddns['ip'] !== '' ? e($ddns['ip']) : 'not known yet' ?>
        </div>
      </div>
      <div>
        <div class="tc-card__hint" style="font-weight:700">Last checked</div>
        <div style="font:700 15px var(--tc-body);color:var(--tc-ink)"><?= e($ddns['checkedText']) ?></div>
      </div>
      <div>
        <div class="tc-card__hint" style="font-weight:700">Last updated</div>
        <div style="font:700 15px var(--tc-body);color:var(--tc-ink)"><?= e($ddns['updatedText']) ?></div>
      </div>
    </div>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">The name that points here</div>
    <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
      Away-from-home phones use this name. Point it at this house however you like —
      Cloudflare below, another DDNS service, or a static IP you keep updated yourself.
    </div>

    <?php if ($canEditDdns): ?>
      <form method="post" action="/" style="margin-top:10px">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="ddns_address">
        <div style="display:flex;gap:8px;align-items:flex-end">
          <label class="tc-label tc-label--sm tc-grow">External address
            <input class="tc-input tc-input--white" type="text" name="hostname" required
                   value="<?= e($ddns['hostname']) ?>" placeholder="e.g. phone.example.com" autocomplete="off">
          </label>
          <button class="tc-btn tc-btn--teal" type="submit" style="padding:13px 18px">Save</button>
        </div>
      </form>
    <?php else: ?>
      <div class="tc-micro" style="margin-top:6px">Only the Owner can change this.</div>
    <?php endif; ?>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">Keep it updated with Cloudflare <span class="tc-micro">(optional)</span></div>

    <?php if ($cloudflareSaved): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        <?php if ($cloudflareActive): ?>
          Cloudflare is keeping <strong><?= e($ddns['hostname']) ?></strong> pointed here.
        <?php else: ?>
          Cloudflare is set up but paused, so it is not updating
          <strong><?= e($ddns['hostname']) ?></strong> right now.
        <?php endif; ?>
      </div>

      <?php if ($canEditDdns): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <?php if ($cloudflareActive): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_update">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 20px;font-size:15px">Update now</button>
            </form>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_disable">
              <button class="tc-btn tc-btn--ghost" type="submit">Turn off</button>
            </form>
          <?php else: ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="ddns_enable">
              <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px 20px;font-size:15px">Turn back on</button>
            </form>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can change this.</span>
      <?php endif; ?>

      <?php if ($ddns['error'] !== null): ?>
        <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:12px">
          <?= e($ddns['error']) ?>
        </div>
      <?php elseif ($ddns['stale']): ?>
        <div class="tc-micro" style="margin-top:12px;line-height:1.5">
          No check has happened for a while. The checks run inside the app's own container —
          <code>docker logs twocans-php</code> and <code>docker/php/log/ddns.log</code> will say why.
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        If you use Cloudflare for DNS, twocans can keep the name above updated automatically.
        Skip this if you have a static IP or use another DDNS service.
      </div>

      <?php if ($canEditDdns): ?>
        <form method="post" action="/" style="margin-top:12px">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="ddns_connect">

          <div style="display:flex;flex-direction:column;gap:12px">
            <label class="tc-label tc-label--sm">Your domain
              <input class="tc-input tc-input--white" type="text" name="zone" required
                     value="<?= e((string) $ddnsDraft['zone']) ?>" placeholder="e.g. example.com" autocomplete="off">
            </label>
            <label class="tc-label tc-label--sm">Cloudflare API token
              <input class="tc-input tc-input--white" type="password" name="token" required
                     placeholder="••••••••••••" autocomplete="off">
            </label>
          </div>

          <div class="tc-micro" style="margin-top:12px;line-height:1.6">
            In Cloudflare: My Profile → API Tokens → Create Token → Create Custom Token. It needs
            <strong>Zone → Zone → Read</strong> and <strong>Zone → DNS → Edit</strong>, limited to
            this one domain. There is no account ID to enter — twocans finds the zone by name. The
            token is stored encrypted and used for nothing else.
          </div>

          <div class="tc-wizard-actions" style="margin-top:16px">
            <button class="tc-btn tc-btn--coral tc-btn--grow" type="submit" style="padding:13px;font-size:15px">
              Connect Cloudflare →
            </button>
          </div>
        </form>
      <?php else: ?>
        <span class="tc-micro">Only the Owner can set this up.</span>
      <?php endif; ?>
    <?php endif; ?>

    <?php
    $certificates = new Certificates();
    $cert = $certificates->status();
    $certDomain = $certificates->domain();
    $certPending = $certificates->pending();
    $certResult = $certificates->result();
    ?>

    <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

    <div class="tc-card__hint" style="font-weight:700">HTTPS certificate</div>

    <div class="tc-micro" style="margin-top:2px;line-height:1.5">
      Using Cloudflare Tunnel? Your public certificate is already provided by Cloudflare — skip this.
      This section only matters when the box serves HTTPS directly on port 443 with its own certificate.
    </div>

    <?php if (!$cert['exists']): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        No certificate yet — nginx will generate a default one when it starts.
      </div>
    <?php elseif ($cert['selfSigned']): ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        Serving a self-signed certificate — HTTPS works, but browsers show a warning.
      </div>
    <?php else: ?>
      <div class="tc-card__hint" style="font-size:13px;margin-top:2px">
        Let's Encrypt certificate from <strong><?= e($cert['issuer']) ?></strong>, valid until
        <strong><?= e($cert['validTo']) ?></strong>.
      </div>
    <?php endif; ?>

    <?php if ($canEditDdns): ?>
      <?php if ($certPending): ?>
        <div class="tc-micro" style="margin-top:10px;line-height:1.5">
          Waiting for nginx to obtain the certificate for
          <strong><?= e((string) $certDomain) ?></strong>… this can take a minute.
        </div>
      <?php elseif ($certDomain !== null): ?>
        <form method="post" action="/" style="margin-top:10px">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="cert_request">
          <label class="tc-label tc-label--sm">Email (optional, for expiry notices)
            <input class="tc-input tc-input--white" type="email" name="email" placeholder="you@example.com" autocomplete="off">
          </label>
          <div class="tc-wizard-actions" style="margin-top:12px">
            <button class="tc-btn tc-btn--coral" type="submit" style="padding:13px;font-size:15px">
              Get a Let's Encrypt certificate for <?= e($certDomain) ?>
            </button>
          </div>
        </form>
      <?php else: ?>
        <div class="tc-micro" style="margin-top:10px">
          Set the external address above to choose the certificate's domain.
        </div>
      <?php endif; ?>

      <?php if ($certResult !== null && $certResult !== 'issued'): ?>
        <div style="font:800 12px var(--tc-body);color:var(--tc-coral-lip);margin-top:10px;white-space:pre-wrap">
          <?= e($certResult) ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <span class="tc-micro">Only the Owner can change this.</span>
    <?php endif; ?>

    <details style="margin-top:16px">
      <summary style="font:800 13px var(--tc-display);color:var(--tc-teal-deep);cursor:pointer">
        Router port forwarding — for the web UI over HTTPS
      </summary>

      <div class="tc-micro" style="margin-top:12px;line-height:1.6">
        To open this admin from outside your wi-fi, forward this to
        <strong><?= e(getenv('SIP_DOMAIN') ?: 'this server') ?></strong>:
      </div>

      <table style="width:100%;border-collapse:collapse;font:600 12px var(--tc-body);margin-top:10px">
        <tr>
          <td style="padding:7px 8px;border-bottom:1px solid var(--tc-border-3);white-space:nowrap"><strong>443</strong> TCP</td>
          <td style="padding:7px 8px;border-bottom:1px solid var(--tc-border-3);white-space:nowrap">→ port 443</td>
          <td style="padding:7px 8px;border-bottom:1px solid var(--tc-border-3)">Web UI (HTTPS)</td>
        </tr>
      </table>

      <div class="tc-micro" style="margin-top:10px;line-height:1.6">
        Calls are local to the house, so no SIP or RTP ports need forwarding.
      </div>
    </details>
  </section>
</div>
