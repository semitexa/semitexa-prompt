<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Contract;

use Semitexa\Prompt\Domain\Model\PromptMessage;

/**
 * Optional companion to {@see PromptDefinitionInterface}: a prompt definition
 * that also ships few-shot examples. The registry folds these into the
 * template's {@see \Semitexa\Prompt\Domain\Model\PromptTemplate::$fewShot}.
 */
interface FewShotProviderInterface
{
    /**
     * @return list<PromptMessage>
     */
    public function fewShot(): array;
}
