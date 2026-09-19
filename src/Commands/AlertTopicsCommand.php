<?php

namespace Dphan\TelegramAlerts\Commands;

use Dphan\TelegramAlerts\TelegramClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cài đặt / dò lại topic của nhóm Telegram dùng chung.
 *
 *   php artisan alert:topics                 # dò: liệt kê chat + topic mà bot đã thấy
 *   php artisan alert:topics --tao=athena    # tạo topic mới, in sẵn dòng .env để dán vào project
 *   php artisan alert:topics --tao=athena --chat=-1001234567890
 *
 * Vì sao cần: `message_thread_id` của mỗi topic KHÔNG hiện trong app Telegram, chỉ lộ ra qua Bot API.
 * Một lệnh thay vì mò tay — và id topic đổi (ai đó xoá/tạo lại topic) thì chạy lại là biết ngay.
 */
class AlertTopicsCommand extends Command
{
    protected $signature = 'alert:topics
        {--tao= : Tên topic cần tạo (thường là tên project)}
        {--chat= : Chat id của nhóm dùng chung (mặc định lấy ALERT_CHAT_ID)}';

    protected $description = 'Dò hoặc tạo topic trong nhóm Telegram dùng chung cho kênh báo lỗi.';

    public function handle(): int
    {
        $client = app(TelegramClient::class);
        $chat = (string) ($this->option('chat') ?: config('telegram-alerts.chat_id'));

        if (blank($client->cheToken((string) config('telegram-alerts.bot_token')))) {
            $this->error('Chưa khai ALERT_BOT_TOKEN trong .env.');

            return self::FAILURE;
        }

        try {
            if (filled($this->option('tao'))) {
                return $this->tao($client, $chat, (string) $this->option('tao'));
            }

            return $this->do($client);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function do(TelegramClient $client): int
    {
        $dich = $client->lietKeDich();

        if ($dich === []) {
            $this->warn('Chưa thấy chat nào. Mở nhóm dùng chung, gửi thử 1 tin trong topic cần dùng rồi chạy lại.');
            $this->line('Gợi ý: làm 1 lần cho mỗi topic — gửi "ping" vào ĐÚNG topic đó, sau đó chạy lại lệnh này.');

            return self::SUCCESS;
        }

        $this->table(
            ['chat_id', 'nhóm', 'topic_id', 'topic'],
            array_map(fn ($d) => [$d['chat_id'], $d['chat_title'], $d['topic_id'] ?? '—', $d['topic_name'] ?? '—'], $dich)
        );

        $this->newLine();
        $this->line('Dán dòng tương ứng vào .env của từng project:');
        foreach ($dich as $d) {
            $this->line('ALERT_TOPIC_ID='.($d['topic_id'] ?? ''));
        }

        return self::SUCCESS;
    }

    private function tao(TelegramClient $client, string $chat, string $ten): int
    {
        if (blank($chat)) {
            $this->error('Chưa có chat id nhóm: khai ALERT_CHAT_ID trong .env hoặc truyền --chat=-100...');

            return self::FAILURE;
        }

        $id = $client->taoTopic($ten, $chat);

        $this->info("Đã tạo topic \"{$ten}\" (id {$id}).");
        $this->newLine();
        $this->line('Thêm vào .env của project này:');
        $this->line('ALERT_BOT_TOKEN=<token bot dùng chung>');
        $this->line("ALERT_CHAT_ID={$chat}");
        $this->line("ALERT_TOPIC_ID={$id}");
        $this->line('ALERT_PROJECT='.$ten);

        return self::SUCCESS;
    }
}
