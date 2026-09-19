<?php

namespace Dphan\TelegramAlerts\Commands;

use Dphan\TelegramAlerts\TelegramAlertHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gửi thử một tin qua kênh báo lỗi dùng chung, BỎ QUA bộ gộp lỗi trùng và cổng môi trường, và in LỖI THẬT
 * nếu không gửi được (sai token/chat id, chưa add bot vào nhóm, hoặc máy chủ không tới được api.telegram.org).
 *
 * Đường log thật thì nuốt lỗi để không làm hỏng request, nên sau khi cài PHẢI chạy lệnh này một lần trên
 * từng server — nếu không thì "chuông im" lúc sự cố và không ai biết nó chưa bao giờ kêu.
 *
 *   php artisan alert:test
 *   php artisan alert:test --text="Thử topic athena"
 */
class AlertTestCommand extends Command
{
    protected $signature = 'alert:test
        {--text= : Nội dung tin thử (mặc định là tin mẫu có tên project)}
        {--level=error : Mức log sẽ hiện ở dòng đầu tin thử}';

    protected $description = 'Gửi thử tin báo lỗi qua Telegram để kiểm cấu hình, topic và đường mạng.';

    public function handle(): int
    {
        $handler = app(TelegramAlertHandler::class, (array) config('logging.channels.telegram.handler_with', []));

        if (! $handler->daCauHinh()) {
            $this->error('Chưa khai ALERT_BOT_TOKEN hoặc ALERT_CHAT_ID trong .env (xem README của dphan/telegram-alerts).');

            return self::FAILURE;
        }

        $text = (string) ($this->option('text') ?: '✅ Thử kênh báo lỗi · '
            .config('telegram-alerts.project', config('app.name', 'app')).' · '.config('app.env'));

        try {
            $handler->gui($text);
        } catch (Throwable $e) {
            $this->error('Gửi thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Đã gửi. Kiểm tin trong Telegram (đúng topic của project chứ?).');

        if (! $this->kenhTelegramDangHoatDong()) {
            $this->warn('Lưu ý: kênh telegram CHƯA nằm trong log mặc định, nên lỗi thật sẽ KHÔNG được báo. '
                .'Đặt LOG_CHANNEL=stack + LOG_STACK=daily,telegram (hoặc trỏ kênh mặc định vào một stack có telegram).');
        }

        if ($handler->bat() === false) {
            $this->warn('Lưu ý: cổng môi trường đang TẮT (ALERT_ENABLED=false hoặc không phải production) — '
                .'ở môi trường này lỗi thật sẽ không báo. Muốn bật: ALERT_ENABLED=true.');
        }

        return self::SUCCESS;
    }

    /**
     * Kênh mặc định có thật sự dẫn tới `telegram` không. Phải đi theo chuỗi stack (kênh mặc định có thể là
     * `daily_and_telegram` → ['daily','telegram'], không nhất thiết tên là `stack`): bản đầu chỉ soi kênh
     * `stack` nên báo oan cho project dùng tên khác, mà cảnh báo oan thì người ta bắt đầu bỏ qua cảnh báo thật.
     */
    private function kenhTelegramDangHoatDong(): bool
    {
        return $this->danToiTelegram((string) config('logging.default'), []);
    }

    /** @param array<string, bool> $daXet */
    private function danToiTelegram(string $kenh, array $daXet, int $sau = 0): bool
    {
        if ($kenh === '' || $sau > 3 || isset($daXet[$kenh])) {
            return false;
        }

        $daXet[$kenh] = true;

        if ($kenh === 'telegram') {
            return true;
        }

        $cauHinh = (array) config("logging.channels.{$kenh}", []);

        if (($cauHinh['driver'] ?? null) !== 'stack') {
            return false;
        }

        foreach ((array) ($cauHinh['channels'] ?? []) as $con) {
            if ($this->danToiTelegram((string) $con, $daXet, $sau + 1)) {
                return true;
            }
        }

        return false;
    }
}
