<?php

namespace App\Commands;

use App\Libraries\OrderNotifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * Smoke-test the new-order email/whatsapp notifier without placing a real order.
 *
 * Usage:
 *   php spark notify:test
 *
 * Calls CI4's Email service directly so we can read send() and printDebugger() — that
 * way SMTP failures surface as CLI output rather than being swallowed by OrderNotifier's
 * fire-and-forget try/catch.
 */
class NotifyTest extends BaseCommand
{
    protected $group       = 'Marooff';
    protected $name        = 'notify:test';
    protected $description = 'Send a test "new order" notification to the configured recipients.';

    public function run(array $params)
    {
        CLI::write('Recipients: ' . env('notify.email.recipients', '(none)'), 'yellow');
        CLI::write('From:       ' . env('notify.email.from', '(none)'), 'yellow');
        CLI::write('SMTPUser:   ' . env('email.SMTPUser', '(none)'), 'yellow');
        CLI::write('Protocol:   ' . (env('email.protocol') ?? config('Email')->protocol), 'yellow');
        CLI::write('');

        $email = Services::email();
        $email->setFrom(env('notify.email.from', ''), env('notify.email.from_name', 'Marooff'));
        $email->setTo(env('notify.email.recipients', ''));
        $email->setSubject('Marooff test email — ' . date('Y-m-d H:i:s'));
        $email->setMailType('html');
        $email->setMessage('<h2>Marooff test email</h2><p>If you can see this, SMTP is wired correctly.</p>');
        $email->setAltMessage('Marooff test email — SMTP is wired correctly.');

        CLI::write('Sending…', 'cyan');
        $ok = $email->send(false);

        if ($ok) {
            CLI::write('✓ Email sent successfully. Check the inbox.', 'green');
            return 0;
        }

        CLI::error('✗ Email send returned false. SMTP debugger:');
        CLI::write($email->printDebugger(['headers']), 'red');
        return 1;
    }
}
