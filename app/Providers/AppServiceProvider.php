<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Purchase;
use App\Observers\PurchaseObserver;

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
        Purchase::observe(PurchaseObserver::class);
    }
}
