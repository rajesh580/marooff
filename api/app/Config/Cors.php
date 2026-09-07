<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * The default CORS configuration.
     *
     * `allowedOrigins` merges:
     *   - the hard-coded localhost dev origins (so `npm run dev` keeps working)
     *   - whatever is set in `.env` under `cors.allowed_origins` (comma-separated list)
     *
     * That way the same backend works in dev AND prod without code changes —
     * production gets its origins from the .env, dev gets the bundled ones.
     */
    public array $default = [
        'allowedOrigins' => [
            'https://marooffc.com', 'https://www.marooffc.com',
            'https://admin.marooffc.com', 'https://sales.marooffc.com',
            'http://localhost:3000', 'http://127.0.0.1:3000',
            'http://localhost:5173', 'http://127.0.0.1:5173',
        ],

        /**
         * Origin regex patterns for the `Access-Control-Allow-Origin` header.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
         *
         * NOTE: A pattern specified here is part of a regular expression. It will
         *       be actually `#\A<pattern>\z#`.
         *
         * E.g.:
         *   - ['https://\w+\.example\.com']
         */
        'allowedOriginsPatterns' => [],

        /**
         * Weather to send the `Access-Control-Allow-Credentials` header.
         *
         * The Access-Control-Allow-Credentials response header tells browsers whether
         * the server allows cross-origin HTTP requests to include credentials.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Credentials
         */
        'supportsCredentials' => true,

        /**
         * Set headers to allow.
         *
         * The Access-Control-Allow-Headers response header is used in response to
         * a preflight request which includes the Access-Control-Request-Headers to
         * indicate which HTTP headers can be used during the actual request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Headers
         */
        'allowedHeaders' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'],

        /**
         * Set headers to expose.
         *
         * The Access-Control-Expose-Headers response header allows a server to
         * indicate which response headers should be made available to scripts running
         * in the browser, in response to a cross-origin request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Expose-Headers
         */
        'exposedHeaders' => [],

        /**
         * Set methods to allow.
         *
         * The Access-Control-Allow-Methods response header specifies one or more
         * methods allowed when accessing a resource in response to a preflight
         * request.
         *
         * E.g.:
         *   - ['GET', 'POST', 'PUT', 'DELETE']
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Methods
         */
        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        /**
         * Set how many seconds the results of a preflight request can be cached.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Max-Age
         */
        'maxAge' => 7200,
    ];

    /**
     * Merge the .env-defined origins (cors.allowed_origins, comma-separated)
     * into the default `allowedOrigins` list. Called automatically by CI4
     * after the config class is instantiated.
     */
    public function __construct()
    {
        parent::__construct();

        $extra = (string) env('cors.allowed_origins', '');
        if ($extra !== '') {
            foreach (explode(',', $extra) as $origin) {
                $origin = trim($origin);
                if ($origin !== '' && !in_array($origin, $this->default['allowedOrigins'], true)) {
                    $this->default['allowedOrigins'][] = $origin;
                }
            }
        }
    }
}
