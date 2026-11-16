<?php

declare(strict_types=1);

/**
 * The zero-body redirect for the duplicate shell address.
 *
 * Apache's own 301 page is fine for humans and wrong for scanners: its default
 * body carries the status title, a link and the server signature, which puts a
 * short-Location redirect right on the line ZAP draws for "Big Redirect
 * Detected" (10044) — a redirect whose body is larger than its Location header
 * plus 300 bytes. Whatever that body contains is content the redirect is
 * "leaking" by definition. This file answers with the status and the Location
 * header and no body at all, so there is nothing to leak and nothing to flag.
 *
 * public/.htaccess routes the duplicate address here; every other header on
 * the response comes from the always-set rules in the same file. The guard
 * around the rule (THE_REQUEST) keeps the SPA fallback's internal rewrite to
 * app/index.html from re-entering this file, so / cannot redirect to itself.
 */

http_response_code(301);
header('Location: /');
