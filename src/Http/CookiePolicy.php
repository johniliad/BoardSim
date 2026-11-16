<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * The one door through which a cookie may pass.
 *
 * BoardSim sets no cookies today: the API is stateless, runs are addressed by
 * unguessable UUIDs and there is no login. That is exactly why this class
 * exists — before any cookie does. A "Cookie No HttpOnly Flag" finding is
 * cheapest to prevent structurally than to fix after a cookie has shipped,
 * and a shared host's php.ini can start a session without this code asking
 * for one. Two defenses:
 *
 *   1. harden() pins PHP's session-cookie settings before anything can start
 *      a session, so even a future session_start() — or a host that forces
 *      session.auto_start — cannot emit a cookie without HttpOnly, Secure and
 *      SameSite. Secure fails closed: on a plain-HTTP origin no session
 *      cookie is written at all, which is the right outcome for a dev server.
 *   2. setCookie() is the only sanctioned way for application code to set a
 *      cookie. It forces the site-wide path, HttpOnly, Secure and SameSite;
 *      a caller may choose a name, a value and a lifetime, never a weaker
 *      attribute.
 */
final class CookiePolicy
{
    /**
     * Lax, not Strict: the product has no login and no cross-site state, so
     * there is nothing to protect with Strict, and Lax keeps a future cookie
     * working on a normal top-level navigation (e.g. opening a share link).
     */
    public const SAME_SITE = 'Lax';

    /**
     * Call once, as early as possible, in every HTTP entry point — before any
     * output, before anything that could start a session or set a cookie.
     */
    public static function harden(): void
    {
        // Strict mode rejects session ids that were not issued by PHP itself
        // (session fixation), and trans_sid must never rewrite URLs to carry
        // the id, because a session id in a URL leaks into logs and referrers.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.auto_start', '0');

        // The attribute INI values: these also govern a session that a host
        // configuration auto-started before this script ran, so the next
        // Set-Cookie PHP emits still carries the full attribute set.
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', self::SAME_SITE);

        // And the authoritative pre-start declaration for the next
        // session_start(): same attributes, host-only cookie, site-wide path.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => self::SAME_SITE,
        ]);
    }

    /**
     * The attributes every cookie gets, whatever the caller asked for.
     *
     * Kept pure so the policy can be asserted without sending a header.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function attributes(array $options = []): array
    {
        return [
            'lifetime' => (int) ($options['lifetime'] ?? 0),
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => self::SAME_SITE,
        ];
    }

    /**
     * Set a cookie through the policy. HttpOnly, Secure, SameSite=Lax,
     * host-only, site-wide path — always. Returns setcookie()'s own result.
     *
     * @param array<string, mixed> $options
     */
    public static function setCookie(string $name, string $value, array $options = []): bool
    {
        return setcookie($name, $value, self::attributes($options));
    }
}