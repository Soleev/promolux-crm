<?php

namespace App\Filament\Pages;

use App\Services\CrmAccess;
use App\Services\MonthlyCharts;
use App\Services\Report;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class Overview extends Page
{
    protected static ?string $title = 'Обзор бизнеса';

    protected static ?int $navigationSort = -10;

    protected static string|\UnitEnum|null $navigationGroup = 'Главная';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected string $view = 'filament.pages.overview';

    public ?string $from = null;

    public ?string $until = null;

    public string $chartInterval = 'day';

    public ?string $manager = null;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')->label('С даты')->native(false)->displayFormat('d.m.Y')->live(),
            DatePicker::make('until')->label('По дату')->native(false)->displayFormat('d.m.Y')->afterOrEqual('from')->live(),
            Select::make('manager')->label('Менеджер')->placeholder('Все менеджеры')
                ->options(fn (): array => $this->managers())->searchable()->live()
                ->hidden(fn (): bool => auth()->user()->role === 'manager'),
        ])->columns(auth()->user()->role === 'manager' ? 2 : 3);
    }

    #[Computed]
    public function chartMonths(): array
    {
        $this->checkFilters();

        if (! $this->from || ! $this->until) {
            return [];
        }

        return app(MonthlyCharts::class)->build(auth()->user(), $this->from, $this->until, $this->manager ? (int) $this->manager : null, $this->chartInterval);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->active && ! in_array(auth()->user()->role, ['cashier', 'designer'], true);
    }

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
    }

    private function checkFilters(): void
    {
        $this->validate(['from' => 'nullable|date', 'until' => 'nullable|date|after_or_equal:from', 'manager' => 'nullable|integer', 'chartInterval' => 'required|in:day,month']);
    }

    #[Computed]
    public function report(): array
    {
        $this->checkFilters();

        return app(Report::class)->build(auth()->user(), $this->from, $this->until, $this->manager ? (int) $this->manager : null);
    }

    #[Computed]
    public function register(): Collection
    {
        $this->checkFilters();

        return app(Report::class)->register(auth()->user(), $this->from, $this->until, $this->manager ? (int) $this->manager : null);
    }

    #[Computed]
    public function managers(): array
    {
        return CrmAccess::salesAssignees(auth()->user())->pluck('name', 'id')->all();
    }
}
