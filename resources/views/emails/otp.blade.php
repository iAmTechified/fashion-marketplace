<x-mail::message>
# Your One-Time Password

Your OTP for {{ $reason }} is: **{{ $otp }}**

This OTP is valid for 10 minutes.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>