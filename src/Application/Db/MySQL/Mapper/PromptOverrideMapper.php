<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideResource;
use Semitexa\Prompt\Domain\Model\PromptOverride;

/** The bridge between the MySQL row and the override the layered repository serves. */
#[AsMapper(resourceModel: PromptOverrideResource::class, domainModel: PromptOverride::class)]
final class PromptOverrideMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof PromptOverrideResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new PromptOverride(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            promptId: $resourceModel->prompt_id,
            system: $resourceModel->system,
            baseHash: $resourceModel->base_hash,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PromptOverride || throw new \InvalidArgumentException('Unexpected domain model.');

        return new PromptOverrideResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            prompt_id: $domainModel->getPromptId(),
            system: $domainModel->getSystem(),
            base_hash: $domainModel->getBaseHash(),
            updated_at: $domainModel->getUpdatedAt() ?? new \DateTimeImmutable(),
        );
    }
}
