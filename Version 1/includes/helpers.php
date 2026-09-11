<?php

declare(strict_types=1);

function escape_html(mixed $untrustedValue): string
{
    return htmlspecialchars(
        (string) $untrustedValue,
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    );
}
