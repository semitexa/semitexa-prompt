<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;
use Semitexa\Prompt\Domain\Model\PromptMessage;

final class PromptRegistryTest extends TestCase
{
    public function testBuildsTemplatesFromAnnotatedClasses(): void
    {
        $registry = new PromptRegistry();

        $catalog = $registry->buildFromClasses([FixtureAlphaPrompt::class, FixtureFewShotPrompt::class]);

        self::assertArrayHasKey('fix.alpha', $catalog);
        self::assertSame('Alpha {{ x }}', $catalog['fix.alpha']->system);
        self::assertSame('partial', $catalog['fix.alpha']->channel);
        self::assertSame('An alpha fixture.', $catalog['fix.alpha']->description);
        self::assertSame(['x'], $catalog['fix.alpha']->variableNames());
    }

    public function testFewShotProviderMessagesAreCaptured(): void
    {
        $registry = new PromptRegistry();

        $catalog = $registry->buildFromClasses([FixtureFewShotPrompt::class]);

        self::assertCount(1, $catalog['fix.fewshot']->fewShot);
        self::assertSame('example', $catalog['fix.fewshot']->fewShot[0]->content);
    }

    public function testClassWithoutDefinitionInterfaceIsSkipped(): void
    {
        $registry = new PromptRegistry();

        $catalog = $registry->buildFromClasses([FixtureNotADefinition::class]);

        self::assertSame([], $catalog);
    }

    public function testDuplicateIdIsAHardError(): void
    {
        $registry = new PromptRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate prompt id "fix.alpha"/');

        $registry->buildFromClasses([FixtureAlphaPrompt::class, FixtureDuplicateAlphaPrompt::class]);
    }
}

#[AsPrompt(id: 'fix.alpha', channel: 'partial', description: 'An alpha fixture.')]
final class FixtureAlphaPrompt implements PromptDefinitionInterface
{
    public function system(): string
    {
        return 'Alpha {{ x }}';
    }
}

#[AsPrompt(id: 'fix.alpha')]
final class FixtureDuplicateAlphaPrompt implements PromptDefinitionInterface
{
    public function system(): string
    {
        return 'Another alpha';
    }
}

#[AsPrompt(id: 'fix.fewshot')]
final class FixtureFewShotPrompt implements PromptDefinitionInterface, FewShotProviderInterface
{
    public function system(): string
    {
        return 'System';
    }

    public function fewShot(): array
    {
        return [PromptMessage::user('example')];
    }
}

#[AsPrompt(id: 'fix.notadef')]
final class FixtureNotADefinition
{
    public function whatever(): string
    {
        return 'not a prompt';
    }
}
