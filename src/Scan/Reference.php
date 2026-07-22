<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * One place an attachment is referenced. Labels and edit links are resolved
 * at render time from objectType + objectId — never stored.
 */
final class Reference
{
    public const CONFIRMED = 'confirmed';
    public const POSSIBLE = 'possible';
    public const INFO = 'info';

    public function __construct(
        public readonly string $detector,   // 'postmeta', 'post-content', 'options', 'termmeta', 'usermeta', 'attached'
        public readonly string $objectType, // 'post' | 'option' | 'theme_mod' | 'term' | 'user'
        public readonly int $objectId,      // 0 for option / theme_mod
        public readonly string $detail,     // meta_key / option_name / short description
        public readonly string $match,      // 'acf' | 'thumbnail' | 'woo-gallery' | 'elementor' | 'block-id' | 'wp-image-class' | 'gallery' | 'url' | 'serialized' | 'comma-list' | 'exact' | 'widget' | 'theme-mod' | 'site-option' | 'attached'
        public readonly string $confidence, // self::CONFIRMED | self::POSSIBLE | self::INFO
    ) {
    }

    public function countsAsUsed(): bool
    {
        return $this->confidence !== self::INFO;
    }

    /** Compact array for JSON storage (short keys keep the meta small). */
    public function toArray(): array
    {
        return [
            'd' => $this->detector,
            't' => $this->objectType,
            'i' => $this->objectId,
            'k' => $this->detail,
            'm' => $this->match,
            'c' => $this->confidence,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            detector: (string) ($a['d'] ?? ''),
            objectType: (string) ($a['t'] ?? 'post'),
            objectId: (int) ($a['i'] ?? 0),
            detail: (string) ($a['k'] ?? ''),
            match: (string) ($a['m'] ?? 'exact'),
            confidence: (string) ($a['c'] ?? self::POSSIBLE),
        );
    }
}
