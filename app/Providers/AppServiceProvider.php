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
        $this->registerFilamentPageAliases();
    }

    private function registerFilamentCoreAliases(): void
    {
        if (class_exists(\Filament\Livewire\Notifications::class)) {
            Livewire::component('filament.livewire.notifications', \Filament\Livewire\Notifications::class);
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
