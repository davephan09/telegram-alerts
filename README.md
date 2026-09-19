# dphan/telegram-alerts — chuông báo lỗi Telegram dùng chung

Một bot · một nhóm Telegram · **mỗi project một topic**. Thay vì mỗi project một bot + một chat riêng
(cinenjoy một cái, ketquaxosobamien một cái, athena một cái…), cả studio dùng chung một chỗ:
một nơi để nhìn, một nơi để tắt tiếng, một nơi để biết "tối qua có gì nổ không".

Nguồn chân lý: `D:\AI\Services\TelegramAlerts` (Brain ghi ở `Brain/70_KNOWLEDGE/`).

## Vì sao dùng chung

| | Mỗi project một bot/chat | Một bot + một nhóm, mỗi project một topic |
|---|---|---|
| Thêm project | tạo bot, tạo chat, cấu hình lại từ đầu | tạo 1 topic, dán 4 dòng .env |
| Nhìn tổng thể | mở N cửa sổ chat | 1 nhóm, N topic |
| Tắt tiếng 1 project | tắt cả chat | mute đúng topic đó |
| Sửa hành vi cảnh báo | sửa N chỗ, dễ lệch | sửa 1 package |

## Cài đặt một lần cho cả studio

1. **@BotFather** → `/newbot` → lấy **token**. Token này dùng chung cho mọi project.
2. Tạo **một nhóm riêng tư** → bật **Topics** → thêm bot vào nhóm → phong **admin** (cần quyền
   *Manage Topics* để tạo topic bằng API).
3. Gửi 1 tin bất kỳ trong nhóm, rồi chạy:

```bash
php scripts/telegram-setup.php <BOT_TOKEN> chats      # ra chat_id nhóm (-100…)
php scripts/telegram-setup.php <BOT_TOKEN> tao <CHAT_ID> athena cinematimes paradise hera gaia cronus
```

Lệnh `tao` in ra đúng 4 dòng `.env` cho mỗi project — dán vào project tương ứng.

## Cài vào một project Laravel

```bash
composer require dphan/telegram-alerts
```

`config/logging.php` — trỏ một kênh vào handler dùng chung:

```php
'telegram' => [
    'driver' => 'monolog',
    'handler' => \Dphan\TelegramAlerts\TelegramAlertHandler::class,
    'level' => env('ALERT_LEVEL', 'error'),
],
```

`.env`:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=daily,telegram        # log vẫn ra file như cũ, thêm đường báo lỗi
ALERT_BOT_TOKEN=<token dùng chung>
ALERT_CHAT_ID=-100…
ALERT_TOPIC_ID=<topic của project>
ALERT_PROJECT=athena            # nhãn hiện ở dòng đầu mỗi tin
# ALERT_ENABLED bỏ trống = chỉ bật khi APP_ENV=production
```

Rồi chạy một lần trên **từng server**:

```bash
php artisan alert:test          # gửi tin thật; sai token/chat/topic là báo lỗi ngay
php artisan alert:topics        # cần id topic mới (ai đó xoá/tạo lại topic)
```

## Bốn lan can (handler chạy đúng lúc hệ thống đang hỏng)

1. **Không bao giờ ném lỗi ra ngoài** — chuông hỏng không được làm hỏng thêm request đang lỗi.
2. **Timeout 2s kết nối / 5s tổng, không thử lại** — `api.telegram.org` không tới được mà treo PHP-FPM
   vài phút thì tệ hơn mất một tin.
3. **Gộp lỗi trùng** theo vân tay `loại exception + file:dòng` (mặc định 30 phút), và đếm số lần lặp để
   tin sau nói "đã lặp N lần" — thấy được sự cố lan rộng mà không dội bom tin nhắn.
4. **Cache FILE, không cache mặc định** — lúc DB sập thì mọi request cùng lỗi, đúng lúc cần chuông nhất.
   Thêm nữa: **mặc định chỉ bật ở production** (`ALERT_ENABLED`), để tin thử ở máy dev không lẫn vào
   đúng chỗ đang theo dõi prod — bài học trả giá từ kênh cũ.
5. **Gửi hỏng thì KHÔNG mất tin**: tin được ghi xuống spool trên đĩa (`storage/app/telegram-alerts-spool.jsonl`)
   và `alert:flush` gửi lại — package tự đăng ký lịch 5 phút một lần ở production, không phải khai thêm.
   Cửa sổ gộp chỉ được chốt SAU KHI gửi thành công (chốt trước = mạng chập là mất tin im lặng).
   Xem tồn: `php artisan alert:flush --xem`.

Tin gửi ở dạng **text thuần, không parse_mode**: tin báo lỗi hay chứa `<`, `>`, `_` và tên file có ngoặc —
Markdown/HTML sẽ làm Telegram trả 400 "can't parse entities" và cảnh báo biến mất im lặng.

Khi chính chuông không gửi được, lỗi được ghi lại qua `error_log` (hoặc `ALERT_FALLBACK_LOG=<path>`),
kèm token đã che — không để "chuông im mà không ai biết vì sao".

## Ngưỡng chờ (đo thật, đừng hạ xuống)

| Biến | Mặc định | Ghi chú |
|---|---|---|
| `ALERT_CONNECT_TIMEOUT` | 5s | Đo từ VPS Eros 19/09/2026: bình thường ~0,3s, **có lúc nghẽn quá 2s** — ngưỡng 2s cũ làm rơi một tin báo thật |
| `ALERT_TIMEOUT` | 10s | Tổng thời gian; không thử lại trong request (đã có spool lo phần gửi lại) |
| `ALERT_SPOOL_PATH` | `storage/app/telegram-alerts-spool.jsonl` | Nơi giữ tin gửi hỏng (trần 200 tin, bỏ tin cũ nhất) |
| `ALERT_AUTO_FLUSH` | `true` | Tự đăng ký lịch `alert:flush` 5 phút/lần ở production |

## Phân phối (chọn trước khi commit vào repo project)

- **A. Repo GitHub riêng của Dat (khuyến nghị)** — tạo repo `telegram-alerts`, thêm vào `composer.json`
  của mỗi project:
  `"repositories": [{"type": "vcs", "url": "https://github.com/<user>/telegram-alerts"}]`
  rồi `composer require dphan/telegram-alerts:^1`. Nếu repo để **private** thì mỗi server + CI cần
  `COMPOSER_AUTH` (PAT chỉ quyền đọc) — thêm 1 secret một lần cho mỗi repo.
- **B. Không dùng Composer** — copy `src/` + `config/` vào từng project (ví dụ `app/Logging/Telegram/`).
  Không cần token GitHub, nhưng sửa 1 chỗ phải sửa N nơi ⇒ chỉ nên coi là bước đệm.

## Lưu ý khi dùng cho 2 server

`Eros` (Vietnix) và `Aion` (Hetzner) đều gọi ra `api.telegram.org` được (không cần mở port vào).
Một nhóm chung nhận tin từ cả hai; nhãn `ALERT_PROJECT` + `APP_ENV` cho biết tin đến từ đâu.
