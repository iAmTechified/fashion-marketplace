<x-mail::message>
# Product Listing Approval

Your product listing for "{{ $productName }}" has been {{ $status }}.

@if ($status === 'approved')
Congratulations! Your product is now live on our platform.
@else
Reason: {{ $reason }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>