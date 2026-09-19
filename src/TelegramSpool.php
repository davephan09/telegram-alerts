<?php

namespace Dphan\TelegramAlerts;

use Throwable;

/**
 * Spool tin báo lỗi chưa gửi được.
 *
 * Vì sao cần: gửi tin là một lời gọi mạng ra ngoài (api.telegram.org). Mạng VN tới Telegram chập chờn —
 * đo từ VPS Eros 19/09/2026: bình thường 0,8s nhưng có lúc nghẽn quá ngưỡng timeout. Nếu chỉ "thử một lần
 * rồi thôi" thì đúng lúc sự cố lớn (mạng cũng đang có vấn đề) lại là lúc mất tin, mà không ai biết.
 *
 * Ở đây tin hỏng được ghi xuống đĩa (storage/app, KHÔNG nằm trong public) với trần số dòng, và lệnh
 * `alert:flush` (package tự đăng ký lịch 5 phút một lần) gửi lại rồi xoá. Không chặn request, không mất tin.
 */
final class TelegramSpool
{
    /** Trần số tin giữ lại: spool là lưới an toàn, không phải hàng đợi vô hạn. */
    public const TOI_DA = 200;

    public function __construct(private readonly ?string $duongDan = null) {}

    public function path(): string
    {
        $duong = $this->duongDan ?? config('telegram-alerts.spool_path');

        if (filled($duong)) {
            return (string) $duong;
        }

        return function_exists('storage_path')
            ? storage_path('app/telegram-alerts-spool.jsonl')
            : sys_get_temp_dir().'/telegram-alerts-spool.jsonl';
    }

    /** @param array<string, mixed> $themThongTin */
    public function ghi(string $noiDung, array $themThongTin = [], ?string $lyDo = null): void
    {
        try {
            $duong = $this->path();
            $thuMuc = dirname($duong);

            if (! is_dir($thuMuc)) {
                @mkdir($thuMuc, 0775, true);
            }

            $dong = json_encode([
                'luc' => date('c'),
                'ly_do' => $lyDo,
                'noi_dung' => $noiDung,
            ] + $themThongTin, JSON_UNESCAPED_UNICODE);

            if ($dong === false) {
                return;
            }

            file_put_contents($duong, $dong.PHP_EOL, FILE_APPEND | LOCK_EX);
            $this->catBot();
        } catch (Throwable) {
            // Spool cũng hỏng thì thôi — không được ném lỗi ra ngoài (handler chạy lúc hệ thống đang hỏng).
        }
    }

    /** @return list<array<string, mixed>> */
    public function doc(): array
    {
        $duong = $this->path();

        if (! is_file($duong)) {
            return [];
        }

        $tin = [];

        foreach (file($duong, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $dong) {
            $gi = json_decode($dong, true);

            if (is_array($gi) && isset($gi['noi_dung'])) {
                $tin[] = $gi;
            }
        }

        return $tin;
    }

    public function xoa(): void
    {
        @unlink($this->path());
    }

    public function soTin(): int
    {
        return count($this->doc());
    }

    /** Giữ trần: bỏ tin CŨ NHẤT khi vượt ngưỡng (tin mới là tin đang xảy ra). */
    private function catBot(): void
    {
        $duong = $this->path();
        $dong = @file($duong, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($dong === false || count($dong) <= self::TOI_DA) {
            return;
        }

        $giu = array_slice($dong, -self::TOI_DA);
        file_put_contents($duong, implode(PHP_EOL, $giu).PHP_EOL, LOCK_EX);
    }
}
