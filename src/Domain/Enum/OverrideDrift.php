<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Domain\Enum;

/**
 * How a tenant's prompt override stands against the text the framework ships
 * for that prompt today.
 *
 * An override wins over the catalog forever — that is what it is for. The cost
 * is that it is also a frozen copy: when a shipped prompt is rewritten, the
 * tenant who customised it keeps the old wording and nothing in their row says
 * so. This is the verdict that says it.
 *
 * The distinction between {@see self::Current} and {@see self::Untracked}
 * matters: an override written before fingerprints were recorded is *unknown*,
 * not unchanged, and reporting it as up to date would be a lie an operator acts
 * on.
 */
enum OverrideDrift: string
{
    /** The shipped text is byte-identical to what was overridden. */
    case Current = 'current';

    /** The framework has rewritten the prompt since — worth re-reading. */
    case ShippedChanged = 'shipped_changed';

    /** Written before base_hash existed; we cannot tell either way. */
    case Untracked = 'untracked';

    /** Overrides a prompt id no installed package ships. */
    case NotInCatalog = 'not_in_catalog';

    /**
     * @param string|null $baseHash      the fingerprint stamped when the override was written
     * @param string|null $shippedSystem the catalog's system text now, or null when nothing ships this id
     */
    public static function classify(?string $baseHash, ?string $shippedSystem): self
    {
        return match (true) {
            $shippedSystem === null => self::NotInCatalog,
            $baseHash === null => self::Untracked,
            $baseHash === self::fingerprint($shippedSystem) => self::Current,
            default => self::ShippedChanged,
        };
    }

    /** The fingerprint stored in `prompt_override.base_hash`. */
    public static function fingerprint(string $system): string
    {
        return hash('sha256', $system);
    }

    /** How the verdict reads on a terminal. */
    public function label(): string
    {
        return match ($this) {
            self::Current => 'up to date',
            self::ShippedChanged => 'shipped text changed',
            self::Untracked => 'unknown (pre-tracking)',
            self::NotInCatalog => 'not shipped',
        };
    }
}
