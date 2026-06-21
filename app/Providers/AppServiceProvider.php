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
        // Behind an HTTPS-terminating reverse proxy the app sees plain HTTP, so
        // asset()/url() generate http:// links the browser blocks as mixed
        // content. Force the https scheme whenever the app is configured for
        // https (APP_URL), regardless of the proxied request scheme.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        $this->registerFilamentCoreAliases();
        $this->registerFilamentAuthAliases();
        $this->registerFilamentPageAliases();
        $this->registerFilamentWidgetAliases();
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
        $this->registerLivewireAliasesForPath(app_path('Filament/Pages'), 'App\\Filament\\Pages\\');
    }

    private function registerFilamentWidgetAliases(): void
    {
        $this->registerLivewireAliasesForPath(app_path('Filament/Widgets'), 'App\\Filament\\Widgets\\');
    }

    private function registerLivewireAliasesForPath(string $path, string $namespacePrefix): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (File::allFiles($path) as $file) {
            $relativePath = str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            $class = $namespacePrefix . $relativePath;

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
