<?php

namespace Dphan\TelegramAlerts;

use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Chuông báo lỗi Telegram dùng chung cho mọi project: MỘT bot, MỘT nhóm, mỗi project một topic.
 *
 * Gắn vào bất kỳ project Laravel nào bằng cách trỏ kênh log vào handler này:
 *
 *     'telegram' => [
 *         'driver' => 'monolog',
 *         'handler' => \Dphan\TelegramAlerts\TelegramAlertHandler::class,
 *         'level' => env('ALERT_LEVEL', 'error'),
 *     ],
 *
 * rồi cho kênh đó vào stack mặc định (`LOG_CHANNEL=stack`, `LOG_STACK=daily,telegram`).
 *
 * Handler chạy ĐÚNG LÚC hệ thống đang hỏng, nên có bốn lan can:
 *  - KHÔNG ném lỗi ra ngoài: chuông hỏng không được làm hỏng thêm request đang lỗi.
 *  - Timeout ngắn, không thử lại (xem TelegramClient).
 *  - Gộp lỗi trùng (cùng loại + cùng dòng code) trong `dedup_minutes`, đếm số lần lặp để tin sau nói rõ
 *    "đã lặp N lần" — dấu hiệu sự cố lan rộng mà không dội bom tin nhắn.
 *  - Cache FILE chứ không cache mặc định: lúc DB sập thì mọi request cùng lỗi, đúng lúc cần chuông nhất.
 */
final class TelegramAlertHandler extends AbstractProcessingHandler
{
    private bool $dangGui = false;

