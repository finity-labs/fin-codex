<?php

use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The three harness pieces 11-05's listener rows and 11-06's end-to-end rows
 * lean on: Laravel's notifications table, Notifiable on the fixture user, and
 * finCodexNotificationsFor(), which reads the rows with the panel bell's own
 * query - so a row a test reads is a row the bell would show.
 */

it('stores a Filament database notification for a fixture user', function (): void {
    $user = User::create(['name' => 'Bell', 'email' => 'bell@example.com']);
    $other = User::create(['name' => 'Quiet', 'email' => 'quiet@example.com']);

    $user->notifyNow(Notification::make()->title('Hello')->body('World')->success()->toDatabase());

    $stored = finCodexNotificationsFor($user);

    expect($stored)->toHaveCount(1);

    $row = $stored->first();

    expect($row->type)->toBe(DatabaseNotification::class)
        ->and($row->data['format'])->toBe('filament')
        ->and($row->data['title'])->toBe('Hello')
        ->and($row->data['body'])->toBe('World')
        ->and($row->data['status'])->toBe('success')
        ->and($row->data['duration'])->toBe('persistent')
        ->and($row->read_at)->toBeNull()
        ->and(finCodexNotificationsFor($other))->toHaveCount(0);
});

it('stores it inside the call on the sync driver and on a fake queue alike', function (): void {
    Queue::fake();

    $user = User::create(['name' => 'Bell', 'email' => 'bell@example.com']);

    $user->notifyNow(Notification::make()->title('Hello')->body('World')->success()->toDatabase());

    expect(finCodexNotificationsFor($user))->toHaveCount(1);

    Queue::assertNothingPushed();
});

it("ignores rows that are not Filament's", function (): void {
    $user = User::create(['name' => 'Bell', 'email' => 'bell@example.com']);

    $user->notifyNow(new class extends BaseNotification
    {
        /** @return list<string> */
        public function via(mixed $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(mixed $notifiable): array
        {
            return ['plain' => true];
        }
    });

    expect(finCodexNotificationsFor($user))->toHaveCount(0)
        ->and(DB::table('notifications')->count())->toBe(1);
});
