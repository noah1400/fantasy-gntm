<x-filament-panels::page>
    <div class="space-y-6">
        @if($this->season)
            {{-- Player Status Overview --}}
            <x-filament::section>
                <x-slot name="heading">Player Status</x-slot>
                <x-slot name="description">Active players and their models ({{ $this->freeAgents->count() }} free agent(s) available)</x-slot>

                <div class="space-y-3">
                    @forelse($this->playerStatus as $status)
                        <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <div>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $status['user']->name }}</span>
                                <span class="ml-2 text-sm text-gray-500 dark:text-gray-400">{{ number_format($status['points'], 1) }} pts</span>
                            </div>
                            <div class="flex gap-2">
                                @foreach($status['active_models'] as $pm)
                                    <x-filament::badge>{{ $pm->topModel->name }}</x-filament::badge>
                                @endforeach
                                @if($status['active_models']->isEmpty())
                                    <x-filament::badge color="danger">No models</x-filament::badge>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No active players.</p>
                    @endforelse
                </div>
            </x-filament::section>

            {{-- Phase Queue --}}
            <x-filament::section>
                <x-slot name="heading">Phase Queue</x-slot>

                @if($this->phaseQueue->isNotEmpty())
                    <div class="space-y-2">
                        @foreach($this->phaseQueue as $phase)
                            <div @class([
                                'flex items-center justify-between rounded-lg p-4 shadow-sm ring-1',
                                'bg-success-50 ring-success-500/20 dark:bg-success-500/10 dark:ring-success-400/20' => $phase->status === \App\Enums\GamePhaseStatus::Active,
                                'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => $phase->status === \App\Enums\GamePhaseStatus::Pending,
                            ])>
                                <div class="flex items-center gap-3">
                                    <x-filament::badge :color="$phase->status->getColor()">{{ $phase->status->getLabel() }}</x-filament::badge>
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $phase->type->getLabel() }}</span>
                                    @if($phase->config)
                                        <span class="text-sm text-gray-500 dark:text-gray-400">
                                            @if(isset($phase->config['target_model_count']))
                                                (target: {{ $phase->config['target_model_count'] }})
                                            @elseif(isset($phase->config['eligible_below']))
                                                (eligible below: {{ $phase->config['eligible_below'] }})
                                            @endif
                                        </span>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    @if($phase->status === \App\Enums\GamePhaseStatus::Active)
                                        <x-filament::button size="sm" color="success" wire:click="closePhase({{ $phase->id }})" wire:confirm="Close this phase?">
                                            Close
                                        </x-filament::button>
                                    @endif
                                    @if($phase->status === \App\Enums\GamePhaseStatus::Pending)
                                        <x-filament::button size="sm" color="danger" wire:click="cancelPhase({{ $phase->id }})" wire:confirm="Cancel this phase?">
                                            Cancel
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">No active or pending phases.</p>
                @endif
            </x-filament::section>

            {{-- Add Phase --}}
            <x-filament::section>
                <x-slot name="heading">Add Phase</x-slot>

                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="newPhaseType">
                                <option value="">-- Select Phase Type --</option>
                                @foreach(\App\Enums\GamePhaseType::cases() as $type)
                                    @if(! $type->isInstant())
                                        <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                                    @endif
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    @if($this->newPhaseType === 'mandatory_drop')
                        <div>
                            <label class="text-sm font-medium text-gray-950 dark:text-white">Target model count</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" wire:model="newPhaseTargetModelCount" min="0" max="5" />
                            </x-filament::input.wrapper>
                        </div>
                    @endif

                    @if($this->newPhaseType === 'pick_round')
                        <div>
                            <label class="text-sm font-medium text-gray-950 dark:text-white">Eligible below (models)</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" wire:model="newPhaseEligibleBelow" min="1" max="5" />
                            </x-filament::input.wrapper>
                        </div>
                    @endif

                    <x-filament::button wire:click="addPhase" :disabled="!$this->newPhaseType">
                        Add Phase
                    </x-filament::button>
                </div>
            </x-filament::section>

            {{-- Quick Actions --}}
            <x-filament::section>
                <x-slot name="heading">Quick Actions</x-slot>

                <div class="grid gap-6 md:grid-cols-2">
                    {{-- Force Assign --}}
                    <div class="space-y-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 class="text-sm font-medium text-gray-950 dark:text-white">Force Assign Model</h3>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="forceAssignUserId">
                                <option value="">-- Player --</option>
                                @foreach($this->playerStatus as $status)
                                    <option value="{{ $status['user']->id }}">{{ $status['user']->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="forceAssignModelId">
                                <option value="">-- Free Agent --</option>
                                @foreach($this->freeAgents as $model)
                                    <option value="{{ $model->id }}">{{ $model->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::button size="sm" wire:click="forceAssign" wire:confirm="Force assign this model?">
                            Assign
                        </x-filament::button>
                    </div>

                    {{-- Eliminate Player --}}
                    <div class="space-y-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h3 class="text-sm font-medium text-gray-950 dark:text-white">Eliminate Player</h3>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="eliminatePlayerId">
                                <option value="">-- Player --</option>
                                @foreach($this->playerStatus as $status)
                                    <option value="{{ $status['user']->id }}">{{ $status['user']->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::button size="sm" color="danger" wire:click="eliminatePlayer" wire:confirm="Eliminate this player?">
                            Eliminate
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            {{-- Completed Phases --}}
            @if($this->completedPhases->isNotEmpty())
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Recent Completed Phases</x-slot>

                    <div class="space-y-2">
                        @foreach($this->completedPhases as $phase)
                            <div class="flex items-center justify-between rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="flex items-center gap-3">
                                    <x-filament::badge :color="$phase->status->getColor()">{{ $phase->status->getLabel() }}</x-filament::badge>
                                    <span class="text-sm text-gray-950 dark:text-white">{{ $phase->type->getLabel() }}</span>
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $phase->completed_at?->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @else
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No active season found.
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
