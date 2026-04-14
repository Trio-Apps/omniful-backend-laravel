<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerFilamentCoreAliases();
        $this->registerFilamentAuthAliases();
        $this->registerFilamentPageAliases();
    }

    private function registerFilamentCoreAliases(): void
    {
        if (class_exists(\Filament\Livewire\Notifications::class)) {
            Livewire::component('filament.livewire.notifications', \Filament\Livewire\Notifications::class);
        }
    }

    private function registerFilamentAuthAliases(): void
    {
        $aliases = [
            'filament.auth.pages.login' => \Filament\Auth\Pages\Login::class,
            'filament.auth.pages.register' => \Filament\Auth\Pages\Register::class,
            'filament.auth.pages.edit-profile' => \Filament\Auth\Pages\EditProfile::class,
            'filament.auth.pages.email-verification.email-verification-prompt' => \Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt::class,
            'filament.auth.pages.password-reset.request-password-reset' => \Filament\Auth\Pages\PasswordReset\RequestPasswordReset::class,
            'filament.auth.pages.password-reset.reset-password' => \Filament\Auth\Pages\PasswordReset\ResetPassword::class,
        ];

        foreach ($aliases as $alias => $class) {
            if (class_exists($class)) {
                Livewire::component($alias, $class);
            }
        }
    }

    private function registerFilamentPageAliases(): void
    {
        foreach (File::allFiles(app_path('Filament/Pages')) as $file) {
            $relativePath = str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            $class = 'App\\Filament\\Pages\\' . $relativePath;

            if (!class_exists($class) || !is_subclass_of($class, LivewireComponent::class)) {
                continue;
            }

            $alias = Str::of($class)
                ->replace('\\', '.')
                ->trim('.')
                ->explode('.')
                ->map(fn (string $segment): string => Str::kebab($segment))
                ->implode('.');

            Livewire::component($alias, $class);
        }
    }
}
