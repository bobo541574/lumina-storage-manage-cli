<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\RemoteDiscovery;
use App\Contracts\StorageDriver;
use App\DTOs\ListingEntry;
use App\DTOs\ListingResult;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;
use App\DTOs\TransferStatus;
use App\Exceptions\ObjectNotFoundException;
use App\Exceptions\RemoteNotFoundException;
use App\Exceptions\StorageException;
use App\Exceptions\TransferException;
use App\Exceptions\VisibilityException;
use App\Support\ProcessResult;
use App\Support\RcloneProcess;
use App\Support\RcloneStats;
use App\Support\StorageLogger;
use Symfony\Component\Process\Process;

/**
 * Rclone-backed storage driver.
 *
 * Translates application-level semantics into rclone invocations. All processes
 * are executed with array arguments (no shell interpolation). Rclone handles
 * any transfer where at least one end is a remote; LocalStorageDriver covers
 * the pure local-to-local case.
 */
final class RcloneStorageDriver implements RemoteDiscovery, StorageDriver
{
    /**
     * rclone availability and the remote list are re-checked constantly by
     * assertAvailable()/assertRemote(). Memoising them per instance keeps a
     * multi-thousand-object transfer from spawning one `rclone version` and
     * one `rclone listremotes` subprocess per object.
     */
    private ?bool $available = null;

    /** @var array<int, string>|null */
    private ?array $remotes = null;

    /** @var (\Closure(RcloneStats): void)|null */
    private ?\Closure $progressHandler = null;

    /** @var array<string, string|null> */
    private array $configuredAcls = [];

    /**
     * The canned ACLs S3 accepts on a write. Anything else is rejected by the
     * API — on a CopyObject as an opaque "400 InvalidArgument".
     */
    private const CANNED_ACLS = [
        'private',
        'public-read',
        'public-read-write',
        'authenticated-read',
        'aws-exec-read',
        'bucket-owner-read',
        'bucket-owner-full-control',
    ];

    public function __construct(
        private readonly RcloneProcess $process,
        private readonly StorageLogger $logger,
    ) {}

    public function name(): string
    {
        return 'rclone';
    }

    public function isAvailable(): bool
    {
        return $this->available ??= $this->process->run(['version'])->successful();
    }

    /**
     * Receive live rclone counters while a transfer runs, so the command layer
     * can render progress instead of leaving the terminal silent for minutes.
     */
    public function onProgress(?callable $handler): void
    {
        $this->progressHandler = $handler === null ? null : \Closure::fromCallable($handler);
    }

    /**
     * Drop the memoised remote list after the rclone config has been changed.
     */
    public function flushRemotes(): void
    {
        $this->remotes = null;
    }

    /**
     * Whether the remote is configured in rclone.
     */
    public function remoteExists(string $remote): bool
    {
        return in_array($remote, $this->remotes(), true);
    }

    /**
     * Whether the bucket exists on the remote.
     */
    public function bucketExists(string $remote, string $bucket): bool
    {
        return in_array($bucket, $this->buckets($remote), true);
    }

