@component('mail::message')
# {{ $title }}

@if($body)
{{ $body }}
@endif

@if(!empty($context))
@component('mail::table')
| Field | Value |
|-------|-------|
@foreach($context as $key => $value)
| {{ $key }} | {{ is_scalar($value) ? $value : json_encode($value) }} |
@endforeach
@endcomponent
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
