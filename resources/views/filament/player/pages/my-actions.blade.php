<x-filament-panels::page>
    <div class="space-y-6">
        @if($this->myAction)
            {{-- Action Header --}}
            <x-filament::section>
                <div>
                    <p class="text-lg font-medium text-warning-600 dark:text-warning-400">
                        Action Required: {{ ucfirst(str_replace('_', ' ', $this->myAction['action'])) }}
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->myAction['reason'] }}</p>
                </div>
            </x-filament::section>

            @if($this->myAction['action'] === 'free_agent_pick')
                {{-- Free Agent Pick --}}
                <x-filament::section>
                    <x-slot name="heading">Pick a Free Agent</x-slot>

                    <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        @foreach($this->freeAgents as $model)
                            <button
                                wire:click="pickFreeAgent({{ $model->id }})"
                                wire:confirm="Pick {{ $model->name }} as free agent?"
                                class="flex flex-col items-center rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition duration-75 hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-white/5"
                            >
                                @if($model->image)
                                    <img src="{{ Storage::disk('public')->url($model->image) }}" alt="{{ $model->name }}" class="mx-auto mb-2 size-16 rounded-full object-cover">
                                @else
                                    <div class="mx-auto mb-2 flex size-16 items-center justify-center rounded-full bg-success-50 text-xl font-bold text-success-600 dark:bg-success-400/10 dark:text-success-400">
                                        {{ substr($model->name, 0, 1) }}
                                    </div>
                                @endif
                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $model->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>

            @elseif($this->myAction['action'] === 'mandatory_drop')
                {{-- Mandatory Drop --}}
                <x-filament::section>
                    <x-slot name="heading">Drop a Model</x-slot>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">You must drop a model to continue.</p>

                    <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        @foreach($this->myActiveModels as $pm)
                            <button
                                wire:click="mandatoryDrop({{ $pm->topModel->id }})"
                                wire:confirm="Drop {{ $pm->topModel->name }}?"
                                class="flex flex-col items-center rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition duration-75 hover:border-danger-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-danger-500 dark:hover:bg-white/5"
                            >
                                @if($pm->topModel->image)
                                    <img src="{{ Storage::disk('public')->url($pm->topModel->image) }}" alt="{{ $pm->topModel->name }}" class="mx-auto mb-2 size-16 rounded-full object-cover">
                                @else
                                    <div class="mx-auto mb-2 flex size-16 items-center justify-center rounded-full bg-danger-50 text-xl font-bold text-danger-600 dark:bg-danger-400/10 dark:text-danger-400">
                                        {{ substr($pm->topModel->name, 0, 1) }}
                                    </div>
                                @endif
                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $pm->topModel->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>

            @elseif(in_array($this->myAction['action'], ['optional_swap', 'trading_swap']))
                {{-- Swap (Optional or Trading) --}}
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

                <div class="flex gap-3">
                    @if($this->selectedDropModelId && $this->selectedPickModelId)
                        <x-filament::button wire:click="swapModel" color="warning" wire:confirm="Confirm this swap?">
                            Confirm Swap
                        </x-filament::button>
                    @endif

                    @if($this->myAction['action'] === 'optional_swap')
                        <x-filament::button wire:click="skipSwap" color="gray" wire:confirm="Skip your swap opportunity?">
                            Skip Swap
                        </x-filament::button>
                    @endif
                </div>

            @elseif($this->myAction['action'] === 'waiting')
                {{-- Waiting --}}
                <x-filament::section>
                    <div class="py-8 text-center">
                        <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-filament::icon icon="heroicon-o-clock" class="size-8 text-gray-400" />
                        </div>
                        <p class="text-lg font-medium text-gray-950 dark:text-white">Waiting for your turn</p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $this->myAction['reason'] }}</p>
                    </div>
                </x-filament::section>
            @endif
        @else
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No actions required at this time.
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
