<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NonWhitespaceLength implements ValidationRule
{
    public function __construct(private int $limit, private int $storageLimit) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The controller's string rule reports invalid input types.
        if (! is_string($value)) {
            return;
        }

        // Match the Unicode White_Space set used by the frontend helper.
        $count = mb_strlen(preg_replace('/[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', '', $value));

        if ($count === 0) {
            $fail('The :attribute field is required.');
        } elseif ($count > $this->limit) {
            $fail("The :attribute field must not exceed {$this->limit} characters, excluding spaces.");
        } elseif (mb_strlen($value) > $this->storageLimit) {
            $fail("The :attribute field must not exceed {$this->storageLimit} total characters, including spaces.");
        }
    }
}
