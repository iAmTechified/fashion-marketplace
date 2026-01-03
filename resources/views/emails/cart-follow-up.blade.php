<x-mail::message>
# Don't Forget Your Cart!

You have items in your shopping cart. Don't miss out!

<x-mail::button :url="''">
Complete Your Purchase
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>