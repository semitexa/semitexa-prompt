<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\PromptBodyLocator;

/**
 * The regression this file exists for: a module prompt resolving against the
 * PROJECT root and vanishing from the catalog. Every case builds the two shapes
 * on disk — a module with no composer.json, and a package with one — because the
 * bug was entirely about which marker file the walk stopped at.
 */
final class PromptBodyLocatorTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/prompt-body-locator-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/project/src/modules/Social/src/Application/Prompt', 0o777, true);
        file_put_contents($this->tmp . '/project/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testFindsAModuleBodyWhenTheModuleHasNoComposerJson(): void
    {
        $this->writeBody('project/src/modules/Social/resources/prompts', 'social.topic.twig', 'Module body');

        $body = (new PromptBodyLocator())->load($this->moduleClassFile(), 'resources/prompts/social.topic.twig');

        self::assertSame('Module body', $body);
    }

    public function testModuleRootIsOfferedBeforeTheProjectRoot(): void
    {
        $roots = (new PromptBodyLocator())->rootsFor($this->moduleClassFile());

        self::assertSame(
            [$this->tmp . '/project/src/modules/Social', $this->tmp . '/project'],
            $roots,
        );
    }

    public function testModuleBodyWinsOverASameNamedProjectBody(): void
    {
        $this->writeBody('project/resources/prompts', 'social.topic.twig', 'Project body');
        $this->writeBody('project/src/modules/Social/resources/prompts', 'social.topic.twig', 'Module body');

        $body = (new PromptBodyLocator())->load($this->moduleClassFile(), 'resources/prompts/social.topic.twig');

        self::assertSame('Module body', $body);
    }

    public function testStillFallsBackToTheComposerAnchoredRoot(): void
    {
        $this->writeBody('project/resources/prompts', 'social.topic.twig', 'Project body');

        $body = (new PromptBodyLocator())->load($this->moduleClassFile(), 'resources/prompts/social.topic.twig');

        self::assertSame('Project body', $body);
    }

    public function testAModuleCarryingAComposerJsonYieldsOneRootNotTwo(): void
    {
        file_put_contents($this->tmp . '/project/src/modules/Social/composer.json', '{}');

        $roots = (new PromptBodyLocator())->rootsFor($this->moduleClassFile());

        self::assertSame([$this->tmp . '/project/src/modules/Social'], $roots);
    }

    public function testAPackageOutsideModulesResolvesAsBefore(): void
    {
        mkdir($this->tmp . '/packages/semitexa-x/src/Application/Prompt', 0o777, true);
        file_put_contents($this->tmp . '/packages/semitexa-x/composer.json', '{}');
        $this->writeBody('packages/semitexa-x/resources/prompts', 'x.twig', 'Package body');

        $locator = new PromptBodyLocator();
        $classFile = $this->tmp . '/packages/semitexa-x/src/Application/Prompt/XPrompt.php';

        self::assertSame([$this->tmp . '/packages/semitexa-x'], $locator->rootsFor($classFile));
        self::assertSame('Package body', $locator->load($classFile, 'resources/prompts/x.twig'));
    }

    public function testMissingBodyReturnsNull(): void
    {
        self::assertNull(
            (new PromptBodyLocator())->load($this->moduleClassFile(), 'resources/prompts/nothing.twig'),
        );
    }

    private function moduleClassFile(): string
    {
        return $this->tmp . '/project/src/modules/Social/src/Application/Prompt/TopicPrompt.php';
    }

    private function writeBody(string $relativeDir, string $file, string $contents): void
    {
        $dir = $this->tmp . '/' . $relativeDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir . '/' . $file, $contents);
    }
}
