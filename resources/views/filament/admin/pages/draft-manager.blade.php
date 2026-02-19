<x-filament-panels::page>
    <div class="space-y-6" wire:poll.5s>
        @if($this->season)
            {{-- Season Info --}}
            <x-filament::section>
                <x-slot name="heading">{{ $this->season->name }}</x-slot>
                <x-slot name="description">Status: {{ $this->season->status->getLabel() }}</x-slot>

                <x-slot name="afterHeader">
                    <div class="flex gap-2">
                        @if($this->season->status === \App\Enums\SeasonStatus::Setup)
                            <x-filament::button wire:click="startDraft" color="warning">
                                Start Draft
                            </x-filament::button>
                        @endif
                        @if($this->season->status === \App\Enums\SeasonStatus::Draft && $this->isDraftComplete)
                            <x-filament::button wire:click="activateSeason" color="success">
                                Activate Season
                            </x-filament::button>
                        @endif
                    </div>
                </x-slot>
            </x-filament::section>

            @if($this->season->status === \App\Enums\SeasonStatus::Setup)
                {{-- Draft Order Management --}}
                <x-filament::section>
                    <x-slot name="heading">Draft Order</x-slot>
                    <x-slot name="description">Set the pick order for the draft. Players are picked from the season's roster.</x-slot>

                    <x-slot name="afterHeader">
                        <x-filament::button wire:click="randomizeDraftOrder" color="gray" size="sm">
                            Randomize
                        </x-filament::button>
                    </x-slot>

                    @if($this->draftOrderPlayers->isEmpty())
                        <div class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No players in this season. Add players to the season first.
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($this->draftOrderPlayers as $index => $player)
                                <div class="flex items-center gap-3 rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                                        {{ $index + 1 }}
                                    </span>
                                    <span class="flex-1 text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $player->name }}
                                    </span>
                                    <div class="flex gap-1">
                                        <x-filament::icon-button
                                            icon="heroicon-m-chevron-up"
                                            wire:click="moveDraftOrderUp({{ $index }})"
                                            size="sm"
                                            color="gray"
                                            :disabled="$index === 0"
                                            label="Move up"
                                        />
                                        <x-filament::icon-button
                                            icon="heroicon-m-chevron-down"
                                            wire:click="moveDraftOrderDown({{ $index }})"
                                            size="sm"
                                            color="gray"
                                            :disabled="$index === count($this->draftOrderUserIds) - 1"
                                            label="Move down"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endif

            @if($this->season->status === \App\Enums\SeasonStatus::Draft)
                {{-- Current Drafter --}}
                @if($this->currentDrafter)
                    <x-filament::section>
                        <div class="text-lg font-medium text-warning-600 dark:text-warning-400">
                            Current Pick: <strong>{{ $this->currentDrafter->name }}</strong>
                            (Pick #{{ $this->draftService->getCurrentPickNumber($this->season) }})
                        </div>
                    </x-filament::section>
                @endif

                {{-- Available Models --}}
                <x-filament::section>
                    <x-slot name="heading">Available Models</x-slot>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        @foreach($this->availableModels as $model)
                            <button
                                wire:click="forcePick({{ $model->id }})"
                                class="flex flex-col items-center rounded-xl bg-white p-5 shadow-sm border border-gray-200 shadow-sm transition duration-75 hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-white/5"
                                @if(!$this->currentDrafter) disabled @endif
                            >
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $model->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            {{-- Draft History --}}
            @if($this->season->draftPicks->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Draft History</x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell">Pick</th>
                                    <th class="fi-ta-header-cell">Round</th>
                                    <th class="fi-ta-header-cell">Player</th>
                                    <th class="fi-ta-header-cell">Model</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($this->season->draftPicks->sortBy('pick_number') as $pick)
                                    <tr>
                                        <td class="fi-ta-header-cell font-normal">#{{ $pick->pick_number }}</td>
                                        <td class="fi-ta-header-cell font-normal">{{ $pick->round }}</td>
                                        <td class="fi-ta-header-cell font-normal">{{ $pick->user->name }}</td>
                                        <td class="fi-ta-header-cell font-normal">{{ $pick->topModel->name }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            {{-- Draft Order --}}
            @if($this->season->draftOrders->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Draft Order</x-slot>

                    <div class="flex flex-wrap gap-2">
                        @foreach($this->season->draftOrders->sortBy('position') as $order)
                            <x-filament::badge>
                                {{ $order->position }}. {{ $order->user->name }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @else
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No season found. Create a season first.
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
