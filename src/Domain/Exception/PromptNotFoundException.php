<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Exception;

use RuntimeException;

final class PromptNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No prompt registered with id "%s".', $id));
    }
}
