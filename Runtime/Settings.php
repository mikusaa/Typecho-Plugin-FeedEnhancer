<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Settings
{
    private const DEFAULT_FIELD_NAMES = ['banner', 'cover', 'thumbnail'];

    private bool $stylesheetEnabled;
    private bool $safariXmlMime;
    private bool $mediaEnabled;

    /** @var string[] */
    private array $mediaFieldNames;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->stylesheetEnabled = self::booleanValue($values, 'stylesheetEnabled', true);
        $this->safariXmlMime = self::booleanValue($values, 'safariXmlMime', false);
        $this->mediaEnabled = self::booleanValue($values, 'mediaEnabled', true);
        $this->mediaFieldNames = array_key_exists('mediaFieldNames', $values)
            ? self::normalizeFieldNames($values['mediaFieldNames'])
            : self::DEFAULT_FIELD_NAMES;
    }

    public static function load(): self
    {
        try {
            $config = Options::alloc()->plugin('FeedEnhancer');
            $values = [];
            foreach (['stylesheetEnabled', 'safariXmlMime', 'mediaEnabled', 'mediaFieldNames'] as $key) {
                $value = $config->{$key};
                if (null !== $value) {
                    $values[$key] = $value;
                }
            }

            return new self($values);
        } catch (\Throwable $exception) {
            return new self();
        }
    }

    public function stylesheetEnabled(): bool
    {
        return $this->stylesheetEnabled;
    }

    public function safariXmlMime(): bool
    {
        return $this->safariXmlMime;
    }

    public function mediaEnabled(): bool
    {
        return $this->mediaEnabled;
    }

    /** @return string[] */
    public function mediaFieldNames(): array
    {
        return $this->mediaFieldNames;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function normalizeFieldNames($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_scalar($value)) {
            $parts = explode(',', (string) $value);
        } else {
            return [];
        }

        $names = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }

            $name = trim((string) $part);
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
                continue;
            }

            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }

            if (count($names) === 10) {
                break;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $values */
    private static function booleanValue(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return $values[$key] === true || $values[$key] === 1 || $values[$key] === '1';
    }
}
