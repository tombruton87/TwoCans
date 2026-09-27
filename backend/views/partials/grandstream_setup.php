<?php
/**
 * How to point a Grandstream phone or adapter at twocans: where its web page
 * is, and what to type in. Used by the add-a-phone wizard and the phone page.
 *
 * @var array $d DeviceRepository::toView()
 */
$pass = (new SettingsRepository())->provisionPass();
$base = 'http://' . PjsipConfig::domain() . ':' . (int) (getenv('HTTP_PORT') ?: 8083);
?>
<?php if ($d['ata']): ?>
  <ol class="tc-qr__steps">
    <li>Plug the adapter into your router, and a corded phone into
      <?= $d['type'] === 'ht802' ? 'its <b>Phone 1</b> socket (and <b>Phone 2</b> if you set one up)' : 'its <b>Phone</b> socket' ?>.</li>
    <li>Find its address: pick up the phone, dial <b>***</b>, then <b>02</b> — it reads the IP address out.
      (Or look for it in your router.)</li>
    <li>Open that address in a browser and sign in — the password is on the label under the adapter
      (older ones use <b>admin</b>).</li>
    <li>Under <b>Maintenance → Upgrade and Provisioning</b>: set <b>Config Upgrade Via</b> to <b>HTTP</b>,
      and <b>Config Server Path</b> to <code><?= e(substr($base, 7)) ?>/grandstream</code></li>
    <li>Set <b>HTTP/HTTPS User Name</b> to <b>twocans</b> and <b>HTTP/HTTPS Password</b> to <code><?= e($pass) ?></code></li>
    <li>Save, then reboot the adapter. When the phone gives a dial tone, it's on the line.</li>
  </ol>
<?php else: ?>
  <ol class="tc-qr__steps">
    <li>Plug the phone in and find its IP address (check your router, or the phone's menu).</li>
    <li>Open that IP in a browser to reach the phone's web UI.</li>
    <li>Set <b>Config Server Path</b> to <code><?= e($base) ?>/grandstream/</code></li>
    <li>Set the config username to <b>twocans</b> and the password to <code><?= e($pass) ?></code></li>
    <li>Set the remote phonebook to <code>http://twocans:<?= e($pass) ?>@<?= e(substr($base, 7)) ?>/phonebook/grandstream.xml</code></li>
    <li>Save, then reboot the phone — it fetches its settings on boot.</li>
  </ol>
<?php endif; ?>
