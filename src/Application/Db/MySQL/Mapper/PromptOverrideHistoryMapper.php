<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideHistoryResource;

/**
 * Self-mapping mapper for {@see PromptOverrideHistoryResource}.
 */
#[AsMapper(
    resourceModel: PromptOverrideHistoryResource::class,
    domainModel: PromptOverrideHistoryResource::class,
)]
final class PromptOverrideHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof PromptOverrideHistoryResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PromptOverrideHistoryResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
