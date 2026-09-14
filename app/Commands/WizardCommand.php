<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\DTOs\StoragePath;
use App\Exceptions\StorageException;
use App\Models\SavedConfig;
use App\Services\StorageService;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Interactive wizard. Every selection is resolved into the same canonical
 * StoragePath values and delegated to the regular commands, so interactive and
 * non-interactive flows share identical services and validation.
 *
 * Config profiles (Storage::saved_configs) can preload selections (--config)
 * and capture them (--save-as), restoring the legacy saved-configurations
 * feature (multi-remote-transfer.sh).
 */
#[AsCommand(name: 'wizard', aliases: ['interactive'], description: 'Interactive storage operation wizard')]
class WizardCommand extends Command
{
    use PresentsOutput;

    protected $help = 'Interactive storage manager wizard.

<options=bold>Usage</>:
  storage wizard [options]
  storage interactive [options]

Walks through storage, bucket, operation, source and destination selection,
then runs the chosen operation through the regular commands. Non-interactive
invocations are not supported; use the direct commands when stdin is not a
terminal. Interactive and non-interactive flows share the same services.

<options=bold>Options</>:
  --config=NAME   Preload selections from a saved configuration profile
  --save-as=NAME  Store the executed operation as a saved configuration profile';

    private const OPERATIONS = [
        'List',
        'Download',
        'Upload',
        'Copy',
        'Move',
        'Rename',
        'Duplicate',
        'Copy To',
        'Move To',
        'Visibility',
        'Delete',
    ];

    private const OPERATION_SLUGS = [
        'List' => 'list',
        'Download' => 'download',
        'Upload' => 'upload',
        'Copy' => 'copy',
        'Move' => 'move',
        'Rename' => 'rename',
        'Duplicate' => 'duplicate',
        'Copy To' => 'copy-to',
        'Move To' => 'move-to',
        'Visibility' => 'visibility',
        'Delete' => 'delete',
    ];

    private const VISIBILITIES = ['private', 'public'];

    public function __construct(private readonly StorageService $storage)
    {
        parent::__construct();
    }

    private ?SavedConfig $profile = null;

    private ?string $lastOperation = null;

    private ?string $lastSource = null;

    private ?string $lastDestination = null;

    private ?array $lastOptions = null;

    public function handle(): int
    {
        if (! $this->configureProfile()) {
            return ExitCode::SOURCE_NOT_FOUND;
        }

        $this->renderOperationHeader('STORAGE MANAGER');

        $storage = $this->selectStorage();
        $root = $this->selectRoot($storage);

        $operation = $this->selectOperation();

        $exit = $this->runOperation($operation, $root);

        if (($exit === ExitCode::SUCCESS || $exit === ExitCode::PARTIAL) && $this->lastOperation !== null) {
            $this->maybeSaveProfile();
        }

        return $exit;
    }

    private function configureProfile(): bool
    {
        $name = $this->option('config');

        if ($name === null) {
            return true;
        }

        try {
            $profile = SavedConfig::query()->where('name', $name)->first();
        } catch (\Throwable $e) {
            $this->renderError('Could not load configuration: '.$e->getMessage());

            return false;
        }

        if ($profile === null) {
            $this->renderError(sprintf('Configuration "%s" not found.', $name));

            return false;
        }

        $this->profile = $profile;

        return true;
    }

    private function maybeSaveProfile(): void
    {
        $name = $this->option('save-as');

        if ($name === null || $this->lastSource === null) {
            return;
        }

        try {
            SavedConfig::query()->updateOrCreate(
                ['name' => $name],
                [
                    'storage' => $this->lastStorage(),
                    'bucket' => $this->lastBucket(),
                    'operation' => $this->lastOperation,
                    'source' => $this->lastSource,
                    'destination' => $this->lastDestination,
                    'options' => $this->lastOptions ?? [],
                ],
            );

            $this->renderSuccess(sprintf('Saved configuration %s.', $name));
        } catch (\Throwable $e) {
            $this->renderError('Could not save configuration: '.$e->getMessage());
        }
    }

