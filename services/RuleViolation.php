<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Pelanggaran aturan bisnis yang ditemukan saat memproses data. Membawa galat per field
 * (kunci "_form" untuk galat umum) agar controller bisa menampilkannya di formulir.
 * Dilempar dari dalam transaksi database sehingga seluruh perubahan dibatalkan.
 */
final class RuleViolation extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
