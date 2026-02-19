<x-filament-widgets::widget>
    @php $data = $this->getDraftData(); @endphp

    @if($data['active'])
        <x-filament::section>
            @if($data['isDraftComplete'])
                <div>
                    <p class="text-lg font-bold text-info-600 dark:text-info-400">Draft Complete!</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Waiting for the season to be activated.</p>
                </div>
            @elseif($data['isMyTurn'])
                <div>
                    <p class="text-lg font-bold text-success-600 dark:text-success-400">It's your turn to pick!</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Pick #{{ $data['pickNumber'] }} - Head to the Draft Room to make your selection.</p>
                    <a href="{{ \App\Filament\Player\Pages\DraftRoom::getUrl() }}" class="mt-2 inline-block text-sm font-medium text-primary-600 underline dark:text-primary-400">
                        Go to Draft Room &rarr;
                    </a>
                </div>
            @else
                <div>
                    <p class="text-lg font-bold text-warning-600 dark:text-warning-400">Draft in Progress</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Pick #{{ $data['pickNumber'] }} - Waiting for <strong>{{ $data['currentDrafter']?->name ?? 'Unknown' }}</strong> to pick.
                    </p>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
