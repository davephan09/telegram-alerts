<?php

/*
|--------------------------------------------------------------------------
| Báo lỗi Telegram dùng chung (dphan/telegram-alerts)
|--------------------------------------------------------------------------
| Một bot · một nhóm Telegram (bật Topics) · mỗi project một topic.
| Kênh chỉ IM LẶNG khi thiếu cấu hình — không bao giờ làm hỏng app.
|
| Muốn 1 project vào chuông: khai 3 biến trong .env của project đó
|   ALERT_BOT_TOKEN=...        (token bot dùng chung, xin ở @BotFather)
|   ALERT_CHAT_ID=-100...      (id nhóm Telegram dùng chung)
|   ALERT_TOPIC_ID=...         (id topic của project trong nhóm, tuỳ chọn)
| rồi trỏ kênh log mặc định vào stack có 'telegram'.
*/

return [

    // Bật/tắt. null = chỉ bật khi APP_ENV=production (chống lẫn tin thử của máy dev vào kênh thật).
    'enabled' => env('ALERT_ENABLED'),

    'bot_token' => env('ALERT_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN')),

    'chat_id' => env('ALERT_CHAT_ID', env('TELEGRAM_CHAT_ID')),

    // Topic (message_thread_id) riêng của project trong nhóm chung. Bỏ trống = gửi vào topic chung.
    'topic_id' => env('ALERT_TOPIC_ID'),

    // Nhãn project hiện ở dòng đầu mỗi tin. Mặc định lấy APP_NAME.
    'project' => env('ALERT_PROJECT', env('APP_NAME', 'app')),

    // Chỉ gửi từ mức này trở lên: debug|info|notice|warning|error|critical|alert|emergency
    'level' => env('ALERT_LEVEL', 'error'),

    // Gộp lỗi trùng (cùng loại + cùng dòng code) trong bao nhiêu phút.
    'dedup_minutes' => (int) env('ALERT_DEDUP_MINUTES', 30),

    // Cache lưu dấu gộp lỗi. Cố ý mặc định 'file': lúc DB sập thì mọi request cùng lỗi,
    // đúng lúc cần chuông nhất mà cache database lại chết theo.
    'cache_store' => env('ALERT_CACHE_STORE', 'file'),

    // Bỏ qua những lỗi ồn mà không cần ai xử lý (khớp chuỗi con trong message).
    'skip_patterns' => [
        'CSRF token mismatch',
        'The page has expired',
        'Unauthenticated',
        'MethodNotAllowedHttpException',
        'NotFoundHttpException',
        'cURL error 28',
    ],

    // Đích nhận khi kênh không gửi được (để còn dấu vết mà dò). null = chỉ ghi error_log của PHP.
    'fallback_log' => env('ALERT_FALLBACK_LOG'),

    // Ngưỡng chờ gọi Telegram (giây). Đo từ VPS VN: bình thường ~0,3s kết nối nhưng có lúc nghẽn; để chặt
    // quá thì tin báo bị bỏ im lặng đúng lúc cần nhất.
    'connect_timeout' => (int) env('ALERT_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('ALERT_TIMEOUT', 10),

    // Spool tin gửi hỏng (lệnh `alert:flush` gửi lại). Bỏ trống = storage/app/telegram-alerts-spool.jsonl.
    'spool_path' => env('ALERT_SPOOL_PATH'),

    // Tự đăng ký lịch `alert:flush` mỗi 5 phút (chỉ ở production). Tắt nếu project đã có cách khác.
    'auto_flush' => env('ALERT_AUTO_FLUSH', true),
];
