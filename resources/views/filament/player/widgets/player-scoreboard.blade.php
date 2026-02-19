<x-filament-widgets::widget>
    <x-filament::section heading="Scoreboard">
        @php $scoreboard = $this->getScoreboardData(); @endphp

        @if(empty($scoreboard))
            <p class="text-sm text-gray-500 dark:text-gray-400">No active season.</p>
        @else
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="fi-ta-table">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="fi-ta-header-cell">#</th>
                            <th class="fi-ta-header-cell">Player</th>
                            <th class="fi-ta-header-cell fi-align-end">Points</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach($scoreboard as $index => $entry)
                            <tr @class([
                                'bg-primary-50 dark:bg-primary-400/10' => $entry['user']->id === auth()->id(),
                            ])>
                                <td class="px-3 py-4 text-sm font-medium text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $index + 1 }}</td>
                                <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">
                                    {{ $entry['user']->name }}
                                    @if($entry['user']->id === auth()->id())
                                        <span class="text-xs text-primary-600 dark:text-primary-400">(You)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-4 text-end text-sm font-bold text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($entry['points'], 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
