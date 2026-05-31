<x-filament-panels::page>
@php
    $branches = $this->getBranches();
    $matrix   = $this->getStockMatrix();
    $rows     = $matrix['rows'];
    $totals   = $matrix['totals'];
    $types    = $matrix['types'];
    $isMulti  = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();
@endphp

<div class="space-y-6">

    {{-- Filters --}}
    @if($isMulti)
    <div class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                <select wire:model.live="branch_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
    @endif

    {{-- Matrix Table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-x-auto">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="font-semibold text-gray-900 dark:text-white">📦 Stock Overview — Full vs Empty by Type</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Latest recorded stock per branch. Colors: <span class="text-green-600">≥50 full</span> · <span class="text-yellow-600">20–49 full</span> · <span class="text-red-600">&lt;20 full</span></p>
        </div>
        @if(empty($rows))
        <div class="px-6 py-8 text-center text-sm text-gray-400">No stock records found.</div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 sticky left-0 bg-gray-50 dark:bg-gray-800">Branch</th>
                    @foreach($types as $type)
                    <th class="px-3 py-3 text-center text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" colspan="3">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $type }}</span>
                    </th>
                    @endforeach
                </tr>
                <tr class="border-t border-gray-100 dark:border-gray-700">
                    <th class="px-4 py-2 text-left text-xs text-gray-400 sticky left-0 bg-gray-50 dark:bg-gray-800"></th>
                    @foreach($types as $type)
                    <th class="px-2 py-2 text-center text-xs text-green-600">Full</th>
                    <th class="px-2 py-2 text-center text-xs text-yellow-600">Empty</th>
                    <th class="px-2 py-2 text-center text-xs text-red-500">Dmg</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white sticky left-0 bg-white dark:bg-gray-900 whitespace-nowrap">
                        {{ $row['branch']->name }}
                        <span class="block text-xs font-normal text-gray-400">{{ $row['branch']->city }}</span>
                    </td>
                    @foreach($types as $type)
                    @php $cell = $row[$type]; @endphp
                    <td class="px-2 py-3 text-center font-semibold {{ $cell['full'] === null ? 'text-gray-300' : ($cell['full'] < 20 ? 'text-red-600' : ($cell['full'] < 50 ? 'text-yellow-600' : 'text-green-600')) }}">
                        {{ $cell['full'] !== null ? number_format($cell['full']) : '—' }}
                    </td>
                    <td class="px-2 py-3 text-center text-yellow-600 dark:text-yellow-400">
                        {{ $cell['empty'] !== null ? number_format($cell['empty']) : '—' }}
                    </td>
                    <td class="px-2 py-3 text-center {{ ($cell['damaged'] !== null && $cell['damaged'] > 0) ? 'text-red-600' : 'text-gray-400' }}">
                        {{ $cell['damaged'] !== null ? number_format($cell['damaged']) : '—' }}
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800">
                <tr>
                    <td class="px-4 py-3 font-bold text-gray-900 dark:text-white sticky left-0 bg-gray-50 dark:bg-gray-800">Totals</td>
                    @foreach($types as $type)
                    <td class="px-2 py-3 text-center font-bold text-green-700 dark:text-green-400">{{ number_format($totals[$type]['full']) }}</td>
                    <td class="px-2 py-3 text-center font-bold text-yellow-700 dark:text-yellow-400">{{ number_format($totals[$type]['empty']) }}</td>
                    <td class="px-2 py-3 text-center font-bold text-red-600 dark:text-red-400">{{ number_format($totals[$type]['damaged']) }}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
</x-filament-panels::page>
