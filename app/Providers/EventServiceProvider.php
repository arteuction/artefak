<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BidPlaced;
use App\Events\ConsignmentChangesRequested;
use App\Events\OfferAccepted;
use App\Listeners\DomainEventConsumer;
use App\Listeners\SendConsignmentChangesNotification;
use App\Listeners\SendOfferAcceptedNotification;
use App\Listeners\SendOutbidNotifications;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    protected $subscribe = [
        DomainEventConsumer::class,
    ];

    protected $listen = [
        BidPlaced::class => [
            SendOutbidNotifications::class,
        ],
        OfferAccepted::class => [
            SendOfferAcceptedNotification::class,
        ],
        ConsignmentChangesRequested::class => [
            SendConsignmentChangesNotification::class,
        ],
    ];
}
