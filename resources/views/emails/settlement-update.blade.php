<x-mail::message>
# Settlement Update

Your settlement for order #{{ $settlement->order->id }} has been updated to: **{{ $settlement->status }}**

@if ($settlement->status === 'paid')
Transaction ID: {{ $settlement->transaction_id }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>