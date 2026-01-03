<x-mail::message>
# New Order Notification

You have received a new order!

Order #{{ $order->id }} has been placed for your product(s).

Please log in to your vendor dashboard to view the order details.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>