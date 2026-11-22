<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Sandbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Application\Service\LayeredPromptRepository;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Application\Service\PromptSandbox;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Semitexa\Prompt\Domain\Contract\PromptOverrideProviderInterface;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Exception\PromptRenderException;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * An operator-written prompt override is not code. These tests go through the
 * path a real override takes — override provider → {@see LayeredPromptRepository}
 * → {@see PromptRenderer} — and pin that it can read and shape the prompt's
 * data, and cannot reach a PHP callable or anything else outside the sandbox.
 */
final class PromptOverrideSandboxTest extends TestCase
{
    /** This class, as a template writes it inside a string. */
    private const PROBE = 'Semitexa\\\\Prompt\\\\Tests\\\\Unit\\\\Sandbox\\\\PromptOverrideSandboxTest';

    /** How many times a template got PHP to call something it must not reach. */
    public static int $hits = 0;

    /**
     * A PHP callable a template can name. It stands in for `system`: a payload
     * that reaches it has reached PHP, and the test can see that it did.
     */
    public static function hit(mixed ...$args): string
    {
        self::$hits++;

        return 'HIT';
    }

    public static function compare(mixed $a, mixed $b): int
    {
        self::$hits++;

        return 0;
    }

    /**
     * A bound prompt with everything a template could reach for: getters and a
     * public property (what shipped prompts read), a method that takes an
     * argument, a static method and an object that is not the prompt (what an
     * override must not reach).
     */
    private static function prompt(): BoundPromptInterface
    {
        return new class implements BoundPromptInterface {
            public string $label = 'public-label';

            /** State no template may read: there is no getter for it. */
            private string $internal = 'INTERNAL-STATE';

            public function promptId(): string
            {
                return 'probe';
            }

            public function assistantName(): string
            {
                return 'Solomiia';
            }

            /** @return list<array{name: string, searchable: bool, sortable: bool}> */
            public function fields(): array
            {
                return [
                    ['name' => 'title', 'searchable' => true, 'sortable' => false],
                    ['name' => 'price', 'searchable' => false, 'sortable' => true],
                ];
            }

            public function shout(string $word): string
            {
                PromptOverrideSandboxTest::$hits++;

                return strtoupper($this->internal === '' ? '' : $word);
            }

            /** @return \ArrayObject<string, int> */
            public function helper(): \ArrayObject
            {
                return new \ArrayObject(['a' => 1]);
            }

            public static function build(): string
            {
                PromptOverrideSandboxTest::$hits++;

                return 'static';
            }
        };
    }

    private const SHIPPED = 'Shipped for {{ prompt.assistantName }}.';

    protected function setUp(): void
    {
        self::$hits = 0;
    }

    public function testTheProbeCallableIsReachableFromPhp(): void
    {
        // Guards the payloads below against a typo in the callable's name: a
        // name PHP cannot call would be "refused" for the wrong reason.
        $name = stripslashes(self::PROBE) . '::hit';
        self::assertIsCallable($name);
        self::assertSame('HIT', $name('a'));
        self::assertSame(1, self::$hits);
    }

