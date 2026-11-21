{{-- Phase 17 / T17-05 — always-visible impersonation banner. Inline styles only: the panel has no custom Vite theme. --}}
<div
    role="status"
    style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.65rem 1rem; background-color: #b45309; color: #ffffff; font-size: 0.875rem; font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);"
>
    <span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;">
        <span aria-hidden="true">&#9888;</span>
        <span>
            Impersonating <strong>{{ $target?->name ?? 'unknown user' }}</strong>
            @if ($initiator !== null)
                &mdash; started by {{ $initiator->name }}
            @endif
        </span>

        @if (filled($reason))
            <span style="font-weight: 500; opacity: 0.9;">Reason: {{ $reason }}</span>
        @endif
    </span>

    <form method="POST" action="{{ route('impersonation.stop') }}" style="margin: 0;">
        @csrf
        <button
            type="submit"
            style="cursor: pointer; border-radius: 0.375rem; border: 1px solid rgba(255, 255, 255, 0.75); background-color: rgba(255, 255, 255, 0.15); color: #ffffff; padding: 0.35rem 0.75rem; font-size: 0.8125rem; font-weight: 600;"
        >
            Stop impersonating
        </button>
    </form>
</div>
