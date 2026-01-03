<x-mail::message>
# Order Status Update

Your order #{{ $order->id }} has been updated to: **{{ $order->status }}**

@if ($order->tracking_number)
Tracking Number: {{ $order->tracking_number }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>