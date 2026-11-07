<?php
declare(strict_types=1);

/** Compact, server-only validator. */
class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data) { $this->data = $data; }

    private function value(string $field) { return $this->data[$field] ?? null; }

    private function set_error(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) $this->errors[$field] = $message;
    }

    public function required(string $field, string $label): self
    {
        $v = $this->value($field);
        if (is_array($v)) {
            if (empty($v)) $this->set_error($field, $label . ' is required.');
        } elseif ($v === null || (is_string($v) && trim($v) === '')) {
            $this->set_error($field, $label . ' is required.');
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->set_error($field, $label . ' must be a valid email address.');
        }
        return $this;
    }

    public function phone(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '') {
            $digits = preg_replace('/\D+/', '', (string)$v);
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                $this->set_error($field, $label . ' must be 10–15 digits.');
            }
        }
        return $this;
    }

    public function min(string $field, int $min, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && mb_strlen((string)$v) < $min) {
            $this->set_error($field, $label . ' must be at least ' . $min . ' characters.');
        }
        return $this;
    }

    public function max(string $field, int $max, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && mb_strlen((string)$v) > $max) {
            $this->set_error($field, $label . ' must be at most ' . $max . ' characters.');
        }
        return $this;
    }

    public function numeric(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !is_numeric($v)) {
            $this->set_error($field, $label . ' must be a number.');
        }
        return $this;
    }

    public function integer(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !preg_match('/^-?\d+$/', (string)$v)) {
            $this->set_error($field, $label . ' must be a whole number.');
        }
        return $this;
    }

    public function min_value(string $field, float $min, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && is_numeric($v) && (float)$v < $min) {
            $this->set_error($field, $label . ' must be at least ' . $min . '.');
        }
        return $this;
    }

    public function max_value(string $field, float $max, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && is_numeric($v) && (float)$v > $max) {
            $this->set_error($field, $label . ' must be at most ' . $max . '.');
        }
        return $this;
    }

    public function date(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '') {
            $d = DateTime::createFromFormat('Y-m-d', (string)$v);
            if (!$d || $d->format('Y-m-d') !== (string)$v) {
                $this->set_error($field, $label . ' must be a valid date (YYYY-MM-DD).');
            }
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !in_array($v, $allowed, true)) {
            $this->set_error($field, $label . ' contains an invalid value.');
        }
        return $this;
    }

    public function errors(): array { return $this->errors; }
    public function passes(): bool  { return empty($this->errors); }
    public function fails(): bool   { return !empty($this->errors); }
}

function first_error(array $errors): string
{
    foreach ($errors as $err) {
        if (is_string($err) && $err !== '') return $err;
    }
    return 'Please review the form and try again.';
}