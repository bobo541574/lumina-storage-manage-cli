<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parsed outcome of an rclone run launched with --use-json-log.
 *
 * rclone writes one JSON object per line to stderr. The final line carrying a
 * "stats" key holds the authoritative counters for the run, which is the only
 * reliable way to report how many objects were actually transferred, skipped
 * or failed — exit codes alone cannot distinguish "copied 4370" from
 * "copied nothing because everything already existed".
 */
final readonly class RcloneStats
{
    /** @param array<int, string> $errorMessages */
    public function __construct(
        public bool $present = false,
        public int $transfers = 0,
        public int $checks = 0,
        public int $errors = 0,
        public int $deletes = 0,
        public int $bytes = 0,
        public int $totalBytes = 0,
        public float $elapsed = 0.0,
        public array $errorMessages = [],
    ) {}

    /**
     * Parse the JSON log emitted on stderr. Non-JSON lines (rclone falls back
     * to plain text for very early failures) are collected as error messages so
     * nothing is silently swallowed.
     */
    public static function parse(string $output): self
    {
        $stats = null;
        $raw = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                $raw[] = ['message' => $line, 'object' => ''];

                continue;
            }

            if (isset($decoded['stats']) && is_array($decoded['stats'])) {
                $stats = $decoded['stats'];

                continue;
            }

            if (($decoded['level'] ?? '') !== 'error' || ! isset($decoded['msg'])) {
                continue;
            }

            $message = self::normalise((string) $decoded['msg']);

            // "Attempt 2/3 failed with 4370 errors" restates the per-object
            // failures that are already collected; keeping it just doubled the
            // list once per retry.
            if ($message === '' || preg_match('/^Attempt \d+\/\d+ failed with /', $message) === 1) {
                continue;
            }

            // rclone logs a few listing artefacts at error level, ignores them
            // and carries on without counting them in stats.errors: an S3
            // directory-marker object whose key IS the directory holding it
            // trips "Entry doesn't belong in directory ... - ignoring" on every
            // run over that prefix. Repeating what rclone ignored invented a
            // failure the transfer never had.
            if (str_ends_with($message, '- ignoring')) {
                continue;
            }

            $raw[] = ['message' => $message, 'object' => trim((string) ($decoded['object'] ?? ''))];
        }

        $messages = self::collapse($raw);

        if ($stats === null) {
            return new self(errorMessages: $messages);
        }

        if (($stats['lastError'] ?? '') !== '' && $messages === []) {
            $messages[] = self::normalise((string) $stats['lastError']);
        }

        return new self(
            present: true,
            transfers: (int) ($stats['transfers'] ?? 0),
            checks: (int) ($stats['checks'] ?? 0),
            errors: (int) ($stats['errors'] ?? 0),
            deletes: (int) ($stats['deletes'] ?? 0),
            bytes: (int) ($stats['bytes'] ?? 0),
            totalBytes: (int) ($stats['totalBytes'] ?? 0),
            elapsed: (float) ($stats['elapsedTime'] ?? 0),
            errorMessages: $messages,
        );
    }

    /**
     * Fraction of the run that has completed, or null while the total is
     * unknown (rclone has not finished listing the source yet).
     */
    public function progress(): ?float
    {
        if ($this->totalBytes <= 0) {
            return null;
        }

        return min(1.0, $this->bytes / $this->totalBytes);
    }

    /**
     * Strip the parts of an S3/HTTP error that differ on every single request,
     * so the same failure repeated across thousands of objects (and across
     * rclone's retries of each one) collapses to a single line.
     */
    private static function normalise(string $message): string
    {
        $message = trim($message);
        $message = (string) preg_replace('/,?\s*(RequestID|HostID|RequestId|HostId|extended request id|request id)\s*:\s*[^,\n]*/i', '', $message);

        return trim((string) preg_replace('/\s{2,}/', ' ', $message), " \t\n\r,");
    }

    /**
     * Group identical failures, reporting the count and one example object
     * rather than one line per object.
     *
     * @param  array<int, array{message: string, object: string}>  $entries
     * @return array<int, string>
     */
    private static function collapse(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $key = $entry['message'];

            if (! isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'objects' => []];
            }

            $groups[$key]['count']++;

            if ($entry['object'] !== '') {
                $groups[$key]['objects'][$entry['object']] = true;
            }
        }

        $messages = [];

        foreach ($groups as $message => $group) {
            $objects = count($group['objects']);

            if ($objects === 1) {
                $messages[] = array_key_first($group['objects']).': '.$message;

                continue;
            }

            if ($objects === 0) {
                $messages[] = $message;

                continue;
            }

            $messages[] = sprintf(
                '%s  (%d objects, e.g. %s)',
                $message,
                $objects,
                array_key_first($group['objects']),
            );
        }

        return $messages;
    }
}
