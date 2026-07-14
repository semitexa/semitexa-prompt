<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

/**
 * Per-tenant prompt-override lookup.
 *
 * Consulted by {@see \Semitexa\Prompt\Application\Service\LayeredPromptRepository}
 * BEFORE the code-shipped catalog: a tenant may override any prompt's system
 * text, and everything it does not override falls through to the shared catalog.
 * Returns null when the current tenant has no override for the prompt id —
 * including the single-tenant 'default' context, which simply has none.
 *
 * Implementations resolve the CURRENT tenant themselves (coroutine-local), so
 * the caller passes only the prompt id.
 */
interface PromptOverrideProviderInterface
{
    /** The overriding system text for a prompt id, or null when not overridden. */
    public function override(string $promptId): ?string;

    /**
     * The current tenant's full override map (prompt id => system text). Used by
     * the layered repository's `all()` and by tooling that lists overrides.
     *
     * @return array<string, string>
     */
    public function all(): array;
}
