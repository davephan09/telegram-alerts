<?php

namespace Dphan\TelegramAlerts;

use Dphan\TelegramAlerts\Commands\AlertTestCommand;
use Dphan\TelegramAlerts\Commands\AlertTopicsCommand;
use Dphan\TelegramAlerts\Commands\AlertFlushCommand;
use Illuminate\Console\Scheduling\Schedule;
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

        $this->app->singleton(TelegramSpool::class, fn () => new TelegramSpool(config('telegram-alerts.spool_path')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telegram-alerts.php' => config_path('telegram-alerts.php'),
            ], 'telegram-alerts-config');

            $this->commands([AlertTestCommand::class, AlertTopicsCommand::class]);
            $this->commands([AlertFlushCommand::class]);
        }

        // Gửi lại tin hỏng: package tự lo lịch để mỗi project không phải nhớ khai thêm.
        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            if (config('telegram-alerts.auto_flush') === false || config('telegram-alerts.auto_flush') === 'false') {
                return;
            }

            if (config('app.env') !== 'production') {
                return;
            }

            $this->app->make(Schedule::class)
                ->command('alert:flush')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
