<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Notifications\DatabaseNotification;

class NotificationLog extends Page
{
    protected static string $view = 'filament.pages.notification-log';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Notification Log';

    public string $filter = 'all';   // all | unread
    public string $from   = '';
    public string $until  = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.notification_log');
    }

    public function mount(): void
    {
        $this->from  = now()->subDays(30)->toDateString();
        $this->until = now()->toDateString();
    }

    public function getNotifications(): \Illuminate\Support\Collection
    {
        $user = auth()->user();

        $query = DatabaseNotification::query()
            ->where('notifiable_type', \App\Models\User::class)
            ->with('notifiable')
            ->when($this->from,  fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->until, fn ($q) => $q->whereDate('created_at', '<=', $this->until))
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->limit(200);

        if (! $user->isOwnerPusat()) {
            $query->where('notifiable_id', $user->id);
        }

        return $query->get();
    }

    public function markAllRead(): void
    {
        $user = auth()->user();

        DatabaseNotification::where('notifiable_type', \App\Models\User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->dispatch('$refresh');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
