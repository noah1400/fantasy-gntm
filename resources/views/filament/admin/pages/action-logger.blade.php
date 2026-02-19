<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Episode Selector --}}
        <div class="flex items-center gap-4">
            <x-filament::input.wrapper class="flex-1">
                <x-filament::input.select wire:model.live="selectedEpisodeId">
                    <option value="">-- Select Episode --</option>
                    @foreach($this->episodes as $episode)
                        <option value="{{ $episode->id }}">Episode {{ $episode->number }}: {{ $episode->title }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            @if($this->lastAction)
                <x-filament::button color="warning" wire:click="undoLastAction">
                    Undo Last
                </x-filament::button>
            @endif
        </div>

        @if(!$this->selectedTopModelId)
            {{-- Step 1: Select a Model --}}
            <x-filament::section>
                <x-slot name="heading">Select a Model</x-slot>

                <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($this->topModels as $topModel)
                        <button
                            wire:click="selectModel({{ $topModel->id }})"
                            class="flex flex-col items-center rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition duration-75 hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-white/5"
                        >
                            @if($topModel->image)
                                <img src="{{ Storage::url($topModel->image) }}" alt="{{ $topModel->name }}" class="mb-3 size-20 rounded-full object-cover">
                            @else
                                <div class="mb-3 flex size-20 items-center justify-center rounded-full bg-gray-100 text-2xl font-bold text-gray-400 dark:bg-white/10">
                                    {{ substr($topModel->name, 0, 1) }}
                                </div>
                            @endif
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $topModel->name }}</span>
                        </button>
                    @endforeach
                </div>
            </x-filament::section>
        @else
            {{-- Step 2: Select an Action --}}
            @php $selectedModel = $this->topModels->find($this->selectedTopModelId); @endphp
            <x-filament::section>
                <x-slot name="heading">
                    Actions for: {{ $selectedModel?->name }}
                </x-slot>

                <x-slot name="afterHeader">
                    <x-filament::button color="gray" size="sm" wire:click="$set('selectedTopModelId', null)">
                        Back
                    </x-filament::button>
                </x-slot>

                <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4">
                    @foreach($this->actions as $action)
                        <button
                            wire:click="logAction({{ $action->id }})"
                            class="flex flex-col items-center rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition duration-75 hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-white/5"
                        >
                            <span class="text-base font-semibold text-gray-950 dark:text-white">{{ $action->name }}</span>
                            <span class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $action->multiplier }}x</span>
                        </button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
