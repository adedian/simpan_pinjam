<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validasi input sederhana. Aturan dipisah "|" : required, min:N, max:N (karakter),
 * maxbytes:N, regex:/pola/, same:field, different:field, in:a,b,c.
 * Aturan regex HARUS yang terakhir: seluruh sisa teks dianggap pola (pola boleh memuat "|").
 * Mengembalikan galat per field (pesan pertama saja) dalam Bahasa Indonesia.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $rules  field => "aturan|aturan"
     * @param array<string,string> $labels field => nama tampil
     * @return array<string,string> field => pesan galat (kosong bila valid)
     */
    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? $field;
            $value = $input[$field] ?? null;
            $value = is_string($value) ? $value : ($value === null ? '' : (string) $value);

            $ruleList = self::split($ruleString);
            if ($value === '' && !in_array('required', $ruleList, true)) {
                continue; // field opsional yang kosong: tidak ada yang perlu diperiksa
            }
            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = self::check($name, $arg, $value, $input, $labels, $label);
                if ($message !== null) {
                    $errors[$field] = $message;
                    break;
                }
            }
        }
        return $errors;
    }

    /** @return array<int,string> */
    private static function split(string $rules): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($rules);
        while ($pos < $len) {
            if (str_starts_with(substr($rules, $pos), 'regex:')) {
                $out[] = substr($rules, $pos);
                break;
            }
            $next = strpos($rules, '|', $pos);
            if ($next === false) {
                $out[] = substr($rules, $pos);
                break;
            }
            $out[] = substr($rules, $pos, $next - $pos);
            $pos   = $next + 1;
        }
        return $out;
    }

    /** @param array<string,mixed> $input @param array<string,string> $labels */
    private static function check(string $rule, ?string $arg, string $value, array $input, array $labels, string $label): ?string
    {
        switch ($rule) {
            case 'required':
                return trim($value) === '' ? "{$label} wajib diisi." : null;
            case 'min':
                return mb_strlen($value) < (int) $arg ? "{$label} minimal {$arg} karakter." : null;
            case 'max':
                return mb_strlen($value) > (int) $arg ? "{$label} maksimal {$arg} karakter." : null;
            case 'maxbytes':
                return strlen($value) > (int) $arg ? "{$label} terlalu panjang." : null;
            case 'regex':
                return preg_match((string) $arg, $value) !== 1 ? "{$label} tidak valid." : null;
            case 'same':
                return $value !== (string) ($input[(string) $arg] ?? '') ? "{$label} tidak sama dengan " . ($labels[(string) $arg] ?? $arg) . '.' : null;
            case 'different':
                return $value === (string) ($input[(string) $arg] ?? '') ? "{$label} harus berbeda dari " . ($labels[(string) $arg] ?? $arg) . '.' : null;
            case 'in':
                return !in_array($value, explode(',', (string) $arg), true) ? "{$label} tidak valid." : null;
        }
        throw new \LogicException('Aturan validasi tidak dikenal: ' . $rule);
    }
}
