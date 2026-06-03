<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Filters --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Filter / Filter Laporan
            </h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                @if(auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader())
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Branch / Cabang
                    </label>
                    <select
                        wire:model.live="branch_id"
                        class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="">All Branches</option>
                        @foreach($this->getBranches() as $branch)
                            <option value="{{ $branch->id }}" @selected($this->branch_id == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Start Date / Dari
                    </label>
                    <input
                        type="date"
                        wire:model.live="start_date"
                        value="{{ $this->start_date }}"
                        class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        End Date / Sampai
                    </label>
                    <input
                        type="date"
                        wire:model.live="end_date"
                        value="{{ $this->end_date }}"
                        class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                </div>

            </div>
        </div>

        {{-- Report --}}
        @php
            $data = $this->getReportData();
            $fmt  = fn($n) => 'Rp ' . number_format($n, 0, ',', '.');
        @endphp

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            {{-- Revenue Section --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-white">💰 Revenue / Pendapatan</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ \Illuminate\Support\Carbon::parse($data['start_date'])->format('d M Y') }}
                        –
                        {{ \Illuminate\Support\Carbon::parse($data['end_date'])->format('d M Y') }}
                    </p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($data['revenue_by_type'] as $type => $row)
                    <div class="flex items-center justify-between px-6 py-3">
                        <div>
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ $type }}
                            </span>
                            <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">
                                {{ number_format($row->qty) }} pcs
                            </span>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-white">{{ $fmt($row->total) }}</span>
                    </div>
                    @empty
                    <div class="px-6 py-8 text-center text-sm text-gray-400">
                        No sales data for this period.
                    </div>
                    @endforelse
                    <div class="flex items-center justify-between bg-green-50 px-6 py-4 dark:bg-green-900/20">
                        <span class="font-semibold text-gray-900 dark:text-white">Total Revenue</span>
                        <span class="text-lg font-bold text-green-600 dark:text-green-400">{{ $fmt($data['total_revenue']) }}</span>
                    </div>
                </div>
            </div>

            {{-- Cost Section --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-white">📉 Costs / Biaya Operasional</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Operational expenses by category</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($data['costs_by_category'] as $category => $amount)
                    <div class="flex items-center justify-between px-6 py-3">
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            {{ $data['category_labels'][$category] ?? $category }}
                        </span>
                        <span class="font-medium text-red-600 dark:text-red-400">{{ $fmt($amount) }}</span>
                    </div>
                    @empty
                    <div class="px-6 py-8 text-center text-sm text-gray-400">
                        No cost data for this period.
                    </div>
                    @endforelse
                    <div class="flex items-center justify-between bg-red-50 px-6 py-4 dark:bg-red-900/20">
                        <span class="font-semibold text-gray-900 dark:text-white">Total Costs</span>
                        <span class="text-lg font-bold text-red-600 dark:text-red-400">{{ $fmt($data['total_cost_ops']) }}</span>
                    </div>
                </div>
            </div>

        </div>

        {{-- Summary --}}
        <div class="rounded-xl border-2 {{ $data['gross_profit'] >= 0 ? 'border-green-400 bg-green-50 dark:border-green-600 dark:bg-green-900/20' : 'border-red-400 bg-red-50 dark:border-red-600 dark:bg-red-900/20' }} p-6">
            <h3 class="mb-4 text-center text-sm font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                Bottom Line / Hasil Akhir
            </h3>
            <div class="grid grid-cols-1 gap-4 text-center sm:grid-cols-3">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Revenue</p>
                    <p class="text-xl font-bold text-green-600 dark:text-green-400">{{ $fmt($data['total_revenue']) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Costs</p>
                    <p class="text-xl font-bold text-red-600 dark:text-red-400">{{ $fmt($data['total_cost_ops']) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $data['gross_profit'] >= 0 ? '✅ Gross Profit / Laba' : '❌ Loss / Rugi' }}
                    </p>
                    <p class="text-2xl font-bold {{ $data['gross_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $fmt(abs($data['gross_profit'])) }}
                    </p>
                    <p class="mt-1 text-sm font-medium text-gray-500 dark:text-gray-400">
                        Margin: {{ $data['margin'] }}%
                    </p>
                </div>
            </div>
        </div>

    </div>
</x-filament-panels::page>
