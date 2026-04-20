<?php

namespace App\Filament\Pages;

use App\Models\SapCostCenter;
use App\Models\SapCostCenterSetting;
use App\Models\SapWarehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseCostCenterMappings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Warehouse Cost Centers';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1010;

    protected string $view = 'filament.pages.warehouse-cost-center-mappings';

    public ?array $data = [];

    public function mount(): void
    {
        $settingsByWarehouse = SapCostCenterSetting::query()
            ->whereNotNull('warehouse_code')
            ->get()
            ->keyBy('warehouse_code');

        $global = SapCostCenterSetting::query()
            ->whereNull('warehouse_code')
            ->first();

        $rows = SapWarehouse::query()
            ->orderBy('code')
            ->get()
            ->map(function (SapWarehouse $warehouse) use ($settingsByWarehouse, $global) {
                $setting = $settingsByWarehouse->get($warehouse->code);

                return [
                    'warehouse_code' => $warehouse->code,
                    'warehouse_name' => $warehouse->name,
                    'costing_code' => $setting?->costing_code,
                    'costing_code2' => $setting?->costing_code2,
                    'costing_code3' => $setting?->costing_code3,
                    'costing_code4' => $setting?->costing_code4,
                    'costing_code5' => $setting?->costing_code5,
                    'project_code' => $setting?->project_code,
                ];
            })
            ->values()
            ->all();

        $this->form->fill([
            'apply_to_stock_transfer' => (bool) ($global?->apply_to_stock_transfer ?? false),
            'warehouses' => $rows,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Warehouse Cost Center Mapping')
                    ->description('Assign Dimension 1-5 cost centers per SAP warehouse. These mappings are used for orders and other SAP documents.')
                    ->schema([
                        Toggle::make('apply_to_stock_transfer')
                            ->label('Apply cost centers on Stock Transfer lines')
                            ->default(false),
                        Repeater::make('warehouses')
                            ->label('')
                            ->schema([
                                TextInput::make('warehouse_code')
                                    ->label('Warehouse')
                                    ->readOnly(),
                                TextInput::make('warehouse_name')
                                    ->label('Name')
                                    ->readOnly(),
                                Select::make('costing_code')
                                    ->label('D1')
                                    ->options(fn () => $this->getDistributionRuleOptions(1))
                                    ->searchable()
                                    ->preload(),
                                Select::make('costing_code2')
                                    ->label('D2')
                                    ->options(fn () => $this->getDistributionRuleOptions(2))
                                    ->searchable()
                                    ->preload(),
                                Select::make('costing_code3')
                                    ->label('D3')
                                    ->options(fn () => $this->getDistributionRuleOptions(3))
                                    ->searchable()
                                    ->preload(),
                                Select::make('costing_code4')
                                    ->label('D4')
                                    ->options(fn () => $this->getDistributionRuleOptions(4))
                                    ->searchable()
                                    ->preload(),
                                Select::make('costing_code5')
                                    ->label('D5')
                                    ->options(fn () => $this->getDistributionRuleOptions(5))
                                    ->searchable()
                                    ->preload(),
                                Select::make('project_code')
                                    ->label('Project')
                                    ->options(fn () => $this->getProjectOptions())
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->columns(4)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => ($state['warehouse_code'] ?? '') !== '' ? (($state['warehouse_code'] ?? '') . ' - ' . ($state['warehouse_name'] ?? '')) : null),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        SapCostCenterSetting::query()->updateOrCreate(
            ['warehouse_code' => null],
            [
                'apply_to_stock_transfer' => (bool) ($state['apply_to_stock_transfer'] ?? false),
            ]
        );

        $seenWarehouseCodes = [];
        foreach ((array) ($state['warehouses'] ?? []) as $row) {
            $warehouseCode = trim((string) ($row['warehouse_code'] ?? ''));
            if ($warehouseCode === '') {
                continue;
            }

            $seenWarehouseCodes[] = $warehouseCode;

            SapCostCenterSetting::query()->updateOrCreate(
                ['warehouse_code' => $warehouseCode],
                [
                    'costing_code' => $row['costing_code'] ?? null,
                    'costing_code2' => $row['costing_code2'] ?? null,
                    'costing_code3' => $row['costing_code3'] ?? null,
                    'costing_code4' => $row['costing_code4'] ?? null,
                    'costing_code5' => $row['costing_code5'] ?? null,
                    'project_code' => $row['project_code'] ?? null,
                ]
            );
        }

        if ($seenWarehouseCodes !== []) {
            SapCostCenterSetting::query()
                ->whereNotNull('warehouse_code')
                ->whereNotIn('warehouse_code', $seenWarehouseCodes)
                ->delete();
        }

        Notification::make()
            ->title('Warehouse cost centers saved')
            ->success()
            ->send();
    }

    private function getDistributionRuleOptions(int $dimension): array
    {
        $exact = SapCostCenter::query()
            ->whereIn('source', ['distribution_rule', 'profit_center'])
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (SapCostCenter $row) => [
                $row->code => $row->code . ' - ' . ($row->name ?: $row->code),
            ])
            ->all();

        if ($exact !== []) {
            return $exact;
        }

        return SapCostCenter::query()
            ->whereIn('source', ['distribution_rule', 'profit_center'])
            ->whereNull('dimension')
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
        ];
    }
}
