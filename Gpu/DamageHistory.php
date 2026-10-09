<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\DamageRecord;

/**
 * What a device's swapchain images are owed. record() turns one present's
 * target damage into surface pixels through where the target lands
 * (LentSurface::presentRect()); since($age) is what an image last shown
 * $age presents ago repaints: the union of the last $age presents, this one
 * included. That is EGL_EXT_buffer_age's count; a Vulkan device counts the
 * presents since it last acquired the same image index.
 *
 * A scaled target's damage grows by one surface pixel each side, the reach
 * of a linear filter. A change of surface size, target size or placement
 * starts the history over with the whole surface, bars included, as does an
 * age of 0 (unknown) or one older than the history keeps.
 */
final class DamageHistory
{
    /** @var list<list<Region>> Oldest first. */
    private array $presents = [];

    /** @var list<int> Target size, placement and surface size the history was recorded under. */
    private array $under = [];

    /** @param  int  $depth  How many presents are kept: the deepest swapchain the device runs. */
    public function __construct(private readonly int $depth = 4)
    {
        if ($depth < 1) {
            throw new DrawingException("A damage history keeps at least 1 present, got {$depth}.");
        }
    }

    /**
     * @param  list<Region>  $damage  The target's damage(), in target pixels.
     * @param  Region  $placed  Where the target lands in the surface.
     * @return list<Region> This present's damage, in surface pixels.
     */
    public function record(array $damage, int $targetWidth, int $targetHeight, Region $placed, int $surfaceWidth, int $surfaceHeight): array
    {
        $under = [$targetWidth, $targetHeight, $placed->x, $placed->y, $placed->width, $placed->height, $surfaceWidth, $surfaceHeight];
        if ($under !== $this->under) {
            $this->under = $under;
            $this->presents = [[$this->whole()]];

            return [$this->whole()];
        }

        $record = new DamageRecord;
        foreach ($damage as $region) {
            $mapped = $this->map($region, $targetWidth, $targetHeight, $placed);
            if (! is_null($mapped)) {
                $record->add($mapped);
            }
        }
        $this->presents[] = $record->written();
        if (count($this->presents) > $this->depth) {
            array_shift($this->presents);
        }

        return $record->written();
    }

    /**
     * @return list<Region> What an image last shown $age presents ago repaints, in surface pixels.
     *
     * @throws DrawingException Before the first record().
     */
    public function since(int $age): array
    {
        if ($this->presents === []) {
            throw new DrawingException('Nothing was recorded: record() each present before since().');
        }
        if ($age < 1 || $age > count($this->presents)) {
            return [$this->whole()];
        }

        $record = new DamageRecord;
        foreach (array_slice($this->presents, -$age) as $present) {
            foreach ($present as $region) {
                $record->add($region);
            }
        }

        return $record->written();
    }

    /**
     * GL's rects: the same pixels, origin bottom-left in a surface $height tall.
     *
     * @param  list<Region>  $regions
     * @return list<Region>
     */
    public static function flipped(array $regions, int $height): array
    {
        return array_map(fn (Region $region): Region => new Region($region->x, $height - $region->bottom(), $region->width, $region->height), $regions);
    }

    private function map(Region $region, int $targetWidth, int $targetHeight, Region $placed): ?Region
    {
        if ($placed->width === $targetWidth && $placed->height === $targetHeight) {
            return (new Region($placed->x + $region->x, $placed->y + $region->y, $region->width, $region->height))->intersect($placed);
        }

        $across = $placed->width / $targetWidth;
        $down = $placed->height / $targetHeight;
        $left = (int) floor($placed->x + $region->x * $across) - 1;
        $top = (int) floor($placed->y + $region->y * $down) - 1;
        $right = (int) ceil($placed->x + $region->right() * $across) + 1;
        $bottom = (int) ceil($placed->y + $region->bottom() * $down) + 1;

        return (new Region($left, $top, $right - $left, $bottom - $top))->intersect($placed);
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->under[6], $this->under[7]);
    }
}
