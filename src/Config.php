<?php
declare(strict_types=1);

namespace ShortsFactory;

final class Config
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}

    public static function load(?string $file = null): self
    {
        $file = $file ?? (getenv('SHORTS_CONFIG') ?: __DIR__ . '/../config/config.php');
        if (!is_file($file)) {
            $file = __DIR__ . '/../config/config.example.php';
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException("Config file must return an array: $file");
        }
        return new self($data);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function isLive(): bool
    {
        return $this->get('mode', 'test') === 'live';
    }

    /** Values of all non-empty secret-looking keys, used for log masking. */
    public function secrets(): array
    {
        $out = [];
        $walk = function (array $a, string $key = '') use (&$walk, &$out): void {
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $walk($v, (string)$k);
                } elseif (is_string($v) && strlen($v) >= 6
                    && preg_match('/key|token|secret|password/i', (string)$k)) {
                    $out[] = $v;
                }
            }
        };
        $walk($this->data);
        return array_values(array_unique($out));
    }
}
