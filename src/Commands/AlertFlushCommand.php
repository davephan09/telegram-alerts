<?php

namespace Dphan\TelegramAlerts\Commands;

use Dphan\TelegramAlerts\TelegramAlertHandler;
use Dphan\TelegramAlerts\TelegramSpool;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gửi lại những tin báo lỗi đã hỏng lúc đầu (xem TelegramSpool). Package tự đăng ký lịch 5 phút một lần,
 * nên bình thường không phải gọi tay.
 *
 *   php artisan alert:flush        # gửi lại hết
 *   php artisan alert:flush --xem  # chỉ xem còn tồn gì
 */
class AlertFlushCommand extends Command
{
    protected $signature = 'alert:flush {--xem : Chỉ liệt kê tin đang tồn, không gửi}';

    protected $description = 'Gửi lại các tin báo lỗi Telegram bị hỏng lúc gửi đầu (spool trên đĩa).';

    public function handle(TelegramSpool $spool): int
    {
        $tin = $spool->doc();

        if ($tin === []) {
            return self::SUCCESS;
        }

        if ($this->option('xem')) {
            foreach ($tin as $t) {
                $this->line(($t['luc'] ?? '?').' · '.\Illuminate\Support\Str::limit((string) $t['noi_dung'], 80));
            }

            return self::SUCCESS;
        }

        $handler = app(TelegramAlertHandler::class, (array) config('logging.channels.telegram.handler_with', []));

        if (! $handler->daCauHinh()) {
            // Không có cấu hình thì giữ nguyên spool, đừng xoá tin (mất dấu vết).
            return self::SUCCESS;
        }

        $conLai = [];
        $guiDuoc = 0;

        foreach ($tin as $t) {
            try {
                $handler->gui((string) $t['noi_dung']);
                $guiDuoc++;
            } catch (Throwable $e) {
                $conLai[] = $t;

                if (! $this->output->isQuiet()) {
                    $this->warn('Gửi lại thất bại (giữ tin trong spool): '.$e->getMessage());
                }
            }
        }

        if ($conLai === []) {
            $spool->xoa();
        } else {
            // Ghi lại phần chưa gửi (giữ nguyên thứ tự), bỏ phần đã gửi.
            $spool->xoa();
            foreach ($conLai as $t) {
                $spool->ghi((string) $t['noi_dung'], ['luc' => $t['luc'] ?? date('c')], $t['ly_do'] ?? null);
            }
        }

        if (! $this->output->isQuiet()) {
            $this->info("Đã gửi lại {$guiDuoc}/".count($tin).' tin tồn.');
        }

        return self::SUCCESS;
    }
}
