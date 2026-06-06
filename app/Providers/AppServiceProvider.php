<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Models\Purchase;
use App\Observers\PurchaseObserver;
use App\Services\WhatsApp\Contracts\WhatsAppMessageSender;
use App\Services\WhatsApp\WapilotWhatsAppMessageSender;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppMessageSender::class, function () {
            $driver = config('services.whatsapp.driver', 'wapilot');

            return match ($driver) {
                'wapilot' => new WapilotWhatsAppMessageSender(
                    config('services.whatsapp.wapilot.base_url', 'https://api.wapilot.net'),
                    config('services.whatsapp.wapilot.instance_id'),
                    config('services.whatsapp.wapilot.token'),
                    (int) config('services.whatsapp.wapilot.timeout', 10),
                ),
                default => throw WhatsAppException::unsupportedDriver($driver),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Purchase::observe(PurchaseObserver::class);
    }
}
