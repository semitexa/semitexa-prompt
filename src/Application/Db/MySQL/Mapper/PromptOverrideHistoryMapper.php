<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideHistoryResource;
use Semitexa\Prompt\Domain\Model\PromptOverrideVersion;

/** The bridge between the MySQL row and one entry on an override's timeline. */
#[AsMapper(resourceModel: PromptOverrideHistoryResource::class, domainModel: PromptOverrideVersion::class)]
final class PromptOverrideHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof PromptOverrideHistoryResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return new PromptOverrideVersion(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            promptId: $resourceModel->prompt_id,
            version: $resourceModel->version,
            system: $resourceModel->system,
            createdAt: $resourceModel->created_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PromptOverrideVersion || throw new \InvalidArgumentException('Unexpected domain model.');

        return new PromptOverrideHistoryResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            prompt_id: $domainModel->getPromptId(),
            version: $domainModel->getVersion(),
            system: $domainModel->getSystem(),
            created_at: $domainModel->getCreatedAt() ?? new \DateTimeImmutable(),
        );
    }
}
