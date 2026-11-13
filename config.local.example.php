<?php

declare(strict_types=1);

/**
 * Deployment template for config.local.php — copy this file to config.local.php
 * in the backend root (`~/boardsim-secrets/backend`) and fill in the absolute path.
 *
 * This file contains NO secret: it is only a pointer to where a secret lives. The
 * actual values stay in ~/boardsim-secrets/boardsim.env, owned by the service
 * account with mode 600, outside the document root.
 *
 * Both the web front controller and the cron retention job resolve the same
 * pointer (BoardSim\Infrastructure\EnvFile::bootstrap), so there is exactly one
 * copy of the credentials and no SetEnv needed.
 */

return [
    'secrets_file' => '/home/USER/boardsim-secrets/boardsim.env',
];