<?php

declare(strict_types=1);

/**
 * Resolve a configured role name to its database identifier.
 */
function role_id(string $name): int
{
    $roleStatement = database()->prepare(
        "SELECT id FROM roles WHERE name = ? LIMIT 1",
    );
    $roleStatement->execute([$name]);
    $roleId = (int) $roleStatement->fetchColumn();

    if ($roleId < 1) {
        throw new RuntimeException(
            "The requested account role is not configured.",
        );
    }

    return $roleId;
}
