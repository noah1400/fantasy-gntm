<x-filament-widgets::widget>
    <x-filament::section heading="My Models">
        @php $models = $this->getMyModelsData(); @endphp

        @if($models->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">You don't have any models yet.</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($models as $entry)
                    <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        @if($entry['top_model']->image)
                            <img src="{{ Storage::url($entry['top_model']->image) }}" alt="{{ $entry['top_model']->name }}" class="size-12 rounded-full object-cover">
                        @else
                            <div class="flex size-12 items-center justify-center rounded-full bg-primary-50 font-bold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                {{ substr($entry['top_model']->name, 0, 1) }}
                            </div>
                        @endif
                        <div class="flex-1">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $entry['top_model']->name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['pick_type']->getLabel() }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ number_format($entry['points'], 1) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">pts</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
