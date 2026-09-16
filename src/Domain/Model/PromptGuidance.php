<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Model;

/**
 * One attributable sentence of operator guidance appended to a prompt.
 *
 * The shape exists because the override layer is the wrong one for this. An
 * override replaces the WHOLE system text for (tenant, prompt): to honour one
 * sentence of feedback, something has to rewrite the entire prompt — and when
 * the feedback arrives from a chat room, that something is a model, rewriting
 * the very rules that exist BECAUSE a model cannot be trusted to keep them. An
 * operator editing a prompt by hand is a person taking responsibility; an
 * assistant editing its own constraints is grading its own homework.
 *
 * Guidance is additive instead: the shipped body is untouched, so
 * {@see \Semitexa\Prompt\Domain\Enum\OverrideDrift} keeps working and a
 * framework update that improves a prompt still reaches a tenant who has
 * guidance. And each row is individually removable — the thing an override
 * cannot do, where undoing one sentence means reverting a whole version.
 *
 * {@see $author} and {@see $reason} are not decoration. Six months on, the only
 * question anybody asks of a customised prompt is why it says what it says.
 */
final readonly class PromptGuidance
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $promptId,
        private string $body,
        private string $author,
        private int $position = 0,
        private string $reason = '',
        private ?string $scope = null,
        private bool $enabled = true,
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

    /**
     * Where this row sits in the sequence its tenant gave for this prompt.
     * Monotonic, assigned on write — never derived from the clock.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** The guidance itself, as the operator said it. */
    public function getBody(): string
    {
        return $this->body;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    /** Why it was asked for, in the requester's own words. May be empty. */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * An optional narrower key within the prompt — a page, a room, a campaign.
     * Null means it applies wherever the prompt is rendered.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Disabled rows stay in the table. Guidance is a record of what people asked
     * for; deleting it to silence it would lose the only account of why the
     * prompt behaves as it does.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            promptId: $this->promptId,
            body: $this->body,
            author: $this->author,
            position: $this->position,
            reason: $this->reason,
            scope: $this->scope,
            enabled: $enabled,
            createdAt: $this->createdAt,
        );
    }
}