    private function lastStorage(): string
    {
        try {
            $path = StoragePath::fromString((string) $this->lastSource);

            return $path->isRemote() ? (string) $path->remote() : 'local';
        } catch (\Throwable) {
            return 'local';
        }
    }

    private function lastBucket(): ?string
    {
        if ($this->lastSource === null) {
            return null;
        }

        try {
            return StoragePath::fromString($this->lastSource)->bucket();
        } catch (\Throwable) {
            return null;
        }
    }

    private function selectStorage(): string
    {
        try {
            $remotes = $this->storage->remotes();
        } catch (StorageException) {
            $remotes = [];
        }

        $choices = $remotes === [] ? ['local'] : [...$remotes, 'local'];

        $default = 0;

        if ($this->profile !== null && $this->profile->storage !== 'local') {
            $index = array_search($this->profile->storage, $choices, true);

            if ($index !== false) {
                $default = $index;
            }
        }

        return $this->choice('Select storage', $choices, $default);
    }

    private function selectRoot(string $storage): string
    {
        if ($storage === 'local') {
            $default = $this->profile?->storage === 'local' && $this->profile->source !== null
                ? $this->profile->source
                : (getcwd() ?: (string) getenv('HOME'));
            $answer = $this->ask('Local working directory', $default) ?? $default;

            return rtrim($answer, '/');
        }

        $manual = '<enter bucket manually>';
        $buckets = $this->bucketsFor($storage);

        if ($buckets === []) {
            $bucket = (string) $this->ask('Bucket name', $this->profileBucket($storage));
        } else {
            $choices = [...$buckets, $manual];
            $default = 0;

            if ($this->profile !== null && $this->profile->storage === $storage && $this->profile->bucket !== null) {
                $index = array_search($this->profile->bucket, $buckets, true);

                if ($index !== false) {
                    $default = $index;
                }
            }

            $bucket = $this->choice('Select bucket', $choices, $default);

            if ($bucket === $manual) {
                $bucket = (string) $this->ask('Bucket name', $this->profileBucket($storage));
            }
        }

        return $storage.':'.$bucket;
    }

    private function profileBucket(string $storage): ?string
    {
        return $this->profile?->storage === $storage ? $this->profile->bucket : null;
    }

    private function selectOperation(): string
    {
        $default = 0;

        if ($this->profile !== null && $this->profile->operation !== null) {
            $index = array_search($this->profile->operation, self::OPERATION_SLUGS, true);

            if ($index !== false) {
                $default = $index;
            }
        }

        return $this->choice('Select operation', self::OPERATIONS, $default);
    }

    private function runOperation(string $operation, string $root): int
    {
        return match ($operation) {
            'List' => $this->opList($root),
            'Download' => $this->opTransfer('download', $root, toLocal: true),
            'Upload' => $this->opTransfer('upload', $root, fromLocal: true),
            'Copy' => $this->opTransfer('copy', $root),
            'Move' => $this->opTransfer('move', $root),
            'Rename' => $this->opTransfer('rename', $root),
            'Duplicate' => $this->opTransfer('duplicate', $root),
            'Copy To' => $this->opTransfer('copy-to', $root),
            'Move To' => $this->opTransfer('move-to', $root),
            'Visibility' => $this->opVisibility($root),
            'Delete' => $this->opDelete($root),
            default => ExitCode::INVALID,
        };
    }

    private function opList(string $root): int
    {
        $path = $this->ask('Source path', $root);
        $path = $this->resolveAgainstRoot($path, $root);

        $this->lastOperation = 'list';
        $this->lastSource = $path;
        $this->lastOptions = ['recursive' => false];

        return $this->call('list', ['path' => $path]) ?? ExitCode::FAILURE;
    }

