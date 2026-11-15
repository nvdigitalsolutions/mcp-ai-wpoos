<?php

namespace App\Console\Commands;

use App\Spike\SpikeFlakyHandler;
use Illuminate\Console\Command;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class NvoosQueueDemo extends Command
{
    protected $signature = 'nvoos:queue-demo {--wait=20}';

    protected $description = 'Dispatch the flaky spike job and print its durable status transitions.';

    public function handle(): int
    {
        $client = app(QueueClientInterface::class);
        $jobId = $client->enqueue(
            SpikeFlakyHandler::class,
            ['key' => 'demo-'.uniqid('', true)],
        );

        $this->info('Job ID: '.$jobId);

        $deadline = time() + max(1, (int) $this->option('wait'));
        $last = '';

        while (time() < $deadline) {
            $status = $client->getStatus($jobId);
            $line = $status->status.' attempts='.$status->attempts;

            if ($line !== $last) {
                $this->line($line);
                $last = $line;
            }

            if ($status->isTerminal()) {
                break;
            }

            sleep(1);
        }

        $this->line('Final: '.$client->getStatus($jobId)->status);

        return self::SUCCESS;
    }
}
