<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Episode Selector --}}
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="selectedEpisodeId">
                <option value="">-- Select Episode --</option>
                @foreach($this->activeEpisodes as $episode)
                    <option value="{{ $episode->id }}">Episode {{ $episode->number }}: {{ $episode->title }} ({{ $episode->status->getLabel() }})</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        @if($this->selectedEpisodeId)
            {{-- Select Eliminated Models --}}
            <x-filament::section>
                <x-slot name="heading">Select Eliminated Models</x-slot>

                <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($this->activeModels as $model)
                        @php $isEliminated = in_array($model->id, $this->eliminatedModelIds); @endphp
                        <button
                            wire:click="toggleElimination({{ $model->id }})"
                            @class([
                                'flex flex-col items-center rounded-xl border p-6 shadow-sm transition duration-75',
                                'border-danger-500 bg-danger-50 dark:border-danger-400 dark:bg-danger-400/10' => $isEliminated,
                                'border-gray-200 bg-white hover:border-danger-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-danger-500 dark:hover:bg-white/5' => !$isEliminated,
                            ])
                        >
                            <span @class([
                                'text-sm font-medium',
                                'text-danger-700 line-through dark:text-danger-300' => $isEliminated,
                                'text-gray-950 dark:text-white' => !$isEliminated,
                            ])>
                                {{ $model->name }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Summary --}}
            @if(count($this->eliminatedModelIds) > 0)
                <x-filament::section>
                    <p class="text-sm text-danger-600 dark:text-danger-400">
                        <strong>{{ count($this->eliminatedModelIds) }}</strong> model(s) will be eliminated:
                        @foreach($this->eliminatedModelIds as $modelId)
                            {{ $this->activeModels->find($modelId)?->name }}@if(!$loop->last), @endif
                        @endforeach
                    </p>
                </x-filament::section>
            @endif

            {{-- Confirm Button --}}
            <div>
                <x-filament::button color="danger" wire:click="confirmEndEpisode" wire:confirm="Are you sure you want to end this episode?">
                    End Episode
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
