<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

/**
 * Resolves a prompt's Twig body from a path declared relative to its owner.
 *
 * `#[AsPrompt]` names its body with an owner-relative path
 * (`resources/prompts/{id}.twig`). "Owner" has two legal shapes in Semitexa and
 * only one of them is anchored by a `composer.json`:
 *
 *   - a **package** — `packages/semitexa-x/`, always carrying a composer.json;
 *   - an **application module** — `src/modules/<Name>/`, for which
 *     MODULE_STRUCTURE.md lists composer.json among the OPTIONAL metadata files.
 *
 * Anchoring on composer.json alone — what this class replaced — silently resolved
 * a module's prompt against the PROJECT root, found nothing there, and dropped
 * the prompt from the catalog entirely: absent from `prompt:list`, unreachable by
 * `prompt:override`, and visible only as whatever fallback the caller had kept.
 * Measured in a consumer 2026-09-14: two correctly-placed module prompts were
 * invisible for weeks, and an otherwise-pointless module composer.json fixed both.
 *
 * So both roots are tried, module first. That order is not a preference: it is the
 * same outermost-wins ranking {@see \Semitexa\Core\Discovery\SourceOrigin} applies
 * to every other override contest, where `src/modules/` (400) outranks the project
 * (300). A module that does carry a composer.json yields one root, not two.
 */
final class PromptBodyLocator
{
    private const MODULES_SEGMENT = '/src/modules/';

    /**
     * @var array<string, string|null> memoized package roots by directory
     *
     * Static on purpose, and safe to be: this is a pure directory -> directory
     * map of the filesystem layout, carrying no request or tenant state, so the
     * coroutine-shared-static hazard does not apply. It has to outlive the
     * instance because PromptRenderer builds a fresh PromptRegistry — and so a
     * fresh locator — for every bound-prompt render. Per-instance, the
     * composer.json walk ran again on each one.
     */
    private static array $packageRoots = [];

    /**
     * The body text for an owner-relative $templateFile, given the file the
     * `#[AsPrompt]` class was declared in. Null when no candidate root holds it.
     */
    public function load(string $classFile, string $templateFile): ?string
    {
        foreach ($this->rootsFor($classFile) as $root) {
            $path = $root . '/' . $templateFile;
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    /**
     * Candidate owner roots for a class file, nearest owner first. Public because
     * it is the half worth asserting on: a wrong root is why a prompt disappears,
     * and an error message that cannot name the roots it searched is the silence
     * this class exists to remove.
     *
     * @return list<string>
     */
    public function rootsFor(string $classFile): array
    {
        $dir = \dirname($classFile);

        $roots = [];
        $module = $this->moduleRootOf($dir);
        if ($module !== null) {
            $roots[] = $module;
        }

        $package = $this->packageRootOf($dir);
        if ($package !== null && !\in_array($package, $roots, true)) {
            $roots[] = $package;
        }

        return $roots;
    }

    /**
     * The `src/modules/<Name>/` directory this path sits under, if any — the same
     * shape `ModuleRegistry::discoverLocalModules()` registers and `SourceOrigin`
     * ranks. A marker file is deliberately NOT required: needing one is the defect.
     */
    private function moduleRootOf(string $dir): ?string
    {
        $needle = self::MODULES_SEGMENT;
        $at = strrpos($dir . '/', $needle);
        if ($at === false) {
            return null;
        }

        $after = substr($dir . '/', $at + \strlen($needle));
        $name = strstr($after, '/', true);
        if ($name === false || $name === '') {
            return null;
        }

        return substr($dir, 0, $at) . $needle . $name;
    }

    /** Walk up from $dir to the nearest directory containing composer.json. */
    private function packageRootOf(string $dir): ?string
    {
        if (\array_key_exists($dir, self::$packageRoots)) {
            return self::$packageRoots[$dir];
        }

        $current = $dir;
        while (true) {
            if (is_file($current . '/composer.json')) {
                return self::$packageRoots[$dir] = $current;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                return self::$packageRoots[$dir] = null;
            }
            $current = $parent;
        }
    }
}
