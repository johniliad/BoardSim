<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * Response security headers, in one place so the policy is testable.
 *
 * The SPA and the API share one origin, so there is no CORS header and no
 * cross-site cookie. What is left worth defending against is injection,
 * framing and cross-origin reading, which is what this policy does:
 *
 *   - script-src 'self' with no 'unsafe-inline' and no 'unsafe-eval';
 *   - style-src 'self' with style-src-attr 'none';
 *   - object-src 'none', base-uri 'none', form-action 'self';
 *   - frame-ancestors 'none' plus legacy X-Frame-Options: DENY;
 *   - Cross-Origin-Resource-Policy and Cross-Origin-Opener-Policy, so the
 *     origin states same-origin isolation explicitly instead of relying on
 *     the mere absence of an Access-Control-Allow-Origin header.
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
 *
 * Three CSP directives — base-uri, form-action and frame-ancestors — have no
 * fallback to default-src, so each one is always spelled out: a policy that
 * omits one is read by the browser as allowing anything for it, which is
 * exactly what ZAP's "CSP: Failure to Define Directive with No Fallback"
 * finding measures.
 *
 * HSTS is emitted on every response, over HTTP and HTTPS alike. A user agent
 * is required by RFC 6797 to ignore the header when it arrives over a
 * non-secure transport, so unconditional emission cannot lock an origin out
 * of anything; what it fixes is the proxy case this site actually runs in:
 * behind Cloudflare the origin may see plain HTTP even though the browser's
 * connection is HTTPS, and gating the header on the origin-visible scheme
 * silently withheld it from exactly the responses (/v1) a browser loads over
 * HTTPS. public/.htaccess mirrors this for the static channel, and
 * bin/verify-http.php keeps the two policies identical.
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
    public static function forApi(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function forStatic(): array
    {
        $headers = self::forApi();
        unset($headers['Content-Type'], $headers['Cache-Control']);

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function emit(array $headers): void
    {
        // The PHP version is an implementation detail no response needs to
        // carry (expose_php cannot be changed at runtime; the header can go).
        header_remove('X-Powered-By');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
