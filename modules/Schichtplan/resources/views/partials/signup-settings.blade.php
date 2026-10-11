{{-- Self sign-up settings (PlanCreate and PlanEditor) --}}
@php($in = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<fieldset class="space-y-3 rounded-xl border border-gray-200 p-4">
    <legend class="px-1 text-sm font-semibold text-gray-900">Wer besetzt die Plätze?</legend>
    <div class="flex flex-col gap-2 sm:flex-row">
        @foreach(\Modules\Schichtplan\src\Models\Plan::SIGNUP_MODES as $value => $label)
            <label class="flex flex-1 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm has-[:checked]:border-fw-red has-[:checked]:bg-red-50">
                <input type="radio" wire:model.live="signup" value="{{ $value }}" class="text-fw-red focus:ring-fw-red"> {{ $label }}
            </label>
        @endforeach
    </div>
    @if($signup === 'self')
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Eintragen bis (leer = bis zum Beginn)<input type="datetime-local" wire:model="signupUntil" class="{{ $in }}"></label>
            <label class="block text-sm text-gray-700">Höchstens Schichten je Person (optional)<input type="number" min="1" max="50" wire:model="maxShifts" class="{{ $in }}"></label>
        </div>
        @error('signupUntil')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('maxShifts')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <label class="flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="needsApproval" class="mt-0.5 rounded text-fw-red focus:ring-fw-red"> <span>Eintragungen müssen bestätigt werden (vom Planer oder der Stationsverantwortung); bis dahin ist der Platz reserviert.</span></label>
        <p class="text-xs text-gray-500">Beim Veröffentlichen bekommt die Zielgruppe eine Einladung zum Eintragen. Am Vortag erinnert die App jeden an seine Schichten. „Plan füllen“ kann übrige Lücken danach noch automatisch verteilen.</p>
    @endif
    <div class="space-y-1">
        <p class="text-sm text-gray-700">Zielgruppe <span class="text-xs text-gray-500">(leer = alle mit Zugang zum Schichtplan; sonst wer in einer der Abteilungen ist oder eine der Funktionen hat)</span></p>
        <div class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach($audienceGroupOptions as $id => $name)
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model="audienceGroups" value="{{ $id }}" class="rounded"> {{ $name }}</label>
            @endforeach
        </div>
        @if($audienceFunctionOptions->isNotEmpty())
            <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 pt-1">
                @foreach($audienceFunctionOptions as $id => $name)
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model="audienceFunctions" value="{{ $id }}" class="rounded"> {{ $name }}</label>
                @endforeach
            </div>
        @endif
    </div>
</fieldset>
