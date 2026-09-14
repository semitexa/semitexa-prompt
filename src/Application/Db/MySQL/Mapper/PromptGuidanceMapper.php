<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptGuidanceResource;
use Semitexa\Prompt\Domain\Model\PromptGuidance;

/** The bridge between the MySQL row and one piece of operator guidance. */
#[AsMapper(resourceModel: PromptGuidanceResource::class, domainModel: PromptGuidance::class)]
final class PromptGuidanceMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof PromptGuidanceResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return new PromptGuidance(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            promptId: $resourceModel->prompt_id,
            body: $resourceModel->body,
            author: $resourceModel->author,
            position: $resourceModel->position,
            reason: $resourceModel->reason,
            scope: $resourceModel->scope,
            enabled: $resourceModel->enabled,
            createdAt: $resourceModel->created_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PromptGuidance || throw new \InvalidArgumentException('Unexpected domain model.');

        return new PromptGuidanceResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            prompt_id: $domainModel->getPromptId(),
            scope: $domainModel->getScope(),
            position: $domainModel->getPosition(),
            body: $domainModel->getBody(),
            author: $domainModel->getAuthor(),
            reason: $domainModel->getReason(),
            enabled: $domainModel->isEnabled(),
            created_at: $domainModel->getCreatedAt() ?? new \DateTimeImmutable(),
        );
    }
}
