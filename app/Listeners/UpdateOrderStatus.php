<?php

namespace App\Listeners;

use App\Events\TransactionCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateOrderStatus
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TransactionCompleted $event): void
    {
        if ($event->transaction->status === 'completed') {
            $order = $event->transaction->order;
            $order->status = 'completed';
            $order->save();
        }
    }
}
