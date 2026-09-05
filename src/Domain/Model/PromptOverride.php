<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

use Semitexa\Prompt\Domain\Enum\OverrideDrift;

/**
 * One tenant's replacement for a shipped prompt.
 *
 * An override wins over the catalog forever, which is what makes the
 * fingerprint here meaningful rather than bookkeeping: it says which shipped
 * text this replacement was written against, and comparing it to what ships now
 * is the only way an operator learns their copy has gone stale.
 */
final readonly class PromptOverride
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $promptId,
        private string $system,
        /** Fingerprint of the shipped text this was written against, or null when unrecorded. */
        private ?string $baseHash = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getPromptId(): string
    {
        return $this->promptId;
    }

    public function getSystem(): string
    {
        return $this->system;
    }

    public function getBaseHash(): ?string
    {
        return $this->baseHash;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** How this override stands against the text shipped for that prompt now. */
    public function driftAgainst(?string $shippedSystem): OverrideDrift
    {
        return OverrideDrift::classify($this->baseHash, $shippedSystem);
    }
}
