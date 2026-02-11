<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use App\Models\SapCostCenter;
use App\Models\SapCostCenterSetting;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapCostCenterSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IntegrationControlSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Integration Settings';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1000;

    protected string $view = 'filament.pages.integration-control-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(array_merge(
            IntegrationSetting::first()?->toArray() ?? [],
            SapCostCenterSetting::first()?->toArray() ?? []
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync Direction')
                    ->description('Choose the source of truth per domain to prevent sync collisions')
                    ->schema([
                        Select::make('sync_direction_items')
                            ->label('Items')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_suppliers')
                            ->label('Suppliers')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_warehouses')
                            ->label('Warehouses / Hubs')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::SAP_TO_OMNIFUL)
                            ->required(),
                        Select::make('sync_direction_inventory')
                            ->label('Inventory')
                            ->options(app(IntegrationDirectionService::class)->options())
                            ->default(IntegrationDirectionService::OMNIFUL_TO_SAP)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('SAP Cost Centers')
                    ->description('Default costing and project values sent to SAP on transactions')
                    ->schema([
                        Select::make('costing_code')
                            ->label('Costing Code (Dimension 1)')
                            ->options(fn () => $this->getDistributionRuleOptions(1))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code2')
                            ->label('Costing Code 2 (Dimension 2)')
                            ->options(fn () => $this->getDistributionRuleOptions(2))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code3')
                            ->label('Costing Code 3 (Dimension 3)')
                            ->options(fn () => $this->getDistributionRuleOptions(3))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code4')
                            ->label('Costing Code 4 (Dimension 4)')
                            ->options(fn () => $this->getDistributionRuleOptions(4))
                            ->searchable()
                            ->preload(),
                        Select::make('costing_code5')
                            ->label('Costing Code 5 (Dimension 5)')
                            ->options(fn () => $this->getDistributionRuleOptions(5))
                            ->searchable()
                            ->preload(),
                        Select::make('project_code')
                            ->label('Project Code')
                            ->options(fn () => $this->getProjectOptions())
                            ->searchable()
                            ->preload(),
                        Toggle::make('apply_to_stock_transfer')
                            ->label('Apply cost centers on Stock Transfer lines')
                            ->default(false),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(
            ['id' => 1],
            [
                'sync_direction_items' => $state['sync_direction_items'] ?? null,
                'sync_direction_suppliers' => $state['sync_direction_suppliers'] ?? null,
                'sync_direction_warehouses' => $state['sync_direction_warehouses'] ?? null,
                'sync_direction_inventory' => $state['sync_direction_inventory'] ?? null,
            ]
        );

        SapCostCenterSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'costing_code' => $state['costing_code'] ?? null,
                'costing_code2' => $state['costing_code2'] ?? null,
                'costing_code3' => $state['costing_code3'] ?? null,
                'costing_code4' => $state['costing_code4'] ?? null,
                'costing_code5' => $state['costing_code5'] ?? null,
                'project_code' => $state['project_code'] ?? null,
                'apply_to_stock_transfer' => (bool) ($state['apply_to_stock_transfer'] ?? false),
            ]
        );

        $this->form->fill(array_merge(
            IntegrationSetting::first()?->toArray() ?? [],
            SapCostCenterSetting::first()?->toArray() ?? []
        ));

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }

    public function syncSapCostCenters(SapServiceLayerClient $client): void
    {
        try {
            $result = app(SapCostCenterSyncService::class)->syncFromSap($client);

            Notification::make()
                ->title('SAP cost centers synced')
                ->body(
                    'Distribution Rules: ' . (int) ($result['distribution_rules'] ?? 0)
                    . ' | Projects: ' . (int) ($result['projects'] ?? 0)
                )
                ->success()
                ->send();

            $this->form->fill(array_merge(
                IntegrationSetting::first()?->toArray() ?? [],
                SapCostCenterSetting::first()?->toArray() ?? []
            ));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('SAP cost center sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function getDistributionRuleOptions(int $dimension): array
    {
        return SapCostCenter::query()
            ->where('source', 'distribution_rule')
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();
    }

    private function getProjectOptions(): array
    {
        return SapCostCenter::query()
            ->where('source', 'project')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->action('save')
                ->color('primary')
                ->extraAttributes([
                    'style' => 'background-color: #226d64; color: #ffffff;',
                ])
                ->keyBindings(['mod+s']),
            Action::make('syncSapCostCenters')
                ->label('Sync SAP Cost Centers')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action('syncSapCostCenters'),
        ];
    }
}

