<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Jobs\ProcessTransferJob;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Re-dispatch failed storage transfer jobs back onto the queue so they can be
 * reprocessed by `queue:work`. Lists them when called without an id.
 */
#[AsCommand(name: 'retry')]
class RetryCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Re-dispatch failed storage transfer jobs';

    protected $help = 'Re-dispatch failed storage transfer jobs back onto the queue.

<options=bold>Usage</>:
  storage retry                   list failed transfer jobs
  storage retry <id> ...          re-dispatch specific jobs
  storage retry all               re-dispatch every failed transfer job

Failed jobs are stored by the queue worker (failed_jobs table) whenever a
queued transfer (--queue) fails; `queue:work` reprocesses them once retried.';

    public function handle(): int
    {
        $failer = $this->laravel['queue.failer'];

        $ids = $this->argument('id');

        $all = count($ids) === 1 && $ids[0] === 'all';
        $ids = $all ? $this->transferJobIds($failer) : $ids;

        if ($ids === []) {
            $this->listTransferJobs($failer);

            return ExitCode::SUCCESS;
        }

        $this->renderOperationHeader('RETRY');

        $retried = 0;
        $missing = 0;

        foreach ($ids as $id) {
            $job = $failer->find((string) $id);

            if ($job === null || ! $this->isTransferJob($job)) {
                $this->renderError(sprintf('No failed transfer job with ID [%s].', $id));
                $missing++;

                continue;
            }

            $this->laravel['queue']->connection($job->connection)->pushRaw(
                $this->resetAttempts($job->payload),
                $job->queue,
            );

            $failer->forget((string) $id);
            $this->renderDetail('Re-dispatched', sprintf('%s  <fg=gray>(job %s)</>', $this->describe($job), $id));
            $retried++;
        }

        $this->newLine();

        if ($retried === 0) {
            // Asking to retry jobs and re-dispatching none is a failure; exiting
            // 0 told callers and CI that the retry had worked.
            $this->renderFailed('No jobs were re-dispatched.');

            return ExitCode::FAILURE;
        }

        $message = sprintf('Re-dispatched %d job%s.', $retried, $retried === 1 ? '' : 's');

        if ($missing > 0) {
            $this->renderPartial($message.sprintf(' %d ID%s did not match a failed transfer job.', $missing, $missing === 1 ? '' : 's'));

            return ExitCode::PARTIAL;
        }

        $this->renderSuccess($message);

        return ExitCode::SUCCESS;
    }

    private function listTransferJobs(FailedJobProviderInterface $failer): void
    {
        $jobs = $this->transferJobs($failer);

        $this->renderOperationHeader('RETRY');

        if ($jobs === []) {
            $this->renderInfo('No failed transfer jobs.');

            return;
        }

        $this->table(
            ['ID', 'Operation', 'Source', 'Destination'],
            array_map(fn (object $job): array => [
                $job->id,
                $this->operation($job) ?? '-',
                $this->source($job) ?? '-',
                $this->destination($job) ?? '-',
            ], $jobs),
        );

        $this->renderHint('Use "storage retry <id>" (or "storage retry all") to re-dispatch these jobs.');
    }

    private function transferJobIds(FailedJobProviderInterface $failer): array
    {
        return array_map(fn (object $job): mixed => $job->id, $this->transferJobs($failer));
    }

    /**
     * @return array<int, object>
     */
    private function transferJobs(FailedJobProviderInterface $failer): array
    {
        return array_values(array_filter($failer->all(), fn (object $job): bool => $this->isTransferJob($job)));
    }

    private function isTransferJob(object $job): bool
    {
        $displayName = data_get(json_decode($job->payload, true), 'displayName');

        return is_string($displayName) && str_contains($displayName, 'ProcessTransferJob');
    }

    private function command(object $job): ?ProcessTransferJob
    {
        $payload = json_decode($job->payload, true);

        $command = data_get($payload, 'data.command');

        if (! is_string($command)) {
            return null;
        }

        $object = @unserialize($command, ['allowed_classes' => true]);

        return $object instanceof ProcessTransferJob ? $object : null;
    }

    private function operation(object $job): ?string
    {
        return $this->command($job)?->operation;
    }

    private function source(object $job): ?string
    {
        return $this->command($job)?->source;
    }

    private function destination(object $job): ?string
    {
        return $this->command($job)?->destination;
    }

    private function describe(object $job): string
    {
        return ($this->operation($job) ?? 'transfer').' '.($this->source($job) ?? '?').' -> '.($this->destination($job) ?? '?');
    }

    private function resetAttempts(string $payload): string
    {
        $data = json_decode($payload, true);

        if (isset($data['attempts'])) {
            $data['attempts'] = 0;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Job IDs to re-dispatch, or "all"');

        parent::configure();
    }
}