    private ?TelegramClient $client = null;

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $chatId = null,
        private readonly ?string $topicId = null,
        private readonly ?string $project = null,
        int|string|Level $level = Level::Error,
        private readonly ?int $dedupMinutes = null,
        private readonly ?string $cacheStore = null,
        private readonly ?bool $enabled = null,
        private readonly ?array $skipPatterns = null,
    ) {
        parent::__construct($level);
    }

    public function daCauHinh(): bool
    {
        return filled($this->token()) && filled($this->chatId());
    }

    public function bat(): bool
    {
        $co = $this->enabled ?? config('telegram-alerts.enabled');

        // Bỏ trống = chỉ bật ở production. Đây là bài học trả giá: kênh chung mà không gated theo môi
        // trường thì mỗi lần chạy thử ở máy dev là một tin rác vào đúng chỗ đang theo dõi prod.
        // Đọc config('app.env') chứ không app()->isProduction() để test đổi được môi trường tại chỗ.
        return $co === null ? config('app.env') === 'production' : (bool) $co;
    }

    /**
     * Gửi một tin, NÉM RuntimeException (đã che token) nếu Telegram không nhận. Lệnh `alert:test` gọi
     * thẳng hàm này để thấy lỗi thật; đường log thì nuốt lỗi trong `write()`.
     */
    public function gui(string $text): void
    {
        $this->client()->gui($text);
    }

    private function client(): TelegramClient
    {
        return $this->client ??= new TelegramClient($this->token(), $this->chatId(), $this->topicId());
    }

    protected function write(LogRecord $record): void
    {
        if ($this->dangGui || ! $this->bat() || ! $this->daCauHinh() || $this->biBoQua($record)) {
            return;
        }

        $this->dangGui = true;

        try {
            if (! $this->lanDauTrongKy($record)) {
                $this->demThem($record);

                return;
            }

            $this->gui($this->noiDung($record));
            $this->datLaiDem($record);
        } catch (Throwable $e) {
            TelegramClient::ghiVet($this->cheToken($e->getMessage()));
        } finally {
            $this->dangGui = false;
        }
    }

    /** Lỗi ồn ào mà không cần ai xử lý (CSRF hết hạn, bot dò link, 404…) — khai trong config. */
    private function biBoQua(LogRecord $record): bool
    {
        $mau = $this->skipPatterns ?? (array) config('telegram-alerts.skip_patterns', []);

        foreach ($mau as $m) {
            if ($m !== '' && str_contains($record->message, (string) $m)) {
                return true;
            }
        }

        return false;
    }

    /** true = cửa sổ gộp đã hết, được phép gửi tin mới. */
    private function lanDauTrongKy(LogRecord $record): bool
    {
        // dedup_minutes = 0 nghĩa là TẮT gộp: cache TTL 0 lại có nghĩa "không hết hạn", nên phải chặn ở đây
        // kẻo bật 0 lại thành gộp vĩnh viễn.
        if ($this->phut() <= 0) {
            return true;
        }

        return Cache::store($this->store())->add(
            'alert:gui:'.$this->vanTay($record),
            true,
            $this->phut() * 60
        );
    }

    private function demThem(LogRecord $record): void
    {
        $khoa = 'alert:dem:'.$this->vanTay($record);
        $store = Cache::store($this->store());

        $store->put($khoa, (int) $store->get($khoa, 0) + 1, 24 * 3600);
    }

    private function datLaiDem(LogRecord $record): void
    {
        Cache::store($this->store())->forget('alert:dem:'.$this->vanTay($record));
    }

    private function soLanLap(LogRecord $record): int
    {
        return (int) Cache::store($this->store())->get('alert:dem:'.$this->vanTay($record), 0);
    }

    /**
     * Vân tay để gộp: cùng loại exception + cùng file:dòng = cùng một lỗi cần sửa, dù message khác nhau.
     * Với log không kèm exception thì lấy theo mức + nội dung.
     */
    private function vanTay(LogRecord $record): string
    {
        $e = $record->context['exception'] ?? null;

        return sha1($e instanceof Throwable
            ? $e::class.'|'.$e->getFile().'|'.$e->getLine()
            : $record->level->name.'|'.$record->message);
    }

    private function noiDung(LogRecord $record): string
    {
        $e = $record->context['exception'] ?? null;

        $dong = ['🔴 '.$record->level->getName().' · '.$this->project().' · '.config('app.env')];

        if ($e instanceof Throwable) {
            $dong[] = $e::class;
            $dong[] = Str::limit($e->getMessage(), 500);
            $dong[] = 'tại '.$this->viTri($e);
        } else {
            $dong[] = Str::limit($record->message, 500);
        }

        if (($soLan = $this->soLanLap($record)) > 0) {
            $dong[] = "🔁 Lỗi này đã lặp {$soLan} lần kể từ tin báo trước.";
        }

        $dong[] = $this->noiXayRa();
        $dong[] = $record->datetime->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i:s').' (giờ VN)';
        $dong[] = "Log đầy đủ ở storage/logs. Lỗi giống hệt trong {$this->phut()} phút tới sẽ không báo lại.";

        return implode("\n", array_filter($dong));
    }

    /**
     * Lỗi nổ trong vendor (vd QueryException ở Connection.php) thì kèm thêm dòng code CỦA MÌNH gần nhất
     * đã gọi xuống, vì đó mới là chỗ cần mở ra sửa.
     */
    private function viTri(Throwable $e): string
    {
        $noi = $this->duongDan($e->getFile()).':'.$e->getLine();

        if (! str_contains(str_replace('\\', '/', $e->getFile()), '/vendor/')) {
            return $noi;
        }

        foreach ($e->getTrace() as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            if ($file !== '' && ! str_contains($file, '/vendor/')) {
                return $noi.' (gọi từ '.$this->duongDan($file).':'.($frame['line'] ?? '?').')';
            }
        }

        return $noi;
    }

    private function noiXayRa(): ?string
    {
        if (! app()->runningInConsole()) {
            $req = request();

            return $req->method().' '.Str::limit($req->fullUrl(), 200);
        }

        $argv = $_SERVER['argv'] ?? [];

        return count($argv) > 1 ? 'artisan '.implode(' ', array_slice($argv, 1)) : null;
    }

    private function duongDan(string $file): string
    {
        $goc = str_replace('\\', '/', base_path()).'/';

        return Str::after(str_replace('\\', '/', $file), $goc);
    }

    private function phut(): int
    {
        return $this->dedupMinutes ?? (int) config('telegram-alerts.dedup_minutes', 30);
    }

    private function store(): string
    {
        return $this->cacheStore ?? (string) config('telegram-alerts.cache_store', 'file');
    }

    private function token(): ?string
    {
        return $this->token ?? config('telegram-alerts.bot_token');
    }

    private function chatId(): ?string
    {
        return $this->chatId ?? config('telegram-alerts.chat_id');
    }

    private function topicId(): ?string
    {
        return $this->topicId ?? config('telegram-alerts.topic_id');
    }

    private function project(): string
    {
        return (string) ($this->project ?? config('telegram-alerts.project', config('app.name', 'app')));
    }

    private function cheToken(string $text): string
    {
        return $this->client()->cheToken($text);
    }
}
