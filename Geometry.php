<?php

namespace Surface\Drawing;

/** Shape helpers Painter and Rasterizer share, so both paths agree on segment counts and quads. */
final class Geometry
{
    public static function segments(float $radius): int
    {
        return (int) min(256, max(12, ceil($radius / 2)));
    }

    /** @return list<array{float, float}> */
    public static function ellipsePoints(float $cx, float $cy, float $rx, float $ry, int $segments): array
    {
        $points = [];
        for ($i = 0; $i < $segments; $i++) {
            $angle = 2 * M_PI * $i / $segments;
            $points[] = [$cx + $rx * cos($angle), $cy + $ry * sin($angle)];
        }

        return $points;
    }

    /** The four corners of a butt-ended quad of $stroke width along a segment; [] for a zero-length segment. @return list<array{float, float}> */
    public static function segmentCorners(float $x0, float $y0, float $x1, float $y1, float $stroke): array
    {
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $length = sqrt($dx * $dx + $dy * $dy);
        if ($length === 0.0) {
            return [];
        }
        $nx = $dy / $length * $stroke / 2.0;
        $ny = -$dx / $length * $stroke / 2.0;

        return [[$x0 + $nx, $y0 + $ny], [$x0 - $nx, $y0 - $ny], [$x1 - $nx, $y1 - $ny], [$x1 + $nx, $y1 + $ny]];
    }
}
