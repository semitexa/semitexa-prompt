<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideResource;

/**
 * Self-mapping mapper for {@see PromptOverrideResource} — the resource is the
 * domain model, both directions are clone-passthroughs.
 */
#[AsMapper(
    resourceModel: PromptOverrideResource::class,
    domainModel: PromptOverrideResource::class,
)]
final class PromptOverrideMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof PromptOverrideResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PromptOverrideResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
