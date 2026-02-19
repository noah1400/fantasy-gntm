<x-filament-panels::page>
    <div class="space-y-6" wire:poll.3s>
        @if($this->season)
            {{-- Draft Status --}}
            <x-filament::section>
                @if($this->isDraftComplete)
                    <div>
                        <p class="text-lg font-bold text-info-600 dark:text-info-400">Draft is complete! Waiting for season activation.</p>
                    </div>
                @elseif($this->isMyTurn)
                    <div>
                        <p class="text-lg font-bold text-success-600 dark:text-success-400">It's your turn! Pick a model below.</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Pick #{{ $this->draftService->getCurrentPickNumber($this->season) }}</p>
                    </div>
                @else
                    <div>
                        <p class="text-lg font-bold text-warning-600 dark:text-warning-400">
                            Waiting for <strong>{{ $this->currentDrafter?->name ?? 'Unknown' }}</strong> to pick...
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Pick #{{ $this->draftService->getCurrentPickNumber($this->season) }}</p>
                    </div>
                @endif
            </x-filament::section>

            {{-- Available Models --}}
            @if(!$this->isDraftComplete)
                <x-filament::section>
                    <x-slot name="heading">Available Models</x-slot>

                    <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        @foreach($this->availableModels as $model)
                            <button
                                wire:click="pickModel({{ $model->id }})"
                                @class([
                                    'flex flex-col items-center rounded-xl border p-6 shadow-sm transition duration-75',
                                    'border-gray-200 bg-white hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-white/5 cursor-pointer' => $this->isMyTurn,
                                    'border-gray-200 bg-gray-50 opacity-50 cursor-not-allowed dark:border-white/10 dark:bg-gray-900' => !$this->isMyTurn,
                                ])
                                @if(!$this->isMyTurn) disabled @endif
                                wire:confirm="Pick {{ $model->name }}?"
                            >
                                @if($model->image)
                                    <img src="{{ Storage::url($model->image) }}" alt="{{ $model->name }}" class="mb-3 size-20 rounded-full object-cover">
                                @else
                                    <div class="mb-3 flex size-20 items-center justify-center rounded-full bg-primary-50 text-2xl font-bold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                        {{ substr($model->name, 0, 1) }}
                                    </div>
                                @endif
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $model->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            {{-- Draft History --}}
            @php $picks = $this->season->draftPicks()->with(['user', 'topModel'])->orderBy('pick_number')->get(); @endphp
            @if($picks->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Draft History</x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell">Pick</th>
                                    <th class="fi-ta-header-cell">Player</th>
                                    <th class="fi-ta-header-cell">Model</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($picks as $pick)
                                    <tr @class([
                                        'bg-primary-50 dark:bg-primary-400/10' => $pick->user_id === auth()->id(),
                                    ])>
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">#{{ $pick->pick_number }}</td>
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $pick->user->name }}</td>
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $pick->topModel->name }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @else
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No active draft found.
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
