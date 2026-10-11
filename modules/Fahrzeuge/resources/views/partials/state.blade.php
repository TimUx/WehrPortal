@php($tones = ['green' => 'bg-green-100 text-green-800', 'amber' => 'bg-amber-100 text-amber-900', 'red' => 'bg-red-100 text-red-800', 'blue' => 'bg-blue-100 text-blue-800', 'gray' => 'bg-gray-200 text-gray-700'])
<span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $tones[$state['tone']] }}">{{ $state['label'] }}</span>
