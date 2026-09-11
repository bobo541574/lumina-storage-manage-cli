<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\ListingEntry;
use App\DTOs\StoragePath;
use App\Exceptions\PathParseException;
use App\Exceptions\StorageException;
use App\Services\StorageService;
use App\Support\SizeFormatter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * @note Named "list" per the canonical CLI syntax. Registers through
 *       #[AsCommand] so it replaces Symfony's built-in "list" command;
 *       run without arguments to see all commands instead.
 */
#[AsCommand(name: 'list')]
class ListCommand extends Command
{
    use HandlesStorageErrors;

    private const TYPES = ['all', 'dirs', 'files'];

    private const SORTS = ['asc', 'desc', 'size', 'size-desc'];

    protected $description = 'List objects and directories under a storage path';

    protected $help = 'List the contents of a local directory or remote prefix.

<options=bold>Usage</>:
  storage list [<path>] [options]

<options=bold>Examples</>:
  storage list do-spaces-nyc:my-data/videos/
  storage list /srv/backups --recursive
  storage list remote:bucket/dir/ --type files --recursive
  storage list remote:bucket/dir/ --sort-dir size --sort-file desc

A trailing slash marks an explicit directory/prefix. --type accepts all, dirs
or files. Running without a path prints this usage screen.';

    public function __construct(private readonly StorageService $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->argument('path');

        if ($path === null || $path === '') {
            $this->writeUsage();

            return self::SUCCESS;
        }

        $type = (string) $this->option('type');
        $recursive = (bool) $this->option('recursive');

        // An unrecognised --type used to filter out both files and directories
        // and report an empty, successful listing.
        if (! in_array($type, self::TYPES, true)) {
            $this->newLine();
            $this->renderError(sprintf('Invalid --type "%s". Valid values: %s.', $type, implode(', ', self::TYPES)));

            return self::INVALID;
        }

        foreach (['sort-dir' => $this->option('sort-dir'), 'sort-file' => $this->option('sort-file')] as $option => $value) {
            if (! in_array((string) $value, self::SORTS, true)) {
                $this->newLine();
                $this->renderError(sprintf('Invalid --%s "%s". Valid values: %s.', $option, $value, implode(', ', self::SORTS)));

                return self::INVALID;
            }
        }

        try {
            $target = StoragePath::fromString($path);

            $this->renderOperationHeader('LIST');
            $this->renderDetail('Path', $target->toDisplayString());
            $this->newLine();

            $result = $this->storage->list($target, $recursive, $type);

            $this->render($result->entries, (string) $this->option('sort-dir'), (string) $this->option('sort-file'));

            // Count files and directories apart: a directory is not an object,
            // and folding both into one total overstated what is stored here.
            $directories = 0;
            $files = 0;

            foreach ($result->entries as $entry) {
                $entry->isDirectory ? $directories++ : $files++;
            }

            $summary = sprintf('%d file%s · %s', $files, $files === 1 ? '' : 's', SizeFormatter::human($result->totalSize()));

            if ($directories > 0) {
                $summary .= sprintf(' · %d director%s', $directories, $directories === 1 ? 'y' : 'ies');
            }

            $this->newLine();
            $this->line(sprintf(
                '  <bg=cyan;fg=black;options=bold> SUMMARY </>  %s%s',
                $summary,
                ($elapsed = $this->elapsed()) === null ? '' : '  <fg=gray>('.$elapsed.')</>',
            ));
        } catch (PathParseException|StorageException $e) {
            // reportException maps each exception to its documented exit code,
            // so a missing path exits 3 here just as it does for a transfer.
            return $this->reportException($e);
        }

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List files and directories in a storage path')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Storage path, e.g. do-spaces-nyc:my-data/videos/ or /home/user/downloads/',
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Entries to show: all, dirs or files',
                'all',
            )
            ->addOption(
                'recursive',
                'R',
                InputOption::VALUE_NONE,
                'List recursively',
            )
            ->addOption(
                'sort-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory sort: asc (default), desc, size, size-desc',
                'asc',
            )
            ->addOption(
                'sort-file',
                null,
                InputOption::VALUE_REQUIRED,
                'File sort: asc (default), desc, size, size-desc',
                'asc',
            );
    }

    /**
     * The bare "storage" invocation prints this. Only the application's own
     * commands are listed: filtering by namespace means a framework command
     * added by an upgrade cannot leak into the screen, which a hand-maintained
     * blocklist could not guarantee.
     */
    private function writeUsage(): void
    {
        $this->renderOperationHeader('STORAGE MANAGER');
        $this->renderDetail('Usage', 'storage <command> [options] [arguments]');
        $this->newLine();

        $commands = [];

        foreach ($this->getApplication()->all() as $name => $command) {
            // all() keys aliases as well as canonical names; keep one row
            // per command and show its aliases alongside it.
            if ($command->isHidden() || $name !== $command->getName()) {
                continue;
            }

            if (! str_starts_with($command::class, 'App\\Commands\\')) {
                continue;
            }

            $commands[$name] = $command;
        }

        ksort($commands);

        $labels = [];

        foreach ($commands as $name => $command) {
            $aliases = $command->getAliases();
            $labels[$name] = $name.($aliases !== [] ? ' ['.implode(', ', $aliases).']' : '');
        }

        $width = $labels === [] ? 0 : max(array_map('strlen', $labels));

        foreach ($commands as $name => $command) {
            $this->line(sprintf(
                '  <info>%s</info>  %s',
                str_pad($labels[$name], $width),
                $command->getDescription(),
            ));
        }

        $this->newLine();
        $this->renderHint('Run "storage <command> --help" for per-command options.');
    }

    /** @param array<int, ListingEntry> $entries */
    private function render(array $entries, string $sortDir, string $sortFile): void
    {
        $dirs = [];
        $files = [];

        foreach ($entries as $entry) {
            if ($entry->isDirectory) {
                $dirs[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        $dirs = $this->sortEntries($dirs, $sortDir);
        $files = $this->sortEntries($files, $sortFile);

        foreach ($dirs as $entry) {
            $this->line(sprintf('  <info>%s</info>/', $entry->path));
        }

        foreach ($files as $entry) {
            $this->line(sprintf('  %10s  %s', SizeFormatter::human($entry->size), $entry->path));
        }

        if ($entries === []) {
            $this->renderHint('(empty)');
        }
    }

    /** @param array<int, ListingEntry> $entries @return array<int, ListingEntry> */
    private function sortEntries(array $entries, string $sort): array
    {
        return match ($sort) {
            'desc' => $this->sortBy($entries, 'name', true),
            'size' => $this->sortBy($entries, 'size', false),
            'size-desc' => $this->sortBy($entries, 'size', true),
            default => $this->sortBy($entries, 'name', false),
        };
    }

    /** @param array<int, ListingEntry> $entries @return array<int, ListingEntry> */
    private function sortBy(array $entries, string $field, bool $desc): array
    {
        usort($entries, static function (ListingEntry $a, ListingEntry $b) use ($field, $desc): int {
            $cmp = match ($field) {
                'size' => $a->size <=> $b->size,
                default => strcasecmp($a->name, $b->name),
            };

            return $desc ? -$cmp : $cmp;
        });

        return $entries;
    }
}
