<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SecurityPolicyInterface;

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
