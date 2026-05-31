<x-filament-panels::page>
    @php
        $branches = $this->getBranches();
        $shipments = $this->getShipments();
        $isMulti = auth()->user()->isOwnerPusat() || auth()->user()->isRegionalLeader();

        $statusColors = [
            'draft' => 'gray',
            'pending_approval' => 'warning',
            'approved' => 'info',
            'in_transit' => 'primary',
            'delivered' => 'success',
            'cancelled' => 'danger',
        ];
        $statusLabels = [
            'draft' => 'Draft',
            'pending_approval' => 'Pending',
            'approved' => 'Approved',
            'in_transit' => 'In Transit',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
        ];
    @endphp

    <div class="space-y-6">

        {{-- Filters --}}
        <div
            class="rounded-xl border border-gray-200 bg-white px-6 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-4">
                @if($isMulti)
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
                @endif
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                    <select wire:model.live="status_filter"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        <option value="active">Active (Approved + In Transit)</option>
                        <option value="all">All Statuses</option>
                        <option value="pending_approval">Pending Approval</option>
                        <option value="approved">Approved</option>
                        <option value="in_transit">In Transit</option>
                        <option value="delivered">Delivered</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Summary Counts --}}
        @php
            $countByStatus = $shipments->groupBy('status')->map->count();
        @endphp
        <div class="flex flex-wrap gap-3">
            @foreach(['approved' => 'Approved', 'in_transit' => 'In Transit', 'pending_approval' => 'Pending'] as $s => $label)
                @php $cnt = $countByStatus->get($s, 0); @endphp
                <div
                    class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <span class="font-bold text-gray-900 dark:text-white">{{ $cnt }}</span>
                    <span class="text-gray-500">{{ $label }}</span>
                </div>
            @endforeach
        </div>

        {{-- Shipment List --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white">🚛 Shipment List</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $shipments->count() }} record(s) found</p>
            </div>

            @if($shipments->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-400">No shipments found for the selected filters.</div>
            @else
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($shipments as $do)
                            @php
                                $daysLeft = $do->eta ? now()->startOfDay()->diffInDays($do->eta, false) : null;
                                $etaColor = $daysLeft === null ? 'gray' : ($daysLeft < 0 ? 'red' : ($daysLeft <= 2 ? 'yellow' : 'green'));
                                $sColor = $statusColors[$do->status] ?? 'gray';
                                $sLabel = $statusLabels[$do->status] ?? $do->status;
                            @endphp
                            <div class="px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="font-semibold text-gray-900 dark:text-white">{{ $do->do_number }}</p>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $sColor === 'primary' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                        ($sColor === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                            ($sColor === 'warning' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                ($sColor === 'danger' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                    ($sColor === 'info' ? 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200' :
                                        'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'))))
                                            }}">{{ $sLabel }}</span>
                                        </div>
                                        <div
                                            class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                                            <span>
                                                <span class="font-medium">{{ $do->originBranch?->name ?? '—' }}</span>
                                                <span class="mx-1 text-gray-400">→</span>
                                                <span class="font-medium">{{ $do->destinationBranch?->name ?? '—' }}</span>
                                            </span>
                                            <span class="text-gray-300 dark:text-gray-600">|</span>
                                            <span>{{ $do->cylinder_type }} · {{ number_format($do->quantity_ordered) }} pcs</span>
                                            @if($do->expedition)
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <span>{{ $do->expedition->name }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-xs text-gray-400">Ordered {{ $do->order_date?->format('d M Y') }}</p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        @if($do->eta)
                                                        <p class="text-xs text-gray-500">ETA {{ $do->eta->format('d M Y') }}</p>
                                                        <p class="mt-0.5 text-sm font-semibold
                                                        {{ $etaColor === 'red' ? 'text-red-600 dark:text-red-400' :
                                            ($etaColor === 'yellow' ? 'text-yellow-600 dark:text-yellow-400' :
                                                ($etaColor === 'green' ? 'text-green-600 dark:text-green-400' : 'text-gray-400')) }}">
                                                            @if($daysLeft < 0)
                                                                {{ abs($daysLeft) }}d overdue
                                                            @elseif($daysLeft === 0)
                                                                Today!
                                                            @else
                                                                {{ $daysLeft }}d left
                                                            @endif
                                                        </p>
                                        @else
                                            <p class="text-xs text-gray-400">No ETA set</p>
                                        @endif
                                        @if($do->quantity_received)
                                            <p class="mt-1 text-xs text-green-600">Rcvd: {{ number_format($do->quantity_received) }} pcs
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>