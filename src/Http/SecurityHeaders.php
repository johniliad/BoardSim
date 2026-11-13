<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * Response security headers, in one place so the policy is testable.
 *
 * The SPA and the API share one origin, so there is no CORS header and no
 * cross-site cookie. What is left worth defending against is injection and
 * framing, which is what this policy does:
 *
 *   - script-src 'self' with no 'unsafe-inline' and no 'unsafe-eval';
 *   - style-src 'self' with style-src-attr 'none';
 *   - object-src 'none', base-uri 'none', form-action 'self';
 *   - frame-ancestors 'none' plus legacy X-Frame-Options: DENY.
 *
 * The style policy used to allow 'unsafe-inline' because the SPA set ~80 React
 * inline style attributes for spacing. Those are now utility classes
 * (`.bs-mt-4`, `.bs-muted-90`, …), so the departure is closed: no markup in the
 * product carries a style attribute. The one remaining dynamic style — the
 * glossary popover's anchor position — is written through the CSSOM
 * (`element.style`), which CSP does not govern, so it does not require an
 * exemption.
 *
 * `style-src-attr 'none'` is kept explicitly: it fails closed if a style
 * attribute is reintroduced, which is the point of removing them.
 */
final class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; "
        . "base-uri 'none'; "
        . "object-src 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "style-src-attr 'none'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'";

    public const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), '
        . 'gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    /**
     * JSON responses. `no-store` because a scored run is participant data.
     *
     * @return array<string, string>
     */
    public static function forApi(bool $https): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cache-Control' => 'no-store',
        ];

        // HSTS only over TLS: a browser that sees this header on plain HTTP
        // ignores it, but an origin that later drops back to HTTP would then be
        // unreachable, so it is tied to the actual request scheme.
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    public static function forStatic(bool $https): array
    {
        $headers = self::forApi($https);
        unset($headers['Content-Type'], $headers['Cache-Control']);

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function emit(array $headers): void
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    public static function requestIsHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
