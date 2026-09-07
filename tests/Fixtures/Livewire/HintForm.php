<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Livewire;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * A plain Livewire form outside every panel. With no current panel the
 * hint's guard falls back to lin-codex.auth.guard and then the app default,
 * exactly as the core's ViewerResolver does; the field carries the macro
 * form without a heading. render() returns an inline template.
 */
final class HintForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->codexHelp('users'),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
