<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

/**
 * One saved version of an override — the timeline an operator reverts along.
 *
 * Append-only by design: reverting re-applies an old body as a NEW version
 * rather than rewinding, so the record of what was tried survives.
 */
final readonly class PromptOverrideVersion
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $promptId,
        private int $version,
        private string $system,
        private ?\DateTimeImmutable $createdAt = null,
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getSystem(): string
    {
        return $this->system;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
