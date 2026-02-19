<x-filament-panels::page>
    @php $leaderboard = $this->getLeaderboardData(); @endphp

    @if($leaderboard->isEmpty())
        <x-filament::section>
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No active season found.</div>
        </x-filament::section>
    @else
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="fi-ta-table">
                <thead>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th class="fi-ta-header-cell">#</th>
                        <th class="fi-ta-header-cell">Model</th>
                        <th class="fi-ta-header-cell">Owner</th>
                        <th class="fi-ta-header-cell">Status</th>
                        <th class="fi-ta-header-cell fi-align-end">Points</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                    @foreach($leaderboard as $index => $entry)
                        <tr>
                            <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $index + 1 }}</td>
                            <td class="px-3 py-4 first-of-type:ps-6 last-of-type:pe-6">
                                <div class="flex items-center gap-3">
                                    @if($entry['top_model']->image)
                                        <img src="{{ Storage::url($entry['top_model']->image) }}" alt="{{ $entry['top_model']->name }}" class="size-8 rounded-full object-cover">
                                    @else
                                        <div class="flex size-8 items-center justify-center rounded-full bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                            {{ substr($entry['top_model']->name, 0, 1) }}
                                        </div>
                                    @endif
                                    <a href="{{ \App\Filament\Player\Pages\ModelDetail::getUrl(['topModel' => $entry['top_model']->slug]) }}" class="text-sm font-medium text-gray-950 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">
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
                            <td class="px-3 py-4 text-end text-sm font-bold text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($entry['points'], 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
