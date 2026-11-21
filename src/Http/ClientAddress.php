<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * Resolve the identity used by the application rate limiter.
 *
 * Production traffic is origin-locked to Cloudflare, so CF-Connecting-IP is the
 * authoritative visitor address there. REMOTE_ADDR remains the fallback for
 * local development and hosts where Cloudflare did not provide the header.
 */
final class ClientAddress
{
    /**
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server): string
    {
        $cloudflare = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));

        if ($cloudflare !== '' && filter_var($cloudflare, FILTER_VALIDATE_IP) !== false) {
            return $cloudflare;
        }

        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));

        return $remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false
            ? $remote
            : 'unknown';
    }
}