<?php

/**
 * CÀI ĐẶT BAN ĐẦU kênh Telegram dùng chung — chạy được khi CHƯA có Laravel nào cả.
 *
 *   php telegram-setup.php <BOT_TOKEN> whoami
 *   php telegram-setup.php <BOT_TOKEN> chats
 *   php telegram-setup.php <BOT_TOKEN> tao <CHAT_ID> athena cinematimes paradise hera gaia cronus
 *   php telegram-setup.php <BOT_TOKEN> test <CHAT_ID> [TOPIC_ID]
 *
 * Quy trình một lần cho cả studio:
 *   1. @BotFather → /newbot → lấy token (token này dùng chung cho MỌI project).
 *   2. Tạo 1 nhóm Telegram riêng tư → bật Topics (Forum) → add bot vào nhóm → phong admin
 *      (cần quyền "Manage Topics").
 *   3. Gửi 1 tin bất kỳ trong nhóm → chạy `chats` để lấy chat_id (nhóm id bắt đầu bằng -100).
 *   4. `tao` để tạo topic cho từng project — script in ra đúng 4 dòng .env cho mỗi project.
 *   5. Dán 4 dòng đó vào .env tương ứng, rồi chạy `php artisan alert:test` trong project.
 */

$token = $argv[1] ?? '';
$lenh = $argv[2] ?? '';

if ($token === '') {
    fwrite(STDERR, "Thiếu BOT_TOKEN.\nXem hướng dẫn ở đầu file.\n");
    exit(1);
}

/** Gọi Bot API bằng stream (không cần curl/ext ngoài). */
function goiApi(string $token, string $method, array $params = []): array
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($params),
        'timeout' => 20,
        'ignore_errors' => true,
    ]];

    $raw = @file_get_contents($url, false, stream_context_create($opts));

    if ($raw === false) {
        fwrite(STDERR, "Không gọi được api.telegram.org (mạng? token?).\n");
        exit(1);
    }

    $json = json_decode($raw, true) ?: [];

    if (($json['ok'] ?? false) !== true) {
        fwrite(STDERR, 'Telegram từ chối: '.($json['description'] ?? $raw)."\n");
        exit(1);
    }

    return $json;
}

function che(string $text, string $token): string
{
    return str_replace($token, '***', $text);
}

switch ($lenh) {
    case 'whoami':
        $r = goiApi($token, 'getMe')['result'];
        echo "✅ Bot: @{$r['username']} ({$r['first_name']}) id {$r['id']}\n";
        break;

    case 'chats':
        $r = goiApi($token, 'getUpdates')['result'];
        $thay = [];
        foreach ($r as $u) {
            $m = $u['message'] ?? $u['channel_post'] ?? null;
            if ($m === null) {
                continue;
            }
            $chat = $m['chat'];
            $key = $chat['id'].'/'.($m['message_thread_id'] ?? 0);
            $thay[$key] = [
                'chat_id' => $chat['id'],
                'ten' => $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? '?',
                'loai' => $chat['type'] ?? '?',
                'topic_id' => $m['message_thread_id'] ?? null,
                'topic' => $m['forum_topic_created']['name'] ?? null,
            ];
        }
        if ($thay === []) {
            echo "Chưa thấy chat nào. Add bot vào nhóm, gửi 1 tin trong nhóm/topic rồi chạy lại.\n";
            break;
        }
        printf("%-16s %-24s %-8s %-10s %s\n", 'chat_id', 'nhóm', 'loại', 'topic_id', 'topic');
        foreach ($thay as $t) {
            printf("%-16s %-24s %-8s %-10s %s\n", $t['chat_id'], mb_substr($t['ten'], 0, 22), $t['loai'], $t['topic_id'] ?? '—', $t['topic'] ?? '—');
        }
        break;

    case 'tao':
        $chat = $argv[3] ?? '';
        $tenProjects = array_slice($argv, 4);
        if ($chat === '' || $tenProjects === []) {
            fwrite(STDERR, "Dùng: php telegram-setup.php <TOKEN> tao <CHAT_ID> athena hera gaia ...\n");
            exit(1);
        }
        echo "Nhóm {$chat} — tạo topic cho: ".implode(', ', $tenProjects)."\n\n";
        foreach ($tenProjects as $ten) {
            $r = goiApi($token, 'createForumTopic', ['chat_id' => $chat, 'name' => $ten])['result'];
            $id = $r['message_thread_id'];
            echo "# {$ten} (topic id {$id})\n";
            echo "ALERT_BOT_TOKEN={$token}\n";
            echo "ALERT_CHAT_ID={$chat}\n";
            echo "ALERT_TOPIC_ID={$id}\n";
            echo "ALERT_PROJECT={$ten}\n\n";
        }
        echo "Dán 4 dòng trên vào .env của từng project, rồi chạy: php artisan alert:test\n";
        break;

    case 'test':
        $chat = $argv[3] ?? '';
        $topic = $argv[4] ?? null;
        if ($chat === '') {
            fwrite(STDERR, "Dùng: php telegram-setup.php <TOKEN> test <CHAT_ID> [TOPIC_ID]\n");
            exit(1);
        }
        $params = ['chat_id' => $chat, 'text' => '✅ Thử kênh báo lỗi dùng chung (setup script)'];
        if ($topic !== null) {
            $params['message_thread_id'] = (int) $topic;
        }
        goiApi($token, 'sendMessage', $params);
        echo "Đã gửi. Kiểm tin trong Telegram.\n";
        break;

    default:
        fwrite(STDERR, "Lệnh không hợp lệ. Dùng: whoami | chats | tao | test (xem đầu file).\n");
        exit(1);
}