    /**
     * @param array<string, string> $shipped
     * @param array<string, string> $overrides
     */
    private function layered(array $shipped, array $overrides): LayeredPromptRepository
    {
        $templates = [];
        foreach ($shipped as $id => $system) {
            $templates[$id] = new PromptTemplate(id: $id, system: $system);
        }

        $catalog = new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}

            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id] ?? throw PromptNotFoundException::forId($id);
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

        $provider = new class($overrides) implements PromptOverrideProviderInterface {
            /** @param array<string, string> $overrides */
            public function __construct(private array $overrides) {}

            public function override(string $promptId): ?string
            {
                return $this->overrides[$promptId] ?? null;
            }

            public function all(): array
            {
                return $this->overrides;
            }
        };

        return (new LayeredPromptRepository())->withCatalog($catalog)->withOverrides($provider);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedOverrides(): iterable
    {
        $hit = '"' . self::PROBE . '::hit"';
        $compare = '"' . self::PROBE . '::compare"';

        // A callable NAME where Twig expects an arrow function: PHP would call it.
        yield 'map with a callable name' => ['{{ ["a"]|map(' . $hit . ')|join }}'];
        yield 'filter with a callable name' => ['{{ ["a"]|filter(' . $hit . ')|join }}'];
        yield 'sort with a callable name' => ['{{ ["b", "a"]|sort(' . $compare . ')|join }}'];
        yield 'reduce with a callable name' => ['{{ ["a"]|reduce(' . $hit . ') }}'];
        yield 'find with a callable name' => ['{{ ["a"]|find(' . $hit . ') }}'];
        yield 'has some with a callable name' => ['{{ ["a"] has some ' . $hit . ' ? "y" : "n" }}'];
        yield 'has every with a callable name' => ['{{ ["a"] has every ' . $hit . ' ? "y" : "n" }}'];
        yield 'callable name from a variable' => ['{% set f = ' . $hit . ' %}{{ ["a"]|map(f)|join }}'];
        yield 'callable as an array pair' => ['{{ ["a"]|map(["' . self::PROBE . '", "hit"])|join }}'];
        yield 'the triage payload' => ['{{ ["PROBE-%d"]|map("sprintf")|join }}'];
        yield 'the task payload' => ['{{ ["SEMITEXA_SSTI_RCE_PROOF"]|filter("printf") }}'];

        // Reaching past the prompt's own data.
        yield 'a method that takes an argument' => ['{{ prompt.shout("x") }}'];
        yield 'a static method' => ['{{ prompt.build }}'];
        yield 'a method on another object' => ['{{ prompt.helper.count }}'];
        yield 'a method with arguments on another object' => ['{{ prompt.helper.append("x") }}'];
        yield 'a magic method' => ['{{ prompt.__construct }}'];

        // Functions, filters and tags that are not on the allowlist.
        yield 'constant()' => ['{{ constant("PHP_VERSION") }}'];
        yield 'source()' => ['{{ source("p") }}'];
        yield 'attribute()' => ['{{ attribute(prompt, "shout", ["x"]) }}'];
        yield 'range()' => ['{{ range(1, 3)|join }}'];
        yield 'an unlisted filter' => ['{{ "a"|convert_encoding("UTF-8", "ISO-8859-1") }}'];
        yield 'constant test' => ['{{ 1 is constant("PHP_INT_SIZE") ? "y" : "n" }}'];
        yield 'macro tag' => ['{% macro m() %}x{% endmacro %}ok'];
        yield 'import tag' => ['{% import "p" as m %}ok'];
        yield 'extends tag' => ['{% extends "shipped" %}'];
        yield 'include tag' => ['{% include "shipped" %}'];
        yield 'sandbox tag' => ['{% sandbox %}{% include "shipped" %}{% endsandbox %}'];
    }

    #[DataProvider('refusedOverrides')]
    public function testAnOverrideIsRefused(string $override): void
    {
        $repo = $this->layered(['p' => self::SHIPPED, 'shipped' => 'Partial.'], ['p' => $override]);

        try {
            $rendered = (new PromptRenderer())->render('p', ['prompt' => self::prompt()], $repo);
            self::fail('The override rendered: ' . $rendered->system);
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('override was refused by the prompt sandbox', $e->getMessage());
            // The message names the prompt and what was refused, and says the
            // override is still there: the operator is told, nothing vanishes.
            self::assertStringContainsString('"p"', $e->getMessage());
            self::assertStringContainsString('kept as written', $e->getMessage());
        }

        self::assertSame(0, self::$hits, 'The override reached PHP before it was refused.');
        // Refused is not removed: the repository still serves the override.
        self::assertSame($override, $repo->get('p')->system);
    }

    public function testARefusedOverrideIsNotReplacedByTheShippedPrompt(): void
    {
        $repo = $this->layered(['p' => self::SHIPPED], ['p' => '{{ ["a"]|map("strtoupper")|join }}']);

        $this->expectException(PromptRenderException::class);
        $this->expectExceptionMessage('The callable passed to the "map" filter must be a Closure in sandbox mode');

        (new PromptRenderer())->render('p', ['prompt' => self::prompt()], $repo);
    }

    public function testAnOverrideOfAnIdTheCatalogDoesNotHaveIsSandboxedToo(): void
    {
        $repo = $this->layered([], ['only.override' => '{{ ["a"]|map("' . self::PROBE . '::hit")|join }}']);

        try {
            (new PromptRenderer())->render('only.override', [], $repo);
            self::fail('The override rendered.');
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
        }
        self::assertSame(0, self::$hits);
    }

    public function testAnOverrideIncludedByAShippedPromptIsSandboxed(): void
    {
        $repo = $this->layered(
            ['host' => "Host. {{ include('part') }}", 'part' => 'Shipped part.'],
            ['part' => '{{ ["a"]|map("' . self::PROBE . '::hit")|join }}'],
        );

        try {
            (new PromptRenderer())->render('host', [], $repo);
            self::fail('The included override rendered.');
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
        }
        self::assertSame(0, self::$hits);
    }

    public function testAnOverrideIsStillSandboxedOnceTwigHasItCompiled(): void
    {
        // The second render finds the bodies already compiled (a class in this
        // process, a cache file for the next one), so Twig reads no source for
        // them. The sandbox must not depend on that read.
        $overrides = ['part' => '{{ ["a"]|map("' . self::PROBE . '::hit")|join }}', 'p' => '{{ prompt.shout("x") }}'];
        $shipped = ['host' => "Host. {{ include('part') }}", 'part' => 'Shipped part.', 'p' => self::SHIPPED];

        foreach ([1, 2, 3] as $attempt) {
            foreach (['host', 'p'] as $id) {
                try {
                    (new PromptRenderer())->render($id, ['prompt' => self::prompt()], $this->layered($shipped, $overrides));
                    self::fail(sprintf('Render %d of "%s" was not refused.', $attempt, $id));
                } catch (PromptRenderException $e) {
                    self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
                }
            }
        }
        self::assertSame(0, self::$hits);
    }

    public function testAnOverrideIsRefusedAgainAfterAShippedRenderOfTheSameBody(): void
    {
        // The cached-template case: one process, the same prompt id and the
        // very same body, first as an operator's override, then as shipped
        // code, then as the override again. Whatever Twig kept from the shipped
        // render must not be what the override runs as.
        $body = '{{ prompt.shout("x") }} {{ ["a"]|map("' . self::PROBE . '::hit")|join }}';
        $asOverride = $this->layered(['p' => self::SHIPPED], ['p' => $body]);
        $asShipped = $this->layered(['p' => $body], []);
        $renderer = new PromptRenderer();

        $refused = function () use ($renderer, $asOverride): void {
            self::$hits = 0;
            try {
                $rendered = $renderer->render('p', ['prompt' => self::prompt()], $asOverride);
                self::fail('The override rendered: ' . $rendered->system);
            } catch (PromptRenderException $e) {
                self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
            }
            self::assertSame(0, self::$hits);
        };

        $refused();
        self::assertSame('X HIT', $renderer->render('p', ['prompt' => self::prompt()], $asShipped)->system);
        $refused();
        self::assertSame('X HIT', $renderer->render('p', ['prompt' => self::prompt()], $asShipped)->system);
        $refused();
    }

    public function testAShippedPromptIncludedByAnOverrideIsSandboxedWithIt(): void
    {
        // An override cannot borrow a shipped prompt's trust by including it:
        // what an operator-written body includes runs in its sandbox.
        $repo = $this->layered(
            ['p' => self::SHIPPED, 'tool' => '{{ prompt.shout(word) }}'],
            ['p' => "{% set word = 'x' %}{{ include('tool') }}"],
        );

        try {
            $rendered = (new PromptRenderer())->render('p', ['prompt' => self::prompt()], $repo);
            self::fail('The override rendered: ' . $rendered->system);
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
        }
        self::assertSame(0, self::$hits);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serialisingOverrides(): iterable
    {
        yield 'an object' => ['{{ helper|json_encode }}'];
        yield 'an object nested inside an array' => ['{{ box|json_encode }}'];
        yield 'a JsonSerializable object' => ['{{ serialisable|json_encode }}'];
        yield 'a JsonSerializable object nested inside an array' => ['{{ [1, {"deep": [serialisable]}]|json_encode }}'];
        yield 'the bound prompt' => ['{{ prompt|json_encode }}'];
        yield 'through apply' => ['{% apply json_encode %}{{ helper.secret }}{% endapply %}'];
    }

    /**
     * `json_encode` is PHP's own: handed an object it prints every public
     * property, or whatever jsonSerialize() returns, and a filter's argument
     * never meets the sandbox's property and method rules. So an override does
     * not get the filter at all — `{{ helper.secret }}` is refused, and
     * `{{ helper|json_encode }}` must not be the way round it.
     */
    #[DataProvider('serialisingOverrides')]
    public function testAnOverrideCannotSerialiseAnObjectItMayNotRead(string $override): void
    {
        $repo = $this->layered(['p' => self::SHIPPED], ['p' => $override]);
        $variables = [
            'prompt' => self::prompt(),
            'helper' => (object) ['secret' => 'TOKEN'],
            'box' => ['inner' => ['deeper' => (object) ['secret' => 'TOKEN']]],
            'serialisable' => new class implements \JsonSerializable {
                /** @return array{secret: string} */
                public function jsonSerialize(): array
                {
                    PromptOverrideSandboxTest::$hits++;

                    return ['secret' => 'TOKEN'];
                }
            },
        ];

        try {
            $rendered = (new PromptRenderer())->render('p', $variables, $repo);
            self::fail('The override rendered: ' . $rendered->system);
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
        }
        self::assertSame(0, self::$hits, 'jsonSerialize() was called.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function objectCastingOverrides(): iterable
    {
        // Spelled out, not read from PromptSandbox: the list of what Twig casts
        // is this test's claim; testEveryObjectCastingFilterIsGuardedAndAllowed()
        // holds the sandbox to it.
        foreach (['join'] as $filter) {
            yield $filter . ': a plain object' => [$filter, '{{ helper|' . $filter . ' }}'];
            yield $filter . ': a plain object, with arguments' => [$filter, "{{ helper|" . $filter . "(', ', ' and ') }}"];
            yield $filter . ': the bound prompt' => [$filter, '{{ prompt|' . $filter . ' }}'];
            yield $filter . ': a date' => [$filter, '{{ date|' . $filter . ' }}'];
            // An array holding an object, on the paths that hand the object
            // itself to the filter, which is where Twig would cast it.
            yield $filter . ': an object taken from a list' => [$filter, '{{ [helper]|first|' . $filter . ' }}'];
            yield $filter . ': an object taken from a mapping' => [$filter, '{{ {"k": helper}|last|' . $filter . ' }}'];
            yield $filter . ': the bound prompt found in a list' => [$filter, '{{ [prompt]|find(v => true)|' . $filter . ' }}'];
            yield $filter . ': an object kept by default' => [$filter, '{{ helper|default("none")|' . $filter . ' }}'];
            yield $filter . ': each object of a list' => [$filter, '{{ [helper, prompt]|map(v => v|' . $filter . ')|first }}'];
        }
    }

    /**
     * Twig's `join` casts what is not iterable with `(array)`: for an object
     * that is every property, private ones too, and no policy is asked. The
     * sandboxed environment has its own `join`, which refuses the object.
     */
    #[DataProvider('objectCastingOverrides')]
    public function testAnOverrideCannotCastAnObjectToItsState(string $filter, string $override): void
    {
        $repo = $this->layered(['p' => self::SHIPPED], ['p' => $override]);
        $variables = [
            'prompt' => self::prompt(),
            'helper' => (object) ['secret' => 'TOKEN'],
            'date' => new \DateTimeImmutable('2020-01-02 03:04:05'),
        ];

        try {
            $rendered = (new PromptRenderer())->render('p', $variables, $repo);
            self::fail('The override rendered: ' . $rendered->system);
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
            self::assertStringContainsString('The "' . $filter . '" filter is not allowed on a ', $e->getMessage());
        }
    }

    public function testEveryObjectCastingFilterIsGuardedAndAllowed(): void
    {
        $guarded = array_map(
            static fn(\Twig\TwigFilter $f): string => $f->getName(),
            (new PromptSandbox())->guards()->getFilters(),
        );

        self::assertSame(['join'], $guarded);
        self::assertSame(['join'], PromptSandbox::OBJECT_CASTING_FILTERS);
        self::assertSame([], array_diff(PromptSandbox::OBJECT_CASTING_FILTERS, PromptSandbox::ALLOWED_FILTERS));
    }

    public function testAGuardedFilterGivesAnOverrideWhatItGivesAShippedPrompt(): void
    {
        // Text, numbers, arrays and iterators: the guard hands them to Twig's
        // own filter, so both sides print the same bytes.
        $body = "{{ ['a', 'b', 'c']|join }}|{{ ['a', 'b', 'c']|join(', ') }}|{{ ['a', 'b', 'c']|join(', ', ' and ') }}"
            . "|{{ {'x': 1, 'y': 2.5}|join('-') }}|{{ []|join(',') }}|{{ ['only']|join(', ', ' and ') }}"
            . "|{{ 'text'|join(',') }}|{{ 7|join }}|{{ none|join(',') }}|{{ true|join }}|{{ items|join('+') }}"
            . "|{{ [1, null, 'z', false]|join('/') }}";
        $variables = ['items' => new \ArrayIterator(['i', 'j'])];

        $shipped = (new PromptRenderer())->render('p', $variables, $this->layered(['p' => $body], []));
        $variables = ['items' => new \ArrayIterator(['i', 'j'])];
        $override = (new PromptRenderer())->render('p', $variables, $this->layered(['p' => self::SHIPPED], ['p' => $body]));

        self::assertSame('abc|a, b, c|a, b and c|1-2.5||only|text|7||1|i+j|1//z/', $shipped->system);
        self::assertSame($shipped->system, $override->system);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function joinCallForms(): iterable
    {
        yield 'no argument' => ["{{ ['a', 'b', 'c']|join }}", 'abc'];
        yield 'positional glue' => ["{{ ['a', 'b', 'c']|join(', ') }}", 'a, b, c'];
        yield 'positional glue and and' => ["{{ ['a', 'b', 'c']|join(', ', ' and ') }}", 'a, b and c'];
        yield 'named glue' => ["{{ ['a', 'b', 'c']|join(glue=', ') }}", 'a, b, c'];
        yield 'named glue, colon form' => ["{{ ['a', 'b', 'c']|join(glue: ', ') }}", 'a, b, c'];
        yield 'named and alone' => ["{{ ['a', 'b', 'c']|join(and=' and ') }}", 'ab and c'];
        yield 'named glue and and' => ["{{ ['a', 'b', 'c']|join(glue=', ', and=' and ') }}", 'a, b and c'];
        yield 'named and before glue' => ["{{ ['a', 'b', 'c']|join(and=' and ', glue=', ') }}", 'a, b and c'];
        yield 'positional glue, named and' => ["{{ ['a', 'b', 'c']|join(', ', and=' or ') }}", 'a, b or c'];
        yield 'named glue on a mapping' => ["{{ {'x': 1, 'y': 2}|join(glue='-') }}", '1-2'];
        yield 'named glue on an iterator' => ["{{ items|join(glue='+') }}", 'i+j'];
        yield 'named and on one item' => ["{{ ['only']|join(and=' and ') }}", 'only'];
        yield 'named glue on text' => ["{{ 'text'|join(glue=',') }}", 'text'];
        yield 'a third positional argument, which Twig drops' => ["{{ ['a', 'b']|join(', ', ' and ', 'x') }}", 'a and b'];
        yield 'through apply, named' => ["{% apply upper %}{{ ['a', 'b']|join(glue=', ') }}{% endapply %}", 'A, B'];
        yield 'inside an arrow, named' => ["{{ [['a', 'b'], ['c']]|map(v => v|join(glue='+'))|join(and=' & ') }}", 'a+b & c'];
    }

    /**
     * The sandbox's `join` takes every call Twig's own does — positional,
     * named, `and` alone — and prints what the trusted environment prints.
     */
    #[DataProvider('joinCallForms')]
    public function testTheGuardedJoinTakesEveryCallFormTwigsJoinDoes(string $body, string $expected): void
    {
        $shipped = (new PromptRenderer())->render('p', ['items' => new \ArrayIterator(['i', 'j'])], $this->layered(['p' => $body], []));
        $override = (new PromptRenderer())->render('p', ['items' => new \ArrayIterator(['i', 'j'])], $this->layered(['p' => self::SHIPPED], ['p' => $body]));

        self::assertSame($expected, $shipped->system);
        self::assertSame($shipped->system, $override->system);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function joinCallsTwigRefuses(): iterable
    {
        yield 'an unknown named argument' => ["{{ ['a']|join(separator=', ') }}"];
        yield 'glue given twice' => ["{{ ['a']|join(', ', glue='; ') }}"];
    }

    #[DataProvider('joinCallsTwigRefuses')]
    public function testTheGuardedJoinRefusesWhatTwigsJoinRefuses(string $body): void
    {
        foreach ([$this->layered(['p' => $body], []), $this->layered(['p' => self::SHIPPED], ['p' => $body])] as $repo) {
            try {
                $rendered = (new PromptRenderer())->render('p', [], $repo);
                self::fail('Rendered: ' . $rendered->system);
            } catch (PromptRenderException $e) {
                self::assertStringContainsString('Cannot render prompt "p"', $e->getMessage());
            }
        }
    }

    public function testNamedArgumentsDoNotGetAnObjectPastTheGuard(): void
    {
        foreach (["{{ helper|join(glue=', ') }}", "{{ prompt|join(and=' and ') }}", "{{ [helper]|first|join(and=' and ', glue=', ') }}"] as $override) {
            $repo = $this->layered(['p' => self::SHIPPED], ['p' => $override]);
            try {
                $rendered = (new PromptRenderer())->render('p', ['prompt' => self::prompt(), 'helper' => (object) ['secret' => 'TOKEN']], $repo);
                self::fail('The override rendered: ' . $rendered->system);
            } catch (PromptRenderException $e) {
                self::assertStringContainsString('The "join" filter is not allowed on a ', $e->getMessage());
            }
        }
    }

    public function testAShippedPromptStillSerialises(): void
    {
        $repo = $this->layered(['p' => '{{ helper|json_encode }}'], []);

        $rendered = (new PromptRenderer())->render('p', ['helper' => (object) ['a' => 1]], $repo);

        self::assertSame('{"a":1}', $rendered->system);
    }

    public function testABoundPromptRenderedThroughTheRepositoryIsSandboxed(): void
    {
        // The production call shape: render($boundPrompt, [], $layeredRepository).
        $repo = $this->layered(['probe' => self::SHIPPED], ['probe' => '{{ prompt.shout("x") }}']);

        try {
            (new PromptRenderer())->render(self::prompt(), [], $repo);
            self::fail('The override rendered.');
        } catch (PromptRenderException $e) {
            self::assertStringContainsString('refused by the prompt sandbox', $e->getMessage());
        }
        self::assertSame(0, self::$hits);
    }

    public function testAnOverrideReadsAndShapesThePromptData(): void
    {
        $override = <<<'TWIG'
            {%- set greeting = 'Hello from ' ~ prompt.assistantName -%}
            {{ greeting|upper }} ({{ prompt.label }}).
            {% if prompt.fields is not empty %}Fields:
            {% for f in prompt.fields %}- {{ f.name }}: {{ [f.searchable ? 'searchable' : null, f.sortable ? 'sortable' : null]|filter(v => v)|join(', ') }}
            {% endfor %}{% endif %}Names: {{ prompt.fields|map(f => f.name)|sort((a, b) => a <=> b)|join(', ') }}. Count: {{ prompt.fields|length }}.
            Total: {{ [1, 2, 3]|reduce((carry, v) => carry + v, 0) }}. {{ include('shipped') }} {{ guidance|default('no guidance') }}
            TWIG;
        $repo = $this->layered(['p' => self::SHIPPED, 'shipped' => 'Partial for {{ prompt.assistantName }}.'], ['p' => $override]);

        $rendered = (new PromptRenderer())->render('p', ['prompt' => self::prompt(), 'guidance' => ''], $repo);

        self::assertSame(
            "HELLO FROM SOLOMIIA (public-label).\n"
            . "Fields:\n- title: searchable\n- price: sortable\n"
            . "Names: price, title. Count: 2.\n"
            . 'Total: 6. Partial for Solomiia. no guidance',
            $rendered->system,
        );
    }

    public function testAShippedPromptIsNotSandboxed(): void
    {
        // The same constructs, shipped in code, render as they always did: the
        // sandbox is for what an operator wrote, and only for that.
        $repo = $this->layered(['p' => '{{ prompt.shout("x") }} {{ range(1, 3)|join }}'], []);

        $rendered = (new PromptRenderer())->render('p', ['prompt' => self::prompt()], $repo);

        self::assertSame('X 123', $rendered->system);
    }

    public function testAShippedPromptMayIncludeAnOverrideAndKeepItsOwnTrust(): void
    {
        $repo = $this->layered(
            ['host' => "{{ range(1, 3)|join }} {{ include('part') }}", 'part' => 'Shipped part.'],
            ['part' => 'Overridden {{ prompt.assistantName|upper }}.'],
        );

        $rendered = (new PromptRenderer())->render('host', ['prompt' => self::prompt()], $repo);

        self::assertSame('123 Overridden SOLOMIIA.', $rendered->system);
    }
}
