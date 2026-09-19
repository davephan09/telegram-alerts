<?php

namespace Dphan\TelegramAlerts;

use Dphan\TelegramAlerts\Commands\AlertTestCommand;
use Dphan\TelegramAlerts\Commands\AlertTopicsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Tự nạp (auto-discovery): merge config, cho phép publish config, đăng ký 2 lệnh cài đặt, và cấp
 * TelegramClient dùng chung cho các service khác (digest, thông báo nghiệp vụ…).
 */
class TelegramAlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-alerts.php', 'telegram-alerts');

        $this->app->singleton(TelegramClient::class, fn () => new TelegramClient(
            config('telegram-alerts.bot_token'),
            config('telegram-alerts.chat_id'),
            config('telegram-alerts.topic_id'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telegram-alerts.php' => config_path('telegram-alerts.php'),
            ], 'telegram-alerts-config');

            $this->commands([AlertTestCommand::class, AlertTopicsCommand::class]);
        }
    }
}
