<?php

namespace Dphan\TelegramAlerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Gọi Telegram Bot API. Tách khỏi handler để lệnh `alert:test`, `alert:topics` và các service khác
 * (digest, thông báo xã hội…) dùng lại cùng một đường gửi.
 *
 * Nguyên tắc: timeout ngắn và KHÔNG thử lại. Handler này chạy đúng lúc hệ thống đang hỏng; treo PHP-FPM
 * vài phút vì api.telegram.org không tới được còn tệ hơn việc mất một tin báo.
 */
final class TelegramClient
{
    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $chatId = null,
        private readonly ?string $topicId = null,
    ) {}

    public function daCauHinh(): bool
    {
        return filled($this->token) && filled($this->chatId);
    }

    /**
     * Gửi một tin dạng TEXT THUẦN (không parse_mode).
     *
     * Cố ý không dùng Markdown/HTML: tin báo lỗi hay chứa ký tự <, >, _, * và cả tên file có dấu ngoặc —
     * Telegram trả 400 "can't parse entities" và tin cảnh báo im lặng biến mất. Đổi lấy vài chữ đậm
     * không đáng so với việc mất cảnh báo.
     *
     * @throws RuntimeException khi Telegram không nhận (đã che token trong thông báo lỗi).
     */
    public function gui(string $text, ?string $chatId = null, ?string $topicId = null): void
    {
        if (! $this->daCauHinh()) {
            throw new RuntimeException('Chưa khai token/chat id cho kênh báo lỗi Telegram.');
        }

        $chat = $chatId ?: $this->chatId;
        $topic = $topicId ?? $this->topicId;

        $payload = [
            'chat_id' => $chat,
            'text' => mb_substr($text, 0, 4000),
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];

        if (filled($topic)) {
            $payload['message_thread_id'] = (int) $topic;
        }

        try {
            $res = Http::connectTimeout(2)
                ->timeout(5)
                ->asForm()
                ->post($this->duongDan($this->token, 'sendMessage'), $payload);
        } catch (Throwable $e) {
            throw new RuntimeException($this->cheToken($e->getMessage()), 0, $e);
        }

        if ($res->failed()) {
            throw new RuntimeException(
                'Telegram trả HTTP '.$res->status().': '.$this->cheToken((string) $res->json('description', ''))
            );
        }
    }

    public function duongDan(string $token, string $method): string
    {
        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    /**
     * Xin id của nhóm/topic. Dùng khi cài đặt ban đầu (xem scripts/telegram-setup.php) hoặc trong lệnh
     * `alert:topics` để dò lại id khi ai đó đổi topic.
     *
     * @return array<int, array{chat_id: string, chat_title: string, topic_id: ?int, topic_name: ?string}>
     */
    public function lietKeDich(): array
    {
        $res = Http::connectTimeout(3)->timeout(10)->get($this->duongDan((string) $this->token, 'getUpdates'));

        if ($res->failed() || ! $res->json('ok')) {
            throw new RuntimeException('getUpdates thất bại: '.$this->cheToken((string) $res->json('description', $res->body())));
        }

        $thay = [];

        foreach ($res->json('result', []) as $update) {
            $msg = $update['message'] ?? $update['channel_post'] ?? null;

            if ($msg === null) {
                continue;
            }

            $chat = $msg['chat'] ?? [];
            $id = (string) ($chat['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $thay[$id.'/'.($msg['message_thread_id'] ?? '0')] = [
                'chat_id' => $id,
                'chat_title' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? '?'),
                'topic_id' => isset($msg['message_thread_id']) ? (int) $msg['message_thread_id'] : null,
                'topic_name' => $msg['forum_topic_created']['name'] ?? null,
            ];
        }

        return array_values($thay);
    }

    /**
     * Tạo topic trong nhóm (bot phải là admin có quyền quản lý topic).
     *
     * @throws RuntimeException
     */
    public function taoTopic(string $ten, string $chatId): int
    {
        try {
            $res = Http::connectTimeout(3)->timeout(10)->asForm()->post(
                $this->duongDan((string) $this->token, 'createForumTopic'),
                ['chat_id' => $chatId, 'name' => $ten]
            );
        } catch (Throwable $e) {
            throw new RuntimeException($this->cheToken($e->getMessage()), 0, $e);
        }

        if ($res->failed() || ! $res->json('ok')) {
            throw new RuntimeException(
                'createForumTopic "'.$ten.'" thất bại: '.$this->cheToken((string) $res->json('description', $res->body()))
            );
        }

        return (int) $res->json('result.message_thread_id');
    }

    /** Ghi dấu vết khi chính kênh báo lỗi không gửi được (không được ném tiếp ra ngoài). */
    public static function ghiVet(string $noiDung): void
    {
        $dich = config('telegram-alerts.fallback_log');

        try {
            if (filled($dich)) {
                @file_put_contents((string) $dich, '['.date('Y-m-d H:i:s').'] '.$noiDung.PHP_EOL, FILE_APPEND);

                return;
            }

            error_log('telegram-alerts: '.$noiDung);
        } catch (Throwable) {
            // Hết đường ghi rồi thì thôi, không được ném lỗi tiếp.
        }
    }

    /** Không để token lọt vào log/terminal khi in lỗi ra ngoài. */
    public function cheToken(string $text): string
    {
        return filled($this->token) ? str_replace((string) $this->token, '***', $text) : $text;
    }
}
