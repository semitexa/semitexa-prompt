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
        self::assertSame('greet', $rendered->promptId);
    }

    public function testTwigConditionalsInPrompts(): void
    {
        $template = new PromptTemplate(id: 't', system: 'Hi{% if name %} {{ name }}{% endif %}.');

        self::assertSame('Hi Solomiia.', $this->renderer->renderTemplate($template, ['name' => 'Solomiia'], $this->repository([]))->system);
        self::assertSame('Hi.', $this->renderer->renderTemplate($template, ['name' => ''], $this->repository([]))->system);
    }

    public function testIncludeComposesAndSharesContext(): void
    {
        $repo = $this->repository([
            'core.identity' => new PromptTemplate(id: 'core.identity', system: 'You are {{ assistant_name }}.'),
        ]);
        $template = new PromptTemplate(id: 'planner', system: "{{ include('core.identity') }}\nPlan for {{ user }}.");

        $rendered = $this->renderer->renderTemplate($template, ['assistant_name' => 'Solomiia', 'user' => 'Taras'], $repo);

        self::assertSame("You are Solomiia.\nPlan for Taras.", $rendered->system);
    }

    public function testMissingVariableFailsClosed(): void
    {
        $template = new PromptTemplate(id: 'greet', system: 'Hi {{ name }}');

        $this->expectException(PromptRenderException::class);
        $this->renderer->renderTemplate($template, [], $this->repository([]));
    }

    public function testUnknownIncludeThrows(): void
    {
        $template = new PromptTemplate(id: 't', system: "{{ include('does.not.exist') }}");

        $this->expectException(PromptRenderException::class);
        $this->renderer->renderTemplate($template, [], $this->repository([]));
    }

    public function testFewShotMessagesAreRendered(): void
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
        self::assertSame('Example input 1', $rendered->messages[0]->content);
        self::assertSame('Example output 2', $rendered->messages[1]->content);
    }

    public function testSafeNormalizationStripsTrailingSpaceAndCollapsesBlankLines(): void
    {
        $template = new PromptTemplate(id: 't', system: "Line one   \n\n\n\nLine two\t\n");

        // trailing whitespace stripped, 4 blank lines -> 1 blank line, edges trimmed
        self::assertSame("Line one\n\nLine two", $this->renderer->renderTemplate($template, [], $this->repository([]))->system);
    }

    public function testJsonBracesAreLiteralNotTwig(): void
    {
        $template = new PromptTemplate(id: 't', system: 'Reply {"type":"answer","x":{}} with {{ n }}.');

        self::assertSame('Reply {"type":"answer","x":{}} with 1.', $this->renderer->renderTemplate($template, ['n' => '1'], $this->repository([]))->system);
    }

    public function testRenderStringResolvesIncludesAndVariables(): void
    {
        $repo = $this->repository([
            'core.identity' => new PromptTemplate(id: 'core.identity', system: 'You are {{ assistant_name }}.'),
        ]);

        $result = $this->renderer->renderString("{{ include('core.identity') }} Go.", ['assistant_name' => 'Solomiia'], $repo);

        self::assertSame('You are Solomiia. Go.', $result);
    }
}
