<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Raised for any YAML construct outside the allowlisted subset.
 *
 * engine/RULE_SCHEMA.md requires deterministic rejection rather than a silent
 * default, so this exception is always fatal to a bundle load.
 */
final class YamlError extends RuntimeException
{
}
