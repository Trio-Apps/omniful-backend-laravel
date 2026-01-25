<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IntegrationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Connections';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.integration-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = IntegrationSetting::first();

        $this->form->fill($settings?->toArray() ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('SAP Business One')
                    ->description('Service Layer connection details')
                    ->schema([
                        TextInput::make('sap_service_layer_url')
                            ->label('Service Layer URL')
                            ->placeholder('https://sap.example.com:50000/b1s/v1')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                        TextInput::make('sap_company_db')
                            ->label('Company DB')
                            ->required(),
                        TextInput::make('sap_username')
                            ->label('Username')
                            ->required(),
                        TextInput::make('sap_password')
                            ->label('Password')
                            ->password()
                            ->revealable(),
                        Toggle::make('sap_ssl_verify')
                            ->label('Verify SSL certificate')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Omniful')
                    ->description('Omniful API and webhook settings')
                    ->schema([
                        TextInput::make('omniful_api_url')
                            ->label('API Base URL')
                            ->placeholder('https://api.omniful.com')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                        TextInput::make('omniful_api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_api_secret')
                            ->label('API Secret')
                            ->password()
                            ->revealable(),
                        TextInput::make('omniful_webhook_secret')
                            ->label('Webhook Secret')
                            ->password()
                            ->revealable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        IntegrationSetting::updateOrCreate(['id' => 1], $state);

        Notification::make()
            ->title('Connections saved')
            ->success()
            ->send();
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
