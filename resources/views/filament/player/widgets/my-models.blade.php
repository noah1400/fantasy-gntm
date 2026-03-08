<x-filament-widgets::widget>
    <x-filament::section heading="My Models">
        @php $models = $this->getMyModelsData(); @endphp

        @if($models->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">You don't have any models yet.</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($models as $entry)
                    <div @class([
                        'flex items-center gap-3 rounded-lg p-3',
                        'bg-gray-50 dark:bg-white/5' => ! $entry['is_dropped'],
                        'bg-gray-100/50 opacity-60 dark:bg-white/[0.02]' => $entry['is_dropped'],
                    ])>
                        @if($entry['top_model']->image)
                            <img src="{{ Storage::disk('public')->url($entry['top_model']->image) }}" alt="{{ $entry['top_model']->name }}" @class([
                                'size-12 rounded-full object-cover',
                                'grayscale' => $entry['is_dropped'],
                            ])>
                        @else
                            <div @class([
                                'flex size-12 items-center justify-center rounded-full font-bold',
                                'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400' => ! $entry['is_dropped'],
                                'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500' => $entry['is_dropped'],
                            ])>
                                {{ substr($entry['top_model']->name, 0, 1) }}
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <p @class([
                                'font-medium',
                                'text-gray-950 dark:text-white' => ! $entry['is_dropped'],
                                'text-gray-400 line-through dark:text-gray-500' => $entry['is_dropped'],
                            ])>{{ $entry['top_model']->name }}</p>
                            <div class="flex flex-col gap-0.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $entry['pick_type']->getLabel() }}
                                    @if($entry['picked_in_episode'])
                                        in Ep. {{ $entry['picked_in_episode']->number }}
                                    @endif
                                </p>
                                @if($entry['is_dropped'] && $entry['dropped_after_episode'])
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        @if($entry['drop_reason'])
                                            {{ $entry['drop_reason']->getLabel() }}
                                        @else
                                            Dropped
                                        @endif
                                        after Ep. {{ $entry['dropped_after_episode']->number }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        <div class="text-end">
                            <p @class([
                                'text-lg font-bold',
                                'text-primary-600 dark:text-primary-400' => ! $entry['is_dropped'],
                                'text-gray-400 dark:text-gray-500' => $entry['is_dropped'],
                            ])>{{ number_format($entry['points'], 1) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">pts</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
