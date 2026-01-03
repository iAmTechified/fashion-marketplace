@component('mail::message')
# Payment Failed

Hello {{ $order->user->name }},

We are sorry, but your payment for Order #{{ $order->id }} was unsuccessful.

**Amount:** NGN {{ number_format($order->total_amount, 2) }}

Please try again or contact support if the issue persists.

@component('mail::button', ['url' => config('app.url')])
Return to Store
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent