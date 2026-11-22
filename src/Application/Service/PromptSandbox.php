<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Closure;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Traversable;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\Extension\ExtensionInterface;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\TwigFilter;

/**
 * The sandbox every operator-written prompt body renders in.
 *
 * A prompt body shipped in code is trusted like any other code. A body an
 * operator typed into the prompt editor (a DB override) is not: plain Twig lets
 * a template hand a string to `map` / `filter` / `sort` / `reduce` and have PHP
 * call it, which turns "may edit a prompt" into "may run PHP on the host".
 *
 * So an operator-written body is never given to the environment shipped prompts
 * render in. It renders in a Twig environment of its own, sandboxed as a whole
 * ({@see self::extension()}), together with every prompt it includes;
 * {@see PromptTwigLoader} keeps the two apart. A code-shipped prompt renders
 * exactly as it always did.
 *
 * Needs Twig 3.28: earlier versions have sandbox bypasses this relies on being
 * closed, and no allowlist for tests.
 *
 * What an operator-written body may do:
 *  - tags, filters, functions and tests from the lists below — the ones that
 *    read and shape text. `map`, `filter`, `sort`, `reduce` and `find` stay,
 *    because Twig itself refuses anything but an arrow function (`v => v`) for
 *    them in a sandboxed environment; a callable NAME is an error;
 *  - read the bound `prompt` object the way a shipped body does: its public
 *    properties and its public methods that take no parameters (the getters).
 *    Nothing can be passed to a method, and no other object can be called.
 *
 * Everything else is refused with a Twig security error, which
 * {@see PromptRenderer} reports as a refused override.
 */
final class PromptSandbox implements SecurityPolicyInterface
{
    public const ALLOWED_TAGS = ['if', 'for', 'set', 'with', 'apply'];

