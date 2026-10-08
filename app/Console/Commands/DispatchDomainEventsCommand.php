<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outbox\DomainEventDispatcher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Drains the domain_events outbox.
 *
 * Run every minute via the scheduler.
 * Also reclaims stale processing leases (worker crash recovery).
 */
final class DispatchDomainEventsCommand extends Command
{
    protected $signature   = 'outbox:dispatch {--limit=100 : Max events to process per run}';
    protected $description = 'Dispatch pending domain events from the outbox';

    public function handle(Dispatcher $events): int
    {
        $dispatcher = new DomainEventDispatcher($events);

        $reclaimed  = $dispatcher->reclaimStaleLease();
        $dispatched = $dispatcher->dispatchPending((int) $this->option('limit'));

        if ($reclaimed > 0) {
            $this->line("Reclaimed {$reclaimed} stale lease(s).");
        }

        $this->line("Dispatched {$dispatched} domain event(s).");

        return Command::SUCCESS;
    }
}
