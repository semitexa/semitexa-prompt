<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * Read access to the prompt catalog. The default implementation
 * ({@see \Semitexa\Prompt\Application\Service\PromptRegistry}) discovers
 * code-defined templates via `#[AsPrompt]`; a future DB-backed override layer
 * (per-tenant, live-edited) can implement the same contract and be layered in
 * front, mirroring how semitexa-locale overlays DB translations on a code
 * catalog.
 */
interface PromptRepositoryInterface
{
    /**
     * @throws \Semitexa\Prompt\Domain\Exception\PromptNotFoundException when no template has this id
     */
    public function get(string $id): PromptTemplate;

    public function tryGet(string $id): ?PromptTemplate;

    public function has(string $id): bool;

    /**
     * @return list<PromptTemplate>
     */
    public function all(): array;
}