    /**
     * No `json_encode`: it is PHP's own, and given an object it prints every
     * public property, or whatever jsonSerialize() returns. A filter's argument
     * does not go through {@see checkPropertyAllowed()} or
     * {@see checkMethodAllowed()}, so the filter would read what the rules
     * below refuse. A filter that serialises objects does not belong here.
     */
    public const ALLOWED_FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'default', 'filter', 'find', 'first', 'format', 'join',
        'keys', 'last', 'length', 'lower', 'map', 'merge', 'number_format', 'reduce',
        'replace', 'reverse', 'round', 'slice', 'sort', 'split', 'title', 'trim', 'upper',
    ];

    public const ALLOWED_FUNCTIONS = ['include', 'max', 'min', 'cycle'];

    public const ALLOWED_TESTS = [
        'defined', 'divisible by', 'empty', 'even', 'iterable', 'mapping', 'none', 'null', 'odd', 'same as', 'sequence',
    ];

    private readonly SecurityPolicy $allowlist;

    public function __construct()
    {
        $this->allowlist = new SecurityPolicy(
            allowedTags: self::ALLOWED_TAGS,
            allowedFilters: self::ALLOWED_FILTERS,
            allowedFunctions: self::ALLOWED_FUNCTIONS,
            allowedTests: self::ALLOWED_TESTS,
        );
        // Strict: nothing is allowed implicitly (`extends`, `use`, `block()`,
        // `attribute()` and every test otherwise are, until Twig 4).
        $this->allowlist->setStrict(true);
    }

    /** The Twig extension that sandboxes a whole environment under this policy. */
    public function extension(): SandboxExtension
    {
        return new SandboxExtension($this, true);
    }

    /**
     * Twig filters that turn an object into its state without asking the
     * policy: `join` casts anything that is not iterable with `(array)`, which
     * for an object is every property it has, private ones included.
     *
     * It is the only one. Every other construct on the lists above either
     * refuses an object that is not iterable, hands it back untouched (and
     * printing it is checked), or reads it through the policy, as `column` does.
     */
    public const OBJECT_CASTING_FILTERS = ['join'];

    /**
     * Same-name replacements for {@see self::OBJECT_CASTING_FILTERS}, for the
     * sandboxed environment only. Each refuses an object Twig would cast and
     * otherwise calls Twig's own filter, so text and arrays come out as before.
     *
     * A replacement declares the very parameters Twig's filter does. Twig binds
     * a template's named arguments (`join(glue=', ')`) to the parameter names of
     * the callable it is given, so a catch-all `...$arguments` would turn every
     * named call into "Unknown argument".
     */
    public function guards(): ExtensionInterface
    {
        /**
         * One per name in OBJECT_CASTING_FILTERS, each given Twig's own filter.
         *
         * @var array<string, Closure(Closure): Closure> $factories
         */
        $factories = [
            // Twig: CoreExtension::join($value, $glue = '', $and = null)
            'join' => static fn(Closure $original): Closure => static function (mixed $value, mixed $glue = '', mixed $and = null) use ($original): mixed {
                self::refuseObject('join', $value);

                return $original($value, $glue, $and);
            },
        ];

        $guards = [];
        foreach ((new CoreExtension())->getFilters() as $filter) {
            $name = $filter->getName();
            if (!in_array($name, self::OBJECT_CASTING_FILTERS, true)) {
                continue;
            }
            $factory = $factories[$name] ?? throw new LogicException(sprintf('The prompt sandbox has no guard for the Twig "%s" filter.', $name));
            $original = $filter->getCallable();
            if (!is_callable($original) || $filter->needsEnvironment() || $filter->needsContext() || $filter->needsCharset() || $filter->isVariadic()) {
                // The guard passes the template's arguments straight through; a
                // filter Twig calls with more than those is not one it can stand in for.
                throw new LogicException(sprintf('The prompt sandbox cannot guard the Twig "%s" filter of this Twig version.', $name));
            }
            $original = Closure::fromCallable($original);

            $guard = $factory($original);
            if (self::signature($guard) !== self::signature($original)) {
                // Twig's filter gained, lost or renamed a parameter: the guard
                // would no longer accept every call the filter does.
                throw new LogicException(sprintf('The prompt sandbox guard for the Twig "%s" filter does not match this Twig version.', $name));
            }
            $guards[] = new TwigFilter($name, $guard);
        }
        if (count($guards) !== count(self::OBJECT_CASTING_FILTERS)) {
            throw new LogicException('The prompt sandbox found no Twig filter to guard for one of: ' . implode(', ', self::OBJECT_CASTING_FILTERS) . '.');
        }

        return new class($guards) extends AbstractExtension {
            /** @param list<TwigFilter> $guards */
            public function __construct(private readonly array $guards) {}

            public function getFilters(): array
            {
                return $this->guards;
            }
        };
    }

    private static function refuseObject(string $filter, mixed $value): void
    {
        if (is_object($value) && !$value instanceof Traversable) {
            throw new SecurityNotAllowedFilterError(
                sprintf('The "%s" filter is not allowed on a "%s" object: it would read the object\'s properties.', $filter, $value::class),
                $filter,
            );
        }
    }

    /**
     * What Twig binds a filter call to: each parameter's name, whether it may
     * be left out and, if so, what it then is.
     *
     * @return list<array{string, bool, mixed}>
     */
    private static function signature(Closure $callable): array
    {
        $signature = [];
        foreach ((new ReflectionFunction($callable))->getParameters() as $parameter) {
            $optional = $parameter->isDefaultValueAvailable();
            $signature[] = [$parameter->getName(), $optional, $optional ? $parameter->getDefaultValue() : null];
        }

        return $signature;
    }

    /**
     * @param string[] $tags
     * @param string[] $filters
     * @param string[] $functions
     * @param string[] $tests
     */
    public function checkSecurity($tags, $filters, $functions, array $tests = []): void
    {
        $this->allowlist->checkSecurity($tags, $filters, $functions, $tests);
    }

    /**
     * @param object $obj
     * @param string $method
     */
    public function checkMethodAllowed($obj, $method): void
    {
        // `{% set x %}…{% endset %}` captures its text as a Markup; printing it
        // is printing a string.
        if ($obj instanceof Markup && strtolower($method) === '__tostring') {
            return;
        }

        if ($obj instanceof BoundPromptInterface && !str_starts_with($method, '__') && method_exists($obj, $method)) {
            $ref = new ReflectionMethod($obj, $method);
            if ($ref->isPublic() && !$ref->isStatic() && $ref->getNumberOfParameters() === 0) {
                return;
            }
        }

        throw new SecurityNotAllowedMethodError(
            sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $obj::class),
            $obj::class,
            $method,
        );
    }

    /**
     * @param object $obj
     * @param string $property
     */
    public function checkPropertyAllowed($obj, $property): void
    {
        if ($obj instanceof BoundPromptInterface && property_exists($obj, $property)) {
            $ref = new ReflectionProperty($obj, $property);
            if ($ref->isPublic() && !$ref->isStatic()) {
                return;
            }
        }

        throw new SecurityNotAllowedPropertyError(
            sprintf('Reading "%s" property on a "%s" object is not allowed.', $property, $obj::class),
            $obj::class,
            $property,
        );
    }
}
