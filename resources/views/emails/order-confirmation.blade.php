<x-mail::message>
# Order Confirmation

Thank you for your order!

Your order #{{ $order->id }} has been confirmed.

We will notify you again once your order has shipped.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>