    private function opTransfer(string $command, string $root, bool $fromLocal = false, bool $toLocal = false): int
    {
        $sourceDefault = $fromLocal ? (getcwd() ?: '.') : $this->profileSource($root);
        $destinationDefault = $toLocal ? (getcwd() ?: '.') : ($this->profile?->destination ?? $root);

        $source = $this->ask('Source path', $sourceDefault ?? $root);
        $destination = $this->ask('Destination path', $destinationDefault);

        $source = $this->resolveAgainstRoot($source, $root, $fromLocal);
        $destination = $this->resolveAgainstRoot($destination, $root, $toLocal);

        $overwrite = $this->confirm('Overwrite existing objects?', false);
        $dryRun = $this->confirm('Dry run (no changes)?', false);
        $progress = $this->confirm('Show live progress?', false);

        $this->newLine();
        $this->renderDetail('Plan', $command.' '.$source.' -> '.$destination);

        if (! $dryRun && ! $this->confirm('Run operation?', true)) {
            $this->line('Cancelled.');

            return ExitCode::SUCCESS;
        }

        $this->lastOperation = $command;
        $this->lastSource = $source;
        $this->lastDestination = $destination;
        $this->lastOptions = [
            'overwrite' => $overwrite,
            'dry_run' => $dryRun,
            'progress' => $progress,
        ];

        return $this->call($command, [
            'source' => $source,
            'destination' => $destination,
            '--overwrite' => $overwrite,
            '--dry-run' => $dryRun,
            '--progress' => $progress,
        ]) ?? ExitCode::FAILURE;
    }

    private function opVisibility(string $root): int
    {
        $path = $this->ask('Object path', $this->profileSource($root) ?? $root);
        $path = $this->resolveAgainstRoot($path, $root);
        $value = $this->choice('Visibility', self::VISIBILITIES, 0);

        $this->lastOperation = 'visibility';
        $this->lastSource = $path;
        $this->lastOptions = [];

        return $this->call('visibility', ['path' => $path, 'visibility' => $value]) ?? ExitCode::FAILURE;
    }

    private function opDelete(string $root): int
    {
        $path = $this->ask('Path to delete', $this->profileSource($root) ?? $root);
        $path = $this->resolveAgainstRoot($path, $root);

        $this->lastOperation = 'delete';
        $this->lastSource = $path;
        $this->lastOptions = [];

        return $this->call('delete', ['path' => $path]) ?? ExitCode::FAILURE;
    }

    /**
     * Resolve a user-typed path against the current root.
     *
     * If the input already contains a remote prefix (remote:bucket/...) or is
     * an absolute local path, it is returned as-is. Otherwise the root is
     * prepended so "za_ticket" becomes "secretary_standard:imgdata/za_ticket".
     */
    private function resolveAgainstRoot(string $input, string $root, bool $forceLocal = false): string
    {
        $input = trim($input);

        if ($input === '' || $input === $root) {
            return $root;
        }

        if ($forceLocal) {
            return $input;
        }

        if (str_starts_with($input, '/')) {
            return $input;
        }

        if (preg_match('/^([^:\\\\\/\s]+):/', $input) === 1) {
            return $input;
        }

        return rtrim($root, '/').'/'.ltrim($input, '/');
    }

    private function profileSource(string $root): ?string
    {
        if ($this->profile === null || $this->profile->source === null) {
            return null;
        }

        try {
            $rootPath = StoragePath::fromString($root);
            $sourcePath = StoragePath::fromString($this->profile->source);
        } catch (\Throwable) {
            return null;
        }

        if ($rootPath->isLocal() !== $sourcePath->isLocal()) {
            return null;
        }

        if ($rootPath->isRemote() && $rootPath->remote() !== $sourcePath->remote()) {
            return null;
        }

        return $this->profile->source;
    }

    private function bucketsFor(string $storage): array
    {
        try {
            return $this->storage->buckets($storage);
        } catch (StorageException) {
            return [];
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Preload selections from a saved configuration profile')
            ->addOption('save-as', null, InputOption::VALUE_REQUIRED, 'Store the executed operation as a saved configuration profile');

        parent::configure();
    }
}
