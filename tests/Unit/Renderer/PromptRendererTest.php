<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Renderer;

use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptRenderException;
use Semitexa\Prompt\Domain\Model\PromptMessage;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

final class PromptRendererTest extends TestCase
{
    private PromptRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PromptRenderer();
    }

    /**
     * @param array<string, PromptTemplate> $templates
     */
    private function repository(array $templates): PromptRepositoryInterface
    {
        return new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}

            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id];
            }

            public function tryGet(string $id): ?PromptTemplate
            {
                return $this->templates[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->templates[$id]);
            }

            public function all(): array
            {
                return array_values($this->templates);
            }
        };
    }

    public function testBindsVariables(): void
    {
        $template = new PromptTemplate(id: 'greet', system: 'Hi {{ name }}, welcome to {{ place }}.');

        $rendered = $this->renderer->renderTemplate($template, ['name' => 'Taras', 'place' => 'Semitexa'], $this->repository([]));

        self::assertSame('Hi Taras, welcome to Semitexa.', $rendered->system);
        self::assertSame(['name' => 'Taras', 'place' => 'Semitexa'], $rendered->variables);
        self::assertSame('greet', $rendered->promptId);
    }

    public function testExpandsPartialThenBindsVariablesAcrossIt(): void
    {
        $repo = $this->repository([
            'core.identity' => new PromptTemplate(id: 'core.identity', system: 'You are {{ assistant_name }}.'),
        ]);
        $template = new PromptTemplate(id: 'planner', system: "{{> core.identity }}\nPlan the task for {{ user }}.");

        $rendered = $this->renderer->renderTemplate($template, ['assistant_name' => 'Semi', 'user' => 'Taras'], $repo);

        self::assertSame("You are Semi.\nPlan the task for Taras.", $rendered->system);
    }

    public function testNestedPartials(): void
    {
        $repo = $this->repository([
            'a' => new PromptTemplate(id: 'a', system: 'A[{{> b }}]'),
            'b' => new PromptTemplate(id: 'b', system: 'B'),
        ]);
        $template = new PromptTemplate(id: 't', system: '{{> a }}');

        $rendered = $this->renderer->renderTemplate($template, [], $repo);

        self::assertSame('A[B]', $rendered->system);
    }

    public function testMissingVariableThrows(): void
    {
        $template = new PromptTemplate(id: 'greet', system: 'Hi {{ name }}');

        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessageMatches('/missing value.*name/');

        $this->renderer->renderTemplate($template, [], $this->repository([]));
    }

    public function testUnknownPartialThrows(): void
    {
        $template = new PromptTemplate(id: 't', system: '{{> does.not.exist }}');

        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessageMatches('/unknown prompt id/');

        $this->renderer->renderTemplate($template, [], $this->repository([]));
    }

    public function testPartialCycleThrows(): void
    {
        $repo = $this->repository([
            'cyc.a' => new PromptTemplate(id: 'cyc.a', system: '{{> cyc.b }}'),
            'cyc.b' => new PromptTemplate(id: 'cyc.b', system: '{{> cyc.a }}'),
        ]);
        $template = new PromptTemplate(id: 't', system: '{{> cyc.a }}');

        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessageMatches('/cycle/');

        $this->renderer->renderTemplate($template, [], $repo);
    }

    public function testFewShotMessagesAreVariableSubstituted(): void
    {
        $template = new PromptTemplate(
            id: 't',
            system: 'System {{ x }}',
            fewShot: [
                PromptMessage::user('Example input {{ x }}'),
                PromptMessage::assistant('Example output {{ y }}'),
            ],
        );

        $rendered = $this->renderer->renderTemplate($template, ['x' => '1', 'y' => '2'], $this->repository([]));

        self::assertSame('System 1', $rendered->system);
        self::assertCount(2, $rendered->messages);
        self::assertSame('Example input 1', $rendered->messages[0]->content);
        self::assertSame('Example output 2', $rendered->messages[1]->content);
    }

    public function testRenderStringResolvesPartialsAndVariables(): void
    {
        $repo = $this->repository([
            'core.identity' => new PromptTemplate(id: 'core.identity', system: 'You are {{ assistant_name }}.'),
        ]);

        $result = $this->renderer->renderString('{{> core.identity }} Go.', ['assistant_name' => 'Semi'], $repo);

        self::assertSame('You are Semi. Go.', $result);
    }

    public function testOnlyUsedVariablesAreReportedAsBound(): void
    {
        $template = new PromptTemplate(id: 't', system: 'Only {{ a }}');

        $rendered = $this->renderer->renderTemplate($template, ['a' => '1', 'unused' => '2'], $this->repository([]));

        self::assertSame(['a' => '1'], $rendered->variables);
    }
}
