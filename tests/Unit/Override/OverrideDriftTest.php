<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Override;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Prompt\Domain\Enum\OverrideDrift;

/**
 * An override outlives the prompt it was copied from. These are the four
 * answers the operator can get about that, and the two that must never be
 * confused: "unchanged" and "we cannot tell".
 */
final class OverrideDriftTest extends TestCase
{
    #[Test]
    public function an_override_written_against_the_current_text_is_up_to_date(): void
    {
        $shipped = 'You are {{ prompt.assistantName }}.';

        self::assertSame(
            OverrideDrift::Current,
            OverrideDrift::classify(OverrideDrift::fingerprint($shipped), $shipped),
        );
    }

    #[Test]
    public function rewriting_the_shipped_prompt_makes_the_override_stale(): void
    {
        $before = 'You are {{ prompt.assistantName }}.';
        $after = 'You are {{ prompt.assistantName }}, warm and quick-witted.';

        self::assertSame(
            OverrideDrift::ShippedChanged,
            OverrideDrift::classify(OverrideDrift::fingerprint($before), $after),
        );
    }

    /**
     * Even a single trailing space is a different prompt to a model — the
     * fingerprint is over the bytes, not a normalised form.
     */
    #[Test]
    public function whitespace_alone_counts_as_a_change(): void
    {
        $before = 'Be concise.';

        self::assertSame(
            OverrideDrift::ShippedChanged,
            OverrideDrift::classify(OverrideDrift::fingerprint($before), 'Be concise. '),
        );
    }

    #[Test]
    public function a_row_written_before_tracking_is_unknown_rather_than_unchanged(): void
    {
        $drift = OverrideDrift::classify(null, 'Anything at all.');

        self::assertSame(OverrideDrift::Untracked, $drift);
        self::assertNotSame(OverrideDrift::Current, $drift, 'reporting an untracked row as current would be a lie');
    }

    #[Test]
    public function an_override_of_a_prompt_nothing_ships_is_reported_as_such(): void
    {
        self::assertSame(OverrideDrift::NotInCatalog, OverrideDrift::classify(null, null));
        self::assertSame(
            OverrideDrift::NotInCatalog,
            OverrideDrift::classify(OverrideDrift::fingerprint('gone'), null),
            'a package that stopped shipping the prompt is not "up to date"',
        );
    }

    #[Test]
    public function every_verdict_has_wording_for_a_terminal(): void
    {
        foreach (OverrideDrift::cases() as $case) {
            self::assertNotSame('', $case->label(), "missing label for {$case->value}");
        }
    }
}