    /**
     * Names of all configured remotes (without trailing ":").
     *
     * @return array<int, string>
     */
    public function remotes(): array
    {
        if ($this->remotes !== null) {
            return $this->remotes;
        }

        $result = $this->process->run(['listremotes']);

        $remotes = [];

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && str_ends_with($line, ':')) {
                $remotes[] = substr($line, 0, -1);
            }
        }

        return $this->remotes = $remotes;
    }

    /**
     * Bucket names visible at the remote root.
     *
     * @return array<int, string>
     */
    public function buckets(string $remote): array
    {
        $result = $this->process->run(['lsd', $remote.':']);

        $buckets = [];

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if ($parts !== false && $parts !== [] && trim($line) !== '') {
                $buckets[] = (string) end($parts);
            }
        }

        return $buckets;
    }

    public function list(StoragePath $path, bool $recursive = false, bool $directories = true, bool $files = true): ListingResult
    {
        $this->assertAvailable();
        $this->assertRemote($path);

        $entries = [];

        if ($files) {
            $entries = array_merge($entries, $this->listType($path, $recursive, true));
        }

        if ($directories) {
            $entries = array_merge($entries, $this->listType($path, $recursive, false));
        }

        // rclone exits 0 with no output for a prefix that holds nothing, so an
        // empty listing is the only signal an object store gives that the path
        // is not there. Reporting it as a successful empty listing hid typos
        // behind exit code 0; the local driver already raises not-found here.
        if ($entries === [] && ! $path->isBucketRoot()) {
            throw new ObjectNotFoundException(sprintf(
                'Nothing found at "%s". The prefix is empty or does not exist.',
                $path->toDisplayString(),
            ));
        }

        return new ListingResult($entries);
    }

    private function listType(StoragePath $path, bool $recursive, bool $filesOnly): array
    {
        $arguments = [
            'lsf',
            $path->toRclonePath(),
            '--format',
            'sp',
            '--separator',
            "\t",
        ];

        if ($recursive) {
            $arguments[] = '-R';
        }

        $arguments[] = $filesOnly ? '--files-only' : '--dirs-only';

        $result = $this->process->run($arguments);

        if (! $result->successful()) {
            throw new StorageException(
                sprintf('Unable to list "%s": %s', $path->toDisplayString(), $this->explain($result->stderr)),
            );
        }

        return $this->parseListing($result->stdout, $filesOnly);
    }

    /**
     * Whether anything is stored at the path.
     *
     * `rclone lsf` exits 0 with EMPTY output for a prefix or object that does
     * not exist on an object store — the bucket itself is what it resolves, so
     * the exit code alone says nothing about the key. Existence therefore has
     * to be decided on the listing being non-empty; treating exit 0 as "exists"
     * makes every destination look occupied and silently skips whole transfers.
     */
    public function exists(StoragePath $path): bool
    {
        $this->assertAvailable();

        if ($path->isLocal()) {
            return file_exists($this->localPath($path));
        }

        if (! $path->isRemote() || $path->remote() === null) {
            return false;
        }

        $result = $this->process->run(['lsf', $path->toRclonePath(), '--max-depth', '1']);

        return $result->successful() && trim($result->stdout) !== '';
    }

    public function download(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    public function upload(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    public function copy(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $this->assertAvailable();
        $this->assertUsable($source, $destination);

        $operation = $this->copyOperation($source, $destination);

        return $this->runTransfer($operation, $source, $destination, $options);
    }

    /**
     * Move via a single `rclone move`/`moveto`.
     *
     * rclone transfers each object, verifies it against the destination and
     * only then removes the source — the copy/verify/delete contract — while
     * running the whole prefix in one process with its own parallelism.
     * Objects skipped by --ignore-existing keep their source copy.
     */
    public function move(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $this->assertAvailable();
        $this->assertUsable($source, $destination);

        $operation = $this->copyOperation($source, $destination) === 'copyto' ? 'moveto' : 'move';

        return $this->runTransfer($operation, $source, $destination, $options);
    }

    public function rename(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->move($source, $destination, $options);
    }

    public function delete(StoragePath $path, TransferOptions $options = new TransferOptions): TransferResult
    {
        $this->assertAvailable();
        $this->assertRemote($path);

        $arguments = ['delete', ...$this->optionFlags($options), ...$this->statsFlags(), $path->toRclonePath()];

        $result = $this->process->run($arguments, $this->streamHandler($options));

        $stats = RcloneStats::parse($result->stderr);
        $deleted = $stats->present ? $stats->deletes : 0;

        $this->logger->info(sprintf(
            'delete source=%s status=%s deleted=%d',
            $path->toDisplayString(),
            $result->successful() ? 'success' : 'failed',
            $deleted,
        ));

        if ($result->failed()) {
            $errors = $stats->errorMessages !== [] ? $stats->errorMessages : [trim($result->stderr) ?: 'rclone delete failed'];

            return new TransferResult(TransferStatus::Failed, $deleted, 0, max(1, $stats->errors), $errors, $path);
        }

        return TransferResult::success($deleted, source: $path);
    }

    public function visibility(StoragePath $path, string $visibility, TransferOptions $options = new TransferOptions): TransferResult
    {
        $this->assertAvailable();
        $this->assertRemote($path);

        $targets = $this->visibilityTargets($path, $options);

        if ($options->dryRun) {
            $count = count(array_filter($targets, static fn (string $key): bool => $key !== ''));

            return TransferResult::success(0, $count, $path);
        }

        $credentials = $this->awsCredentialsFor($path);

        $endpoint = preg_replace('/^https?:\/\//', '', $credentials['endpoint']) ?? $credentials['endpoint'];
        $region = $credentials['region'] ?? $this->regionFromEndpoint($endpoint);
        $bucket = $path->bucket();
        $acl = $this->resolveAcl($visibility);

        $errors = [];
        $changed = 0;
        $skipped = 0;

        foreach ($targets as $key) {
            if ($key === '') {
                continue;
            }

            $process = new Process([
                $credentials['binary'],
                's3api',
                'put-object-acl',
                '--endpoint-url',
                'https://'.$endpoint,
                '--region',
                $region,
                '--bucket',
                $bucket ?? '',
                '--key',
                $key,
                '--acl',
                $acl,
                '--output',
                'text',
            ]);

            $process->setTimeout(600);
            $process->setEnv([
                'AWS_ACCESS_KEY_ID' => $credentials['access_key_id'],
                'AWS_SECRET_ACCESS_KEY' => $credentials['secret_access_key'],
                'AWS_EC2_METADATA_DISABLED' => 'true',
            ]);
            $process->run();

            $error = trim($process->getErrorOutput());

            if ($process->isSuccessful()) {
                $changed++;
            } else {
                $errors[] = sprintf('%s: %s', $key, $error !== '' ? $error : 'AWS CLI failed');
            }
        }

        $this->logger->info(sprintf(
            'visibility source=%s acl=%s status=%s changed=%d failed=%d',
            $path->toDisplayString(),
            $acl,
            $errors === [] ? 'success' : (($changed > 0 || $skipped > 0) ? 'partial' : 'failed'),
            $changed,
            count($errors),
        ));

        if ($errors !== [] && $changed === 0) {
            return new TransferResult(TransferStatus::Failed, $changed, $skipped, count($errors), $errors, $path);
        }

        if ($errors !== []) {
            return new TransferResult(TransferStatus::Partial, $changed, $skipped, count($errors), $errors, $path);
        }

        return TransferResult::success($changed, $skipped, $path);
    }

    /**
     * Run one rclone transfer command and translate its JSON stats into a
     * TransferResult. Counts come from rclone itself rather than being
     * predicted from a listing, so what is reported is what actually happened.
     */
    private function runTransfer(string $operation, StoragePath $source, StoragePath $destination, TransferOptions $options): TransferResult
    {
        $arguments = [
            $operation,
            ...$this->optionFlags($options),
            ...$this->aclFlags($options, $destination),
            ...$this->statsFlags(),
            $source->toRclonePath(),
            $destination->toRclonePath(),
        ];

        if ($operation === 'move') {
            // Leave no empty husk of the source prefix behind after a move.
            $arguments[] = '--delete-empty-src-dirs';
        }

        $result = $this->process->run($arguments, $this->streamHandler($options));

        $stats = RcloneStats::parse($result->stderr);

        $this->logTransfer($operation, $source, $destination, $options, $result, $stats);

        return $this->resultFor($result, $stats, $source, $destination);
    }

    /**
     * The `--s3-acl` to write objects with.
     *
     * When no `--acl` is given, rclone falls back to the `acl` key in the
     * remote's own config and sends it verbatim. A remote configured with an
     * application-level value such as `acl = public` therefore makes every
     * server-side CopyObject fail with "400 InvalidArgument", which is what an
     * S3 endpoint returns for an ACL that is not one of the canned names.
     * Resolving that value through the same visibility map the rest of the app
     * uses keeps such a remote working; a value that cannot be resolved is
     * reported as the configuration error it is, before anything is copied.
     *
     * @return array<int, string>
     */
    private function aclFlags(TransferOptions $options, StoragePath $destination): array
    {
        if ($options->acl !== null && $options->acl !== '') {
            return ['--s3-acl='.$this->assertCanned(
                $this->resolveAcl($options->acl),
                sprintf('The requested ACL "%s"', $options->acl),
            )];
        }

        if (! $destination->isRemote() || $destination->remote() === null) {
            return [];
        }

        $configured = $this->configuredAcl($destination->remote());

        if ($configured === null || $configured === '' || in_array($configured, self::CANNED_ACLS, true)) {
            return [];
        }

        $resolved = $this->assertCanned(
            $this->resolveAcl($configured),
            sprintf('acl = %s, configured on remote "%s",', $configured, $destination->remote()),
        );

        $this->logger->info(sprintf(
            'acl remote=%s configured=%s applied=%s',
            $destination->remote(),
            $configured,
            $resolved,
        ));

        return ['--s3-acl='.$resolved];
    }

    private function assertCanned(string $acl, string $source): string
    {
        if (in_array($acl, self::CANNED_ACLS, true)) {
            return $acl;
        }

        throw new TransferException(sprintf(
            '%s is not a valid S3 ACL, and no mapping for it exists in config/storage.php.'.PHP_EOL
            .'S3 accepts: %s.'.PHP_EOL
            .'Pass a valid value with --acl, set STORAGE_DEFAULT_ACL, or fix the remote:'.PHP_EOL
            .'  rclone config update <remote> acl public-read',
            $source,
            implode(', ', self::CANNED_ACLS),
        ));
    }

    /**
     * The `acl` value configured on an S3 remote, or null when the remote is
     * not S3 or sets none. Memoised: this is read on every transfer.
     */
    private function configuredAcl(string $remote): ?string
    {
        if (array_key_exists($remote, $this->configuredAcls)) {
            return $this->configuredAcls[$remote];
        }

        $result = $this->process->run(['config', 'show', $remote]);

        if (! $result->successful()) {
            return $this->configuredAcls[$remote] = null;
        }

        $values = $this->parseRemoteConfig($result->stdout, $remote);

        if (($values['type'] ?? '') !== 's3') {
            return $this->configuredAcls[$remote] = null;
        }

        $acl = trim((string) ($values['acl'] ?? ''));

        return $this->configuredAcls[$remote] = $acl === '' ? null : $acl;
    }

    /**
     * Ask rclone for machine-readable progress on stderr. --stats-one-line
     * keeps each update to a single JSON line, and NOTICE level means the
     * counters arrive without turning on rclone's full verbose logging.
     *
     * @return array<int, string>
     */
    private function statsFlags(): array
    {
        return [
            '--use-json-log',
            '--stats=1s',
            '--stats-one-line',
            '--stats-log-level',
            'NOTICE',
        ];
    }

    /**
     * Feed live counters to the progress handler as rclone emits them.
     */
    private function streamHandler(TransferOptions $options): ?callable
    {
        if (! $options->progress || $this->progressHandler === null) {
            return null;
        }

        $handler = $this->progressHandler;
        $buffer = '';

        return static function (string $type, string $chunk) use (&$buffer, $handler): void {
            if ($type !== 'err') {
                return;
            }

            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                $stats = RcloneStats::parse($line);

                if ($stats->present) {
                    $handler($stats);
                }
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function optionFlags(TransferOptions $options): array
    {
        $flags = [];

        if ($options->transfers > 0) {
            $flags[] = '--transfers='.$options->transfers;
        }

        if ($options->retries > 0) {
            $flags[] = '--retries='.$options->retries;
        }

        if ($options->dryRun) {
            $flags[] = '--dry-run';
        }

        if ($options->verbose) {
            $flags[] = '--verbose';
        }

        if (! $options->overwrite) {
            $flags[] = '--ignore-existing';
        }

        return $flags;
    }

    private function logTransfer(string $operation, StoragePath $source, StoragePath $destination, TransferOptions $options, ProcessResult $result, RcloneStats $stats): void
    {
        $this->logger->info(sprintf(
            'operation=%s source=%s destination=%s status=%s copied=%d skipped=%d failed=%d%s',
            $operation,
            $source->toDisplayString(),
            $destination->toDisplayString(),
            $result->successful() ? 'success' : 'failed',
            $stats->transfers,
            max(0, $stats->checks - $stats->transfers),
            $stats->errors,
            $options->dryRun ? ' dry_run=1' : '',
        ));
    }

    /**
     * rclone's counters, not its exit code, decide the outcome: a run can exit
     * 0 having transferred nothing (everything skipped) and can exit non-zero
     * having transferred most of a prefix (a partial result).
     */
    private function resultFor(ProcessResult $result, RcloneStats $stats, StoragePath $source, StoragePath $destination): TransferResult
    {
        if (! $stats->present) {
            if ($result->successful()) {
                return TransferResult::success(source: $source, destination: $destination);
            }

            $errors = [trim($result->stderr) ?: trim($result->stdout) ?: 'rclone transfer failed'];

            return new TransferResult(TransferStatus::Failed, failed: 1, errors: $errors, source: $source, destination: $destination);
        }

        $copied = $stats->transfers;

        // rclone's "checks" counter is comparisons made, not objects left
        // behind: move/moveto verifies every object at the destination before
        // deleting the source, so a clean rename reports checks == transfers.
        // Only the surplus over what was transferred is a genuine skip — the
        // objects --ignore-existing compared and then left alone.
        $skipped = max(0, $stats->checks - $stats->transfers);
        $failed = $stats->errors;

        $status = match (true) {
            $failed === 0 && $result->successful() => TransferStatus::Success,
            $copied > 0 || $skipped > 0 => TransferStatus::Partial,
            default => TransferStatus::Failed,
        };

        // A clean run - rclone exited 0 and counted no errors - has nothing to
        // report, whatever it logged on the way there.
        $errors = $status === TransferStatus::Success ? [] : $stats->errorMessages;

        if ($status !== TransferStatus::Success && $errors === []) {
            $errors = [trim($result->stderr) ?: 'rclone transfer failed'];
        }

        return new TransferResult(
            $status,
            $copied,
            $skipped,
            $failed === 0 && $status !== TransferStatus::Success ? 1 : $failed,
            $errors,
            $source,
            $destination,
        );
    }

    /**
     * rclone "copy" copies the contents of a directory/prefix into the
     * destination. "copyto" copies a single object to an exact destination
     * path, preserving the specified object key instead of just the filename.
     */
    private function copyOperation(StoragePath $source, StoragePath $destination): string
    {
        $sourceIsFile = ! $source->isDirectory() && ! $source->isBucketRoot() && ! $this->isLocalDirectory($source);
        $destinationIsFile = ! $destination->isDirectory() && ! $destination->isBucketRoot();

        return $sourceIsFile && $destinationIsFile ? 'copyto' : 'copy';
    }

    private function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new StorageException('rclone is not installed or not executable. See https://rclone.io/install/.');
        }
    }

    private function assertRemote(StoragePath $path): void
    {
        if (! $path->isRemote() || $path->remote() === null) {
            throw new StorageException('A remote storage path is required for the rclone driver.');
        }

        if (! $this->remoteExists($path->remote())) {
            throw new RemoteNotFoundException(sprintf(
                "Remote '%s' not found. Run 'rclone config' to set it up.",
                $path->remote(),
            ));
        }
    }

    /**
     * The rclone driver can transfer whenever at least one end is a remote.
     * Local ends are passed through as plain filesystem paths (never "local:").
     */
    private function assertUsable(StoragePath $source, StoragePath $destination): void
    {
        if ($source->isRemote()) {
            $this->assertRemote($source);
        }

        if ($destination->isRemote()) {
            $this->assertRemote($destination);
        }

        if ($source->isLocal() && $destination->isLocal()) {
            throw new TransferException('The local-to-local transfer should be handled by the local driver.');
        }
    }

    /**
     * Map application-level visibility values to the backend ACL, or allow a
     * backend ACL value through unchanged (legacy compatibility).
     */
    private function resolveAcl(string $visibility): string
    {
        $mapping = config('storage.visibility', []);

        if (isset($mapping[$visibility])) {
            return (string) $mapping[$visibility];
        }

        return $visibility;
    }

    /**
     * @return array{endpoint: string, access_key_id: string, secret_access_key: string, region?: string, binary: string}
     */
    private function awsCredentialsFor(StoragePath $path): array
    {
        $binary = $this->which('aws');

        if ($binary === null) {
            throw new VisibilityException(
                'Visibility on rclone remotes requires the AWS CLI (S3-compatible). '.PHP_EOL
                .'Install on macOS:  brew install awscli'.PHP_EOL
                .'Install on Linux:  pip install awscli  OR  snap install aws-cli --classic',
            );
        }

        $result = $this->process->run(['config', 'show', (string) $path->remote()]);

        if (! $result->successful()) {
            throw new VisibilityException('Could not read the rclone configuration for the remote.');
        }

        $values = $this->parseRemoteConfig($result->stdout, (string) $path->remote());

        foreach (['endpoint', 'access_key_id', 'secret_access_key'] as $key) {
            if (($values[$key] ?? '') === '') {
                throw new VisibilityException(sprintf(
                    "Could not extract '%s' from the rclone configuration for remote '%s'.",
                    $key,
                    $path->remote(),
                ));
            }
        }

        $values['binary'] = $binary;

        return $values;
    }

    /**
     * Parse the "[remote]" block of `rclone config show` without executing any
     * shell code. Lines are simple "key = value" pairs.
     */
    private function parseRemoteConfig(string $output, string $remote): array
    {
        $values = [];
        $inBlock = false;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '[')) {
                $inBlock = rtrim(ltrim($line, '['), ']') === $remote;

                continue;
            }

            if (! $inBlock) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value);
            }
        }

        return $values;
    }

    private function regionFromEndpoint(string $endpoint): string
    {
        $host = preg_replace('/^https?:\/\//', '', $endpoint) ?? $endpoint;

        return explode('.', $host, 2)[0];
    }

    /**
     * Enumerate the object keys that should receive the new ACL. When the
     * target is a single object, no enumeration is required; the object itself
     * is the only key.
     *
     * @return array<int, string>
     */
    private function visibilityTargets(StoragePath $path, TransferOptions $options): array
    {
        $single = $path->isFile() && ! $path->isBucketRoot();

        if ($single) {
            return [$path->bucket().'/'.ltrim((string) $path->path(), '/')];
        }

        $entries = $this->listType($path, $options->recursive, true);

        $base = $path->isBucketRoot()
            ? (string) $path->bucket()
            : (string) $path->bucket().'/'.trim((string) $path->path(), '/');

        return array_map(static fn (ListingEntry $entry): string => $base.'/'.$entry->path, $entries);
    }

    /**
     * Turn rclone's log output into a single readable sentence.
     *
     * Raw stderr carries a timestamp and level on every line plus a trailing
     * "Failed to ... with N errors" summary, none of which belongs in a message
     * shown to the person who typed the command.
     */
    private function explain(string $stderr): string
    {
        $messages = [];

        foreach (preg_split('/\R/', trim($stderr)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+\w+\s*:\s*/', '', trim($line)));

            if ($line === '' || preg_match('/^Failed to \S+ with \d+ errors?/', $line) === 1) {
                continue;
            }

            $messages[$line] = $line;
        }

        return $messages === [] ? 'rclone reported no further detail.' : implode('; ', $messages);
    }

    private function which(string $command): ?string
    {
        $process = Process::fromShellCommandline('command -v '.$command, null, null, null, 10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    private function localPath(StoragePath $path): string
    {
        $value = $path->path() ?? '';

        if ($value === '' || str_starts_with($value, '/')) {
            return $value;
        }

        return getcwd().'/'.$value;
    }

    private function isLocalDirectory(StoragePath $path): bool
    {
        if (! $path->isLocal() || $path->path() === null) {
            return false;
        }

        return is_dir($this->localPath($path));
    }

    /** @return array<int, ListingEntry> */
    private function parseListing(string $output, bool $filesOnly): array
    {
        $entries = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            [$size, $relative] = array_pad(explode("\t", $line, 2), 2, '');

            $isDirectory = ! $filesOnly && str_ends_with($relative, '/');
            $relative = $isDirectory ? rtrim($relative, '/') : $relative;

            if ($relative === '') {
                continue;
            }

            // rclone reports -1 as the size of a directory; carrying that
            // through made a listing of two prefixes total "-2B".
            $bytes = (int) $size;

            $entries[] = new ListingEntry(
                name: basename($relative),
                path: $relative,
                size: $isDirectory || $bytes < 0 ? 0 : $bytes,
                isDirectory: $isDirectory,
                isFile: ! $isDirectory,
            );
        }

        return $entries;
    }
}
