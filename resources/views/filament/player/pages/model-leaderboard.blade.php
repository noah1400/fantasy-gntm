<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Filters</x-slot>
            <x-slot name="description">Season is required. Episode and model are optional.</x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Season</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedSeasonId">
                            <option value="">-- Select Season --</option>
                            @foreach($this->seasons as $season)
                                <option value="{{ $season->id }}">{{ $season->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Episode (optional)</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedEpisodeId" :disabled="!$this->selectedSeasonId">
                            <option value="">-- All Episodes --</option>
                            @foreach($this->episodes as $episode)
                                <option value="{{ $episode->id }}">
                                    Episode {{ $episode->number }}@if($episode->title): {{ $episode->title }}@endif
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Model (optional)</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedTopModelId" :disabled="!$this->selectedSeasonId">
                            <option value="">-- All Models --</option>
                            @foreach($this->topModels as $model)
                                <option value="{{ $model->id }}">{{ $model->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        @php $leaderboard = $this->getLeaderboardData(); @endphp

        @if(! $this->selectedSeasonId)
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Select a season to view leaderboard and statistics.</div>
            </x-filament::section>
        @else
            @if($this->selectedEpisode && ! $this->selectedTopModel)
                <x-filament::section>
                    <x-slot name="heading">Episode Model Points</x-slot>
                    <x-slot name="description">All models and their points in Episode {{ $this->selectedEpisode->number }}.</x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell">Model</th>
                                    <th class="fi-ta-header-cell fi-align-end">Logged Actions</th>
                                    <th class="fi-ta-header-cell fi-align-end">Points</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($this->episodeModelPoints as $entry)
                                    <tr wire:key="episode-model-points-{{ $entry['top_model']->id }}">
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $entry['top_model']->name }}</td>
                                        <td class="px-3 py-4 text-end text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">{{ $entry['action_count'] }}</td>
                                        <td class="px-3 py-4 text-end text-sm font-medium text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($entry['points'], 1) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @elseif($this->selectedTopModel && ! $this->selectedEpisode)
                <x-filament::section>
                    <x-slot name="heading">Model Episode Points</x-slot>
                    <x-slot name="description">{{ $this->selectedTopModel->name }} points by episode.</x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell">Episode</th>
                                    <th class="fi-ta-header-cell fi-align-end">Logged Actions</th>
                                    <th class="fi-ta-header-cell fi-align-end">Points</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($this->modelEpisodePoints as $entry)
                                    <tr wire:key="model-episode-points-{{ $entry['episode']->id }}">
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">
                                            Episode {{ $entry['episode']->number }}@if($entry['episode']->title): {{ $entry['episode']->title }}@endif
                                        </td>
                                        <td class="px-3 py-4 text-end text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">{{ $entry['action_count'] }}</td>
                                        <td class="px-3 py-4 text-end text-sm font-medium text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($entry['points'], 1) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @elseif($this->selectedTopModel && $this->selectedEpisode)
                <x-filament::section>
                    <x-slot name="heading">Episode Action Breakdown</x-slot>
                    <x-slot name="description">
                        {{ $this->selectedTopModel->name }} in Episode {{ $this->selectedEpisode->number }}.
                    </x-slot>
                    <x-slot name="afterHeader">
                        <span class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ number_format($this->modelEpisodeActionBreakdownTotalPoints, 1) }} pts total</span>
                    </x-slot>

                    @if($this->modelEpisodeActionBreakdown->isNotEmpty())
                        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <table class="fi-ta-table">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-white/5">
                                        <th class="fi-ta-header-cell">Action</th>
                                        <th class="fi-ta-header-cell fi-align-end">Count</th>
                                        <th class="fi-ta-header-cell fi-align-end">Multiplier</th>
                                        <th class="fi-ta-header-cell fi-align-end">Points</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                    @foreach($this->modelEpisodeActionBreakdown as $log)
                                        <tr wire:key="model-episode-breakdown-{{ $log->id }}">
                                            <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $log->action->name }}</td>
                                            <td class="px-3 py-4 text-end text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">{{ $log->count }}</td>
                                            <td class="px-3 py-4 text-end text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">{{ number_format((float) $log->action->multiplier, 2) }}x</td>
                                            <td class="px-3 py-4 text-end text-sm font-medium text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($log->count * (float) $log->action->multiplier, 1) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">No actions logged for this model in this episode.</p>
                    @endif
                </x-filament::section>
            @else
                <x-filament::section>
                    <div class="py-3 text-sm text-gray-500 dark:text-gray-400">
                        Optional statistics: select an episode and/or a model to see detailed breakdowns.
                    </div>
                </x-filament::section>
            @endif

            @if($leaderboard->isEmpty())
                <x-filament::section>
                    <x-slot name="heading">Leaderboard</x-slot>
                    <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No models found for this season.</div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-slot name="heading">Leaderboard</x-slot>
                    <x-slot name="description">Total points with last episode points in parentheses.</x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell">#</th>
                                    <th class="fi-ta-header-cell">Model</th>
                                    <th class="fi-ta-header-cell">Owner</th>
                                    <th class="fi-ta-header-cell">Status</th>
                                    <th class="fi-ta-header-cell fi-align-end">Points (Last Episode)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($leaderboard as $index => $entry)
                                    <tr wire:key="leaderboard-row-{{ $entry['top_model']->id }}">
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $index + 1 }}</td>
                                        <td class="px-3 py-4 first-of-type:ps-6 last-of-type:pe-6">
                                            <div class="flex items-center gap-3">
                                                @if($entry['top_model']->image)
                                                    <img src="{{ Storage::disk('public')->url($entry['top_model']->image) }}" alt="{{ $entry['top_model']->name }}" class="size-8 rounded-full object-cover">
                                                @else
                                                    <div class="flex size-8 items-center justify-center rounded-full bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                                        {{ substr($entry['top_model']->name, 0, 1) }}
                                                    </div>
                                                @endif
                                                <a
                                                    href="{{ \App\Filament\Player\Pages\ModelDetail::getUrl(['topModel' => $entry['top_model']->slug], panel: 'player') }}"
                                                    class="text-sm font-medium text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
                                                >
                                                    {{ $entry['top_model']->name }}
                                                </a>
                                            </div>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">
                                            {{ $entry['owner']?->name ?? 'Free Agent' }}
                                        </td>
                                        <td class="px-3 py-4 first-of-type:ps-6 last-of-type:pe-6">
                                            @if($entry['top_model']->is_eliminated)
                                                <x-filament::badge color="danger">Eliminated</x-filament::badge>
                                            @else
                                                <x-filament::badge color="success">Active</x-filament::badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-4 text-end text-sm font-bold text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">
                                            {{ number_format($entry['points'], 1) }} ({{ number_format($entry['last_episode_points'], 1) }})
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
