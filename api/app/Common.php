<?php

/**
 * Common Functions
 *
 * This file allows developers to overwrite core procedural functions
 * and replace them with their own.
 */

if (! function_exists('env')) {
    /**
     * Enhanced env helper that supports both standard dot-notation
     * (e.g. 'stripe.publishable_key') and UPPERCASE underscore notation
     * (e.g. 'STRIPE_PUBLISHABLE_KEY') as required by Hostinger GUI.
     */
    function env(string $key, $default = null)
    {
        $upperKey = strtoupper(str_replace('.', '_', $key));

        $value = $_ENV[$key]
            ?? $_ENV[$upperKey]
            ?? $_SERVER[$key]
            ?? $_SERVER[$upperKey]
            ?? getenv($key);

        if ($value === false || $value === null) {
            $value = getenv($upperKey);
        }

        // Not found? Return the default value
        if ($value === false || $value === null) {
            return $default;
        }

        // Handle boolean/null keywords
        return match (strtolower((string) $value)) {
            'true'  => true,
            'false' => false,
            'empty' => '',
            'null'  => null,
            default => $value,
        };
    }
}
