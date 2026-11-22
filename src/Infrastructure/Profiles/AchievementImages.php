<?php

declare(strict_types=1);

namespace Modules\Achievements\Infrastructure\Profiles;

use App\Infrastructure\Seo\CloudinaryImageUrlBuilder;

/**
 * The https address of a badge image: a square of 48 × 48 (drawn at 24, sharp on dense screens),
 * the crop the core's badges expect. Null without an image or without Cloudinary.
 */
final readonly class AchievementImages
{
    public const int BADGE_SIZE = 48;

    /** On the achievements page and the profile section, drawn at 48 */
    public const int TILE_SIZE = 96;

    public function __construct(
        private CloudinaryImageUrlBuilder $cloudinary,
    ) {}

    public function badge(?string $publicId): ?string
    {
        return $this->square($publicId, self::BADGE_SIZE);
    }

    public function tile(?string $publicId): ?string
    {
        return $this->square($publicId, self::TILE_SIZE);
    }

    private function square(?string $publicId, int $size): ?string
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        $url = $this->cloudinary->banner($publicId, $size, $size);

        return $url !== null && str_starts_with($url, 'https://') ? $url : null;
    }
}
