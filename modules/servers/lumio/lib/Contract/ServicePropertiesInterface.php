<?php

declare(strict_types=1);

namespace Lumio\Whmcs\Contract;

interface ServicePropertiesInterface
{
    public function get(string $name): ?string;

    /** @param array<string, int|string> $values */
    public function save(array $values): void;
}
