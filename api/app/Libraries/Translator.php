<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * English → Arabic translation via the MyMemory free API.
 *
 *   GET https://api.mymemory.translated.net/get
 *       ?q=<text>&langpair=en|ar&de=<email>
 *
 * Defensive on purpose: a save must NEVER break because translation failed.
 * Any error returns null, gets logged, and the calling code leaves the
 * Arabic column NULL (which the storefront treats as "fall back to English").
 *
 * Use:
 *   $t = new Translator();
 *   $arabic = $t->en2ar('Wet and Dry Compact Foundation');     // string or null
 *   $batch  = $t->en2arMany(['name' => 'Foo', 'desc' => 'Bar']);
 */
class Translator
{
    private const ENDPOINT      = 'https://api.mymemory.translated.net/get';
    private const TIMEOUT_SEC   = 5;
    private const THROTTLE_USEC = 200_000;   // 0.2s between requests
    private const ACCOUNT_EMAIL = 'vijayanand@eloanoriginators.com';

    /** Same-request memoization so a single save never calls the API twice for an identical string. */
    private static array $cache = [];
    private static float $lastCallAt = 0.0;

    /**
     * Translate one English string to Arabic.
     * Returns the Arabic text, or null on empty input / failure / kill switch off.
     */
    public function en2ar(string $text): ?string
    {
        $text = trim($text);
        if ($text === '')       return null;
        if (!$this->enabled())  return null;

        $key = md5($text);
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        $this->throttle();

        $url = self::ENDPOINT . '?' . http_build_query([
            'q'        => $text,
            'langpair' => 'en|ar',
            'de'       => self::ACCOUNT_EMAIL,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'MaroofStore/1.0 (+https://marooffc.com)',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            log_message('warning', 'Translator HTTP error: ' . ($err ?: "code={$code}") . ' for: ' . substr($text, 0, 100));
            self::$cache[$key] = null;
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || ((int) ($data['responseStatus'] ?? 0)) !== 200) {
            log_message('warning', 'Translator response error: ' . substr($body, 0, 200));
            self::$cache[$key] = null;
            return null;
        }

        $arabic = (string) ($data['responseData']['translatedText'] ?? '');
        $arabic = html_entity_decode($arabic, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // MyMemory occasionally wraps the result in quotes — strip them.
        $arabic = trim($arabic, " \t\n\r\"'");

        if ($arabic === '') {
            self::$cache[$key] = null;
            return null;
        }

        self::$cache[$key] = $arabic;
        return $arabic;
    }

    /**
     * Translate many English strings in one call. Keys preserved.
     * Skipped fields (null/empty source) stay null in the output.
     */
    public function en2arMany(array $texts): array
    {
        $out = [];
        foreach ($texts as $k => $v) {
            $out[$k] = is_string($v) ? $this->en2ar($v) : null;
        }
        return $out;
    }

    /** Kill switch — admin can flip settings.translator_enabled to '0' to disable all translation. */
    private function enabled(): bool
    {
        static $flag = null;
        if ($flag === null) {
            $flag = ((new SettingModel())->get('translator_enabled') ?? '1') === '1';
        }
        return $flag;
    }

    private function throttle(): void
    {
        $elapsed = (microtime(true) - self::$lastCallAt) * 1_000_000;
        if ($elapsed < self::THROTTLE_USEC) {
            usleep((int) (self::THROTTLE_USEC - $elapsed));
        }
        self::$lastCallAt = microtime(true);
    }
}
