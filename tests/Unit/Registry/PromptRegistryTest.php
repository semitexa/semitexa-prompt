<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\FewShotProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;
use Semitexa\Prompt\Domain\Exception\PromptBodyMissingException;
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

    /**
     * Was "silently skipped" until 2026-09-14. A class that declares #[AsPrompt],
     * ships no template and implements no PromptDefinitionInterface has no body at
     * all — the same class of misconfiguration as a duplicate id, and skipping it
     * is what let two real prompts stay invisible for weeks behind a caller's
     * fallback. The message must name where it looked, or the operator is no
     * better off than with the old warning nobody had a logger to see.
     */
    public function testClassWithNoBodyAtAllIsAHardError(): void
    {
        $registry = new PromptRegistry();

        $this->expectException(PromptBodyMissingException::class);
        $this->expectExceptionMessageMatches('/Prompt "fix\.notadef".*has no body/');
        $this->expectExceptionMessageMatches('#resources/prompts/fix\.notadef\.twig#');

        $registry->buildFromClasses([FixtureNotADefinition::class]);
    }

    public function testTheHardErrorNamesTheRootsItSearched(): void
    {
        $registry = new PromptRegistry();

        try {
            $registry->buildFromClasses([FixtureNotADefinition::class]);
            self::fail('Expected PromptBodyMissingException.');
        } catch (PromptBodyMissingException $e) {
            self::assertStringContainsString('semitexa-prompt', $e->getMessage());
        }
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
