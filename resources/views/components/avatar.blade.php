@props(['user', 'size' => 'h-8 w-8', 'text' => 'text-xs'])
@if($url = $user->avatarUrl())
<img src="{{ $url }}" alt="{{ $user->name }}" {{ $attributes->merge(['class' => "$size rounded-full object-cover shrink-0"]) }}>
@else
<span {{ $attributes->merge(['class' => "$size $text rounded-full bg-fw-grey-light text-fw-grey font-semibold inline-flex items-center justify-center shrink-0"]) }}>{{ $user->initials() }}</span>
@endif
