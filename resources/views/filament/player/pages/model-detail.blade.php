<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Model Info --}}
        <x-filament::section>
            <div class="flex items-center gap-4">
                @if($this->topModel->image)
                    <img src="{{ Storage::url($this->topModel->image) }}" alt="{{ $this->topModel->name }}" class="size-20 rounded-full object-cover">
                @else
                    <div class="flex size-20 items-center justify-center rounded-full bg-primary-50 text-2xl font-bold text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        {{ substr($this->topModel->name, 0, 1) }}
                    </div>
                @endif
                <div>
                    <h2 class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->topModel->name }}</h2>
                    <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ number_format($this->getTotalPoints(), 1) }} total points</p>
                    @if($this->topModel->is_eliminated)
                        <x-filament::badge color="danger">Eliminated</x-filament::badge>
                    @else
                        <x-filament::badge color="success">Active</x-filament::badge>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- Episode Breakdown --}}
        @foreach($this->getEpisodeBreakdown() as $data)
            @if($data['logs']->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">
                        Episode {{ $data['episode']->number }}
                        @if($data['episode']->title)
                            - {{ $data['episode']->title }}
                        @endif
                    </x-slot>

                    <x-slot name="afterHeader">
                        <span class="font-bold text-primary-600 dark:text-primary-400">{{ number_format($data['points'], 1) }} pts</span>
                    </x-slot>

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <table class="fi-ta-table">
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($data['logs'] as $log)
                                    <tr>
                                        <td class="px-3 py-4 text-sm text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ $log->action->name }}</td>
                                        <td class="px-3 py-4 text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">x{{ $log->count }}</td>
                                        <td class="px-3 py-4 text-sm text-gray-500 first-of-type:ps-6 last-of-type:pe-6 dark:text-gray-400">{{ $log->action->multiplier }}x multiplier</td>
                                        <td class="px-3 py-4 text-end text-sm font-medium text-gray-950 first-of-type:ps-6 last-of-type:pe-6 dark:text-white">{{ number_format($log->count * $log->action->multiplier, 1) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
