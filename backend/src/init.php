<?php
declare(strict_types=1);

/**
 * Shared setup for both web requests and CLI scripts. No session here — see
 * bootstrap.php (web) and bootstrap_cli.php (commands).
 */

ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set(getenv('TZ') ?: 'Europe/London');

require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Permissions.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/GuardianRepository.php';
require __DIR__ . '/DeviceRepository.php';
require __DIR__ . '/CallRepository.php';
require __DIR__ . '/Transcriber.php';
require __DIR__ . '/ContactRepository.php';
require __DIR__ . '/CallRequestRepository.php';
require __DIR__ . '/VoicemailRepository.php';
require __DIR__ . '/Crypto.php';
require __DIR__ . '/Twilio.php';
require __DIR__ . '/Sipio.php';
require __DIR__ . '/Cloudflare.php';
require __DIR__ . '/TrunkRepository.php';
require __DIR__ . '/DynamicDnsRepository.php';
require __DIR__ . '/DynamicDns.php';
require __DIR__ . '/Certificates.php';
require __DIR__ . '/Ami.php';
require __DIR__ . '/SystemHealth.php';
require __DIR__ . '/PjsipConfig.php';
require __DIR__ . '/Provisioning.php';
require __DIR__ . '/PhotoStore.php';
require __DIR__ . '/JokeStore.php';
require __DIR__ . '/JokeRepository.php';
require __DIR__ . '/DialplanRuleRepository.php';
require __DIR__ . '/SettingsRepository.php';
require __DIR__ . '/Retention.php';
require __DIR__ . '/LiveCalls.php';
require __DIR__ . '/Presenter.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Backup.php';
require __DIR__ . '/Mailgun.php';
require __DIR__ . '/UptimeKuma.php';
require __DIR__ . '/NotificationRepository.php';
require __DIR__ . '/Notifier.php';
require __DIR__ . '/GrandstreamProvisioning.php';
require __DIR__ . '/DeviceHotkeyRepository.php';
require __DIR__ . '/Phonebook.php';
