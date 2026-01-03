<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Mail\CartFollowUpEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendCartFollowUpEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-cart-follow-up-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send follow-up emails to users with abandoned carts.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $usersWithAbandonedCarts = User::whereHas('cart.items')
            ->whereDoesntHave('orders', function ($query) {
                $query->where('created_at', '>=', Carbon::now()->subDay());
            })
            ->whereHas('cart', function ($query) {
                $query->where('updated_at', '<=', Carbon::now()->subHours(24));
            })
            ->get();

        foreach ($usersWithAbandonedCarts as $user) {
            try {
                Mail::to($user->email)->send(new CartFollowUpEmail($user));
                $this->info("Sent cart follow-up email to {$user->email}");
            } catch (\Exception $e) {
                $this->error("Failed to send email to {$user->email}: " . $e->getMessage());
            }
        }

        $this->info('Cart follow-up emails sent successfully!');
    }
}
