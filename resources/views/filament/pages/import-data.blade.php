<x-filament-panels::page>
    @if ($showStatus)
        <x-filament::section title="📊 Import Status" description="Current data counts per table">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($this->getTableStatus() as $status)
                    <div class="rounded-lg border p-3 dark:border-gray-700 {{ $status['imported'] ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-gray-50 dark:bg-gray-800/50' }}">
                        <div class="text-sm font-medium {{ $status['imported'] ? 'text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-gray-400' }}">
                            {{ $status['imported'] ? '✅' : '⬜' }} {{ $status['label'] }}
                        </div>
                        <div class="text-lg font-bold {{ $status['imported'] ? 'text-green-900 dark:text-green-300' : 'text-gray-700 dark:text-gray-300' }}">
                            {{ $status['count'] }} rows
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament-panels::form wire:submit="import">
        {{ $this->form }}

        <div class="flex gap-3 flex-wrap">
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-arrow-down-tray"
                wire:click="downloadAllTemplates"
                tag="button"
            >
                Download All Templates (ZIP)
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-document-arrow-down"
                wire:click="downloadTemplate"
                tag="button"
            >
                Download Selected Template
            </x-filament::button>

            <x-filament::button
                type="submit"
                color="primary"
                icon="heroicon-o-arrow-up-tray"
                tag="button"
            >
                Import Data
            </x-filament::button>
        </div>
    </x-filament-panels::form>

    @if ($showResult)
        <div class="mt-6">
            @if ($result['success'])
                <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
                    <x-slot name="heading">
                        ✅ {{ $result['message'] }}
                    </x-slot>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $result['imported'] }} row(s) imported successfully.
                    </p>
                </x-filament::section>
            @else
                <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                    <x-slot name="heading">
                        ❌ {{ $result['message'] }}
                    </x-slot>

                    @if (! empty($result['errors']))
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b dark:border-gray-700">
                                        <th class="px-3 py-2 text-left font-medium">Row</th>
                                        <th class="px-3 py-2 text-left font-medium">Field</th>
                                        <th class="px-3 py-2 text-left font-medium">Error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($result['errors'] as $error)
                                        <tr class="border-b dark:border-gray-700">
                                            <td class="px-3 py-2">{{ $error['row'] }}</td>
                                            <td class="px-3 py-2">
                                                <x-filament::badge color="danger">{{ $error['field'] }}</x-filament::badge>
                                            </td>
                                            <td class="px-3 py-2">{{ $error['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>