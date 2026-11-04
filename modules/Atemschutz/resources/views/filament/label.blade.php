<div class="space-y-3 text-center">
    <img class="mx-auto h-56 w-56" src="{{ $qr }}" alt="QR-Code {{ $equipment->code }}">
    <p class="font-semibold">{{ $equipment->name }}</p>
    <p class="font-mono text-sm">{{ $equipment->code }}</p>
</div>
