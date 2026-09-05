<?php

namespace App\Libraries;

use Config\Services;

/**
 * Fires "new order received" notifications to the storefront owner.
 *
 *   • Email  — via CodeIgniter's Email service (SMTP or PHP mail()).
 *              Config keys (env): notify.email.recipients, email.* (CI defaults).
 *   • WhatsApp — generic JSON webhook so any provider (UltraMsg, WaSender, Twilio,
 *              or self-hosted) can be plugged in. Config keys (env):
 *                notify.whatsapp.url       e.g. https://api.ultramsg.com/instance123/messages/chat
 *                notify.whatsapp.token     bearer/auth token to send
 *                notify.whatsapp.to        comma-separated E.164 phone numbers
 *                notify.whatsapp.body_key  POST-body key name for the message (default: body)
 *                notify.whatsapp.to_key    POST-body key name for the recipient (default: to)
 *
 * Both channels are wrapped in try/catch — a failure NEVER blocks order placement.
 * Errors get logged to writable/logs so we can debug after the fact.
 */
final class OrderNotifier
{
    /** Fire both email + whatsapp for a freshly created order. */
    public static function newOrder(array $order): void
    {
        try { self::sendEmail($order); }    catch (\Throwable $e) { log_message('error', 'OrderNotifier email: ' . $e->getMessage()); }
        try { self::sendWhatsApp($order); } catch (\Throwable $e) { log_message('error', 'OrderNotifier whatsapp: ' . $e->getMessage()); }
    }

    // ---------------------------------------------------------- Email
    private static function sendEmail(array $order): void
    {
        $recipientsRaw = (string) env('notify.email.recipients', '');
        if ($recipientsRaw === '') return; // not configured — skip

        $recipients = array_filter(array_map('trim', explode(',', $recipientsRaw)));
        if (!$recipients) return;

        $from     = (string) env('notify.email.from', 'orders@marooff.ae');
        $fromName = (string) env('notify.email.from_name', 'Marooff Store');

        $email = Services::email();
        $email->setFrom($from, $fromName);
        $email->setTo($recipients);
        $email->setSubject('New order #' . ($order['order_number'] ?? $order['id']) . ' — ' . self::aed($order['grand_total_minor'] ?? 0));
        $email->setMailType('html');
        $email->setMessage(self::renderHtml($order));

        // Plain-text alternative
        $email->setAltMessage(self::renderText($order));

        $email->send(false);
    }

    // ---------------------------------------------------------- WhatsApp
    private static function sendWhatsApp(array $order): void
    {
        $url   = (string) env('notify.whatsapp.url', '');
        $token = (string) env('notify.whatsapp.token', '');
        $toRaw = (string) env('notify.whatsapp.to', '');
        if ($url === '' || $toRaw === '') return; // not configured — skip

        $tos = array_filter(array_map('trim', explode(',', $toRaw)));
        if (!$tos) return;

        $bodyKey = (string) env('notify.whatsapp.body_key', 'body');
        $toKey   = (string) env('notify.whatsapp.to_key', 'to');
        $message = self::renderText($order);

        foreach ($tos as $phone) {
            $payload = [
                $toKey   => $phone,
                $bodyKey => $message,
            ];
            // Some providers (UltraMsg) want the token in the body, others in a header.
            // Send it both ways — providers ignore the field they don't recognise.
            if ($token !== '') $payload['token'] = $token;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    ...($token ? ['Authorization: Bearer ' . $token] : []),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                log_message('warning', "WhatsApp notify HTTP {$code} for {$phone}: " . substr((string) $resp, 0, 200));
            }
        }
    }

    // ---------------------------------------------------------- Renderers
    private static function aed(int $minor): string
    {
        return 'AED ' . number_format($minor / 100, 2);
    }

    private static function renderHtml(array $o): string
    {
        $rows = '';
        foreach ((array) ($o['items'] ?? []) as $it) {
            $name  = htmlspecialchars((string) ($it['name_snapshot'] ?? ''));
            $qty   = (int) ($it['qty'] ?? 0);
            $line  = self::aed((int) ($it['line_total_minor'] ?? 0));
            $rows .= "<tr><td style='padding:6px 12px;border-bottom:1px solid #eee'>{$name}</td>"
                  .  "<td style='padding:6px 12px;border-bottom:1px solid #eee;text-align:right'>×{$qty}</td>"
                  .  "<td style='padding:6px 12px;border-bottom:1px solid #eee;text-align:right'>{$line}</td></tr>";
        }
        $num    = htmlspecialchars((string) ($o['order_number'] ?? $o['id'] ?? ''));
        $name   = htmlspecialchars((string) ($o['customer_name'] ?? ''));
        $email  = htmlspecialchars((string) ($o['customer_email'] ?? ''));
        $phone  = htmlspecialchars((string) ($o['customer_phone'] ?? ''));
        $pm     = strtoupper((string) ($o['payment_method'] ?? ''));
        $total  = self::aed((int) ($o['grand_total_minor'] ?? 0));

        return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;color:#111;max-width:600px;margin:auto">
  <h2 style="background:#b91c1c;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0;margin:0">
    New order #{$num}
  </h2>
  <div style="border:1px solid #eee;border-top:0;padding:16px 20px;border-radius:0 0 8px 8px">
    <p><strong>Customer:</strong> {$name}<br>
       <strong>Email:</strong> {$email}<br>
       <strong>Phone:</strong> {$phone}<br>
       <strong>Payment:</strong> {$pm}<br>
       <strong>Total:</strong> <span style="font-size:18px;color:#b91c1c"><strong>{$total}</strong></span></p>
    <table style="width:100%;border-collapse:collapse;margin-top:8px">
      <thead><tr style="background:#f8fafc">
        <th style="padding:8px 12px;text-align:left">Item</th>
        <th style="padding:8px 12px;text-align:right">Qty</th>
        <th style="padding:8px 12px;text-align:right">Line total</th>
      </tr></thead>
      <tbody>{$rows}</tbody>
    </table>
    <p style="color:#666;font-size:12px;margin-top:16px">Sign in to the admin panel to confirm and ship the order.</p>
  </div>
</div>
HTML;
    }

    private static function renderText(array $o): string
    {
        $lines = [];
        $lines[] = '🛍️ Marooff — NEW ORDER';
        $lines[] = '──────────────────────';
        $lines[] = 'Order: #' . ($o['order_number'] ?? $o['id'] ?? '');
        $lines[] = 'Customer: ' . ($o['customer_name'] ?? '—');
        if (!empty($o['customer_phone'])) $lines[] = 'Phone: ' . $o['customer_phone'];
        if (!empty($o['customer_email'])) $lines[] = 'Email: ' . $o['customer_email'];
        $lines[] = 'Payment: ' . strtoupper((string) ($o['payment_method'] ?? ''));
        $lines[] = '';
        foreach ((array) ($o['items'] ?? []) as $it) {
            $lines[] = '• ' . ($it['name_snapshot'] ?? '') . ' ×' . (int) ($it['qty'] ?? 0) . ' — ' . self::aed((int) ($it['line_total_minor'] ?? 0));
        }
        $lines[] = '';
        $lines[] = 'TOTAL: ' . self::aed((int) ($o['grand_total_minor'] ?? 0));
        return implode("\n", $lines);
    }
}
