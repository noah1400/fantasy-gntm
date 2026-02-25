<x-filament-panels::page>
    <div class="space-y-6">
        @if($this->hasAlreadySwapped)
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    You have already used your pre-episode swap.
                </div>
            </x-filament::section>
        @elseif($this->upcomingEpisode && $this->lastEndedEpisode)
            <x-filament::section>
                <div>
                    <p class="text-lg font-medium text-info-600 dark:text-info-400">
                        Pre-Episode Swap
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Optionally swap one of your models with a free agent before the next episode airs.
                    </p>
                </div>
            </x-filament::section>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <x-filament::section>
                    <x-slot name="heading">Drop Model</x-slot>

                    <div class="space-y-2">
                        @foreach($this->myActiveModels as $pm)
                            <button
                                wire:click="$set('selectedDropModelId', {{ $pm->topModel->id }})"
                                @class([
                                    'w-full rounded-lg border p-3 text-start transition duration-75',
                                    'border-danger-500 bg-danger-50 dark:border-danger-400 dark:bg-danger-400/10' => $this->selectedDropModelId === $pm->topModel->id,
                                    'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10' => $this->selectedDropModelId !== $pm->topModel->id,
                                ])
                            >
                                <span class="font-medium text-gray-950 dark:text-white">{{ $pm->topModel->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Pick Free Agent</x-slot>

                    <div class="space-y-2">
                        @foreach($this->freeAgents as $model)
                            <button
                                wire:click="$set('selectedPickModelId', {{ $model->id }})"
                                @class([
                                    'w-full rounded-lg border p-3 text-start transition duration-75',
                                    'border-success-500 bg-success-50 dark:border-success-400 dark:bg-success-400/10' => $this->selectedPickModelId === $model->id,
                                    'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10' => $this->selectedPickModelId !== $model->id,
                                ])
                            >
                                <span class="font-medium text-gray-950 dark:text-white">{{ $model->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>
            </div>

            @if($this->selectedDropModelId && $this->selectedPickModelId)
                <div class="flex gap-3">
                    <x-filament::button wire:click="swapModel" color="info" wire:confirm="Confirm this swap?">
                        Confirm Swap
                    </x-filament::button>
                </div>
            @endif
        @else
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No pre-episode swap available at this time.
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
