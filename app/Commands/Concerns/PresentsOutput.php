<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\DurationFormatter;

/**
 * Terminal presentation shared by every storage command.
 *
 * Visual language:
 *   - One header line per operation: a coloured badge plus an optional DRY RUN
 *     marker, followed by aligned label/value detail rows.
 *   - Result badges: SUCCESS (green), PARTIAL (yellow), FAILED (red),
 *     DRY RUN (yellow), INFO (blue) — always foreground-on-background so they
 *     stay legible on light and dark terminals alike.
 *   - Elapsed time on completion, measured from the header.
 */
trait PresentsOutput
{
    private const LABEL_WIDTH = 13;

    private ?float $operationStartedAt = null;

    /**
     * Render the operation header and start the clock for this operation.
     */
    protected function renderOperationHeader(string $title, bool $dryRun = false): void
    {
        $this->operationStartedAt = microtime(true);

        $this->newLine();
        $this->line(
            '  <bg=cyan;fg=black;options=bold> '.strtoupper($title).' </>'
            .($dryRun ? '  <bg=yellow;fg=black;options=bold> DRY RUN </>' : '')
        );
        $this->newLine();
    }

    /**
     * Aligned detail row: "Label         value".
     */
    protected function renderDetail(string $label, string $value): void
    {
        $this->line(sprintf(
            '  <fg=gray>%s</>%s',
            str_pad($label, self::LABEL_WIDTH),
            $value,
        ));
    }

    /**
     * Thin rule used to separate a header from its results.
     */
    protected function renderSeparator(int $width = 56): void
    {
        $this->line('  <fg=gray>'.str_repeat('─', max(8, $width)).'</>');
    }

    protected function renderSuccess(string $message): void
    {
        $this->renderBadge('SUCCESS', 'green', 'black', $message);
    }

    protected function renderFailed(string $message): void
    {
        $this->renderBadge('FAILED', 'red', 'white', $message);
    }

    protected function renderPartial(string $message): void
    {
        $this->renderBadge('PARTIAL', 'yellow', 'black', $message);
    }

    protected function renderDryRun(string $message): void
    {
        $this->renderBadge('DRY RUN', 'yellow', 'black', $message);
    }

    /**
     * Neutral outcome — the command did what was asked and there was nothing
     * to do. Never dressed up as a dry run or an error.
     */
    protected function renderInfo(string $message): void
    {
        $this->renderBadge('INFO', 'blue', 'white', $message);
    }

    /**
     * Inline error with a red marker. Used for every user-facing failure so the
     * error style is identical across commands.
     */
    protected function renderError(string $message): void
    {
        foreach (preg_split('/\R/', rtrim($message)) ?: [$message] as $index => $line) {
            $this->line($index === 0
                ? '  <fg=red;options=bold>✖</>  '.$line
                : '     '.$line);
        }
    }

    /**
     * Alias kept for call sites that predate renderError().
     */
    protected function renderFailure(string $message): void
    {
        $this->renderError($message);
    }

    protected function renderHint(string $message): void
    {
        $this->line('  <fg=gray>'.$message.'</>');
    }

    /**
     * Elapsed time since the header was rendered, or null when no header was
     * drawn (so a command never reports a bogus duration).
     */
    protected function elapsed(): ?string
    {
        if ($this->operationStartedAt === null) {
            return null;
        }

        return DurationFormatter::human(microtime(true) - $this->operationStartedAt);
    }

    private function renderBadge(string $label, string $background, string $foreground, string $message): void
    {
        $this->line(sprintf(
            '  <bg=%s;fg=%s;options=bold> %s </>  %s',
            $background,
            $foreground,
            $label,
            $message,
        ));
    }
}
