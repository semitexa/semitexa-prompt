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
     * all — and skipping it is what let two real prompts stay invisible for weeks
     * behind a caller's fallback.
     *
     * It is loud where it is somebody's problem — asking for THAT id by name —
     * and not where it is everybody's. Throwing out of the catalog build instead
     * would take down every LLM render in the application AND `prompt:list`, the
     * one tool an operator would reach for to find out why, on every call
     * forever: the catalog memo is only assigned on success.
     */
    public function testAskingForABodylessPromptByNameThrows(): void
    {
        $registry = new PromptRegistry();
        $registry->buildFromClasses([FixtureNotADefinition::class]);

        $this->expectException(PromptBodyMissingException::class);
        $this->expectExceptionMessageMatches('/Prompt "fix\.notadef".*has no body/');
        $this->expectExceptionMessageMatches('#resources/prompts/fix\.notadef\.twig#');

        $registry->tryGet('fix.notadef');
    }

    public function testTheFailureNamesTheRootsItSearched(): void
    {
        $registry = new PromptRegistry();
        $registry->buildFromClasses([FixtureNotADefinition::class]);

        $broken = $registry->brokenIds();

        self::assertArrayHasKey('fix.notadef', $broken);
        // The package root, wherever it is installed: packages/semitexa-prompt
        // in the monorepo, vendor/semitexa/prompt in an app.
        self::assertStringContainsString(dirname(__DIR__, 3), $broken['fix.notadef']->getMessage());
    }

    public function testOneBodylessPromptDoesNotTakeTheRestOfTheCatalogWithIt(): void
    {
        // The regression this shape exists for: a renamed prompt id whose .twig
        // was not renamed used to be one missing prompt, then briefly became
        // every prompt in the application.
        $registry = new PromptRegistry();

        $catalog = $registry->buildFromClasses([FixtureAlphaPrompt::class, FixtureNotADefinition::class]);

        self::assertArrayHasKey('fix.alpha', $catalog);
        self::assertSame('Alpha {{ x }}', $registry->tryGet('fix.alpha')?->system);
        self::assertCount(1, $registry->all(), 'a bodyless prompt is not listed as if it worked');
    }

    public function testABodylessClassDoesNotPoisonAnIdAnotherClassClaimsValidly(): void
    {
        // Reported on the PR: the quarantine recorded the id, and tryGet() then
        // threw over a template sitting right there in the catalog. A claimed id
        // is a claimed id whether or not the claimant has a body, so this is the
        // duplicate-id case and is reported as one, naming both classes.
        $registry = new PromptRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate prompt id "fix\.notadef"/');

        $registry->buildFromClasses([FixtureNotADefinition::class, FixtureValidNotADefTwin::class]);
    }

    public function testTheSameCollisionIsCaughtInEitherDeclarationOrder(): void
    {
        $registry = new PromptRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate prompt id "fix\.notadef"/');

        $registry->buildFromClasses([FixtureValidNotADefTwin::class, FixtureNotADefinition::class]);
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

/** A healthy class claiming the same id as the bodyless one above. */
#[AsPrompt(id: 'fix.notadef')]
final class FixtureValidNotADefTwin implements PromptDefinitionInterface
{
    public function system(): string
    {
        return 'I have a body.';
    }
}
