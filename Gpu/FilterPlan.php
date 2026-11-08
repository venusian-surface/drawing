<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Drawing\Effects\Blend;
use Surface\Contracts\Drawing\Effects\BlendMode;
use Surface\Contracts\Drawing\Effects\Blur;
use Surface\Contracts\Drawing\Effects\BlurSampling;
use Surface\Contracts\Drawing\Effects\Channel;
use Surface\Contracts\Drawing\Effects\ColorMatrix;
use Surface\Contracts\Drawing\Effects\Composite;
use Surface\Contracts\Drawing\Effects\CompositeOperator;
use Surface\Contracts\Drawing\Effects\Displace;
use Surface\Contracts\Drawing\Effects\EdgeMode;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\Effects\FilterStep;
use Surface\Contracts\Drawing\Effects\Field;
use Surface\Contracts\Drawing\Effects\FieldKind;
use Surface\Contracts\Drawing\Effects\Flood;
use Surface\Contracts\Drawing\Effects\Noise;
use Surface\Contracts\Drawing\Effects\NoiseType;
use Surface\Contracts\Drawing\Effects\Table;
use Closure;
use Surface\Framebuffers\Native\NativeFilters;

/**
 * A FilterChain as the passes a GPU device runs, the same for every device: each pass reads one or two earlier results
 * (number 0 is the region's pixels as the layer target holds them), makes one, and uses a few numbers. A device holds only
 * the arithmetic of each kind; the numbers that need a library (the box sizes, the kernel weights, the spiral offsets, the
 * band weights, the noise lattice) are worked out here, with NativeFilters's own functions, so no device repeats them.
 *
 * Every pass works on premultiplied RGBA8 whole numbers (a device keeps them as 8-bit values and fetches them exactly),
 * inside the region [x0, y0, x1, y1] the device is told: a pixel read outside it is off the image. The result of pass i
 * is number i + 1; the last pass's is the chain's, straight.
 */
final class FilterPlan
{
    /** Pass kinds. */
    public const int PREMULTIPLY = 0;      // in: straight → premultiplied by (c × a + 127) / 255

    public const int STRAIGHTEN = 1;       // in: premultiplied → straight by (c × 255 + a / 2) / a

    public const int BOX = 2;              // params axis (0 across, 1 down), size, left, edge (0 none, 1 held): the mean of the window, (sum + size / 2) / size

    public const int KERNEL = 3;           // params axis, radius, edge; data the 2 × radius + 1 integer weights: (sum of weight × value + 128) >> 8

    public const int SPIRAL = 4;           // params samples, edge; data dx, dy per sample: bilinear reads at 1/256, averaged

    public const int MATRIX = 5;           // data the 20 values: SVG feColorMatrix on straight colour

    public const int FLOOD = 6;            // params r, g, b, a (bytes): the colour, premultiplied

    public const int COMPOSITE = 7;        // in, in2; params operator (0 over, in, out, atop, xor, 5 arithmetic), k1, k2, k3, k4

    public const int BLEND = 8;            // in (above), in2 (below); params mode (0 normal, multiply, screen, darken, 4 lighten)

    public const int HASH = 9;             // params the seed's high and low 16 bits: the integer hash of the surface position

    public const int TURBULENCE = 10;      // params fractal (0, 1), octaves, fx, fy; data the lattice (NativeFilters::noiseTables(), as floats)

    public const int FIELD = 12;           // params kind (0 rounded box, 1 inside, 2 ramp); data x0, y0, x1, y1, the four radii, from, to: an image from the surface position, nothing read

    public const int TABLE = 13;           // in; params mask (1 red, 2 green, 4 blue, 8 alpha); data 256 numbers for each channel the mask has, in that order: SVG feComponentTransfer table on the straight colour

    public const int DISPLACE = 11;        // in (and in2 when the map is a result: params[5] is 1); params scale, x channel, y channel, bands, spread, from-a-result; data 3 × bands weights; map the texture number (-1 when from a result)

    /**
     * @param  array<int, int>  $maps  Displacement map texture numbers by step index.
     * @param  array<int, string>  $tables  Turbulence lattice bytes by step index (NativeFilters::noiseTables()).
     * @return list<array{kind: int, in: int, in2: int, params: list<float>, data: list<float>, map: int}>
     */
    public static function of(FilterChain $chain, bool $premultiplied, array $maps, array $tables, int $regionWidth, int $regionHeight): array
    {
        $passes = [];
        $add = function (int $kind, int $in = 0, int $in2 = 0, array $params = [], array $data = [], int $map = -1) use (&$passes): int {
            $passes[] = ['kind' => $kind, 'in' => $in, 'in2' => $in2, 'params' => array_map('floatval', $params), 'data' => array_map('floatval', $data), 'map' => $map];

            return count($passes);
        };

        $names = [FilterStep::SOURCE => $premultiplied ? 0 : $add(self::PREMULTIPLY, 0)];
        $previous = $names[FilterStep::SOURCE];
        foreach ($chain->steps as $i => $step) {
            $input = fn (?string $name): int => $name === null || $name === '' ? $previous : $names[$name];
            $made = match (true) {
                $step instanceof Blur => self::blur($add, $input($step->in), $step),
                $step instanceof ColorMatrix => $add(self::MATRIX, $input($step->in), 0, [], $step->values),
                $step instanceof Flood => $add(self::FLOOD, 0, 0, [(int) round($step->color->red * 255), (int) round($step->color->green * 255), (int) round($step->color->blue * 255), (int) round($step->color->alpha * 255)]),
                $step instanceof Composite => $add(self::COMPOSITE, $input($step->in), $input($step->in2), [array_search($step->operator, CompositeOperator::cases(), true), $step->k1, $step->k2, $step->k3, $step->k4]),
                $step instanceof Blend => $add(self::BLEND, $input($step->in), $input($step->in2), [array_search($step->mode, BlendMode::cases(), true)]),
                $step instanceof Noise => self::noise($add, $step, $tables[$i] ?? '', $regionWidth, $regionHeight),
                $step instanceof Field => $add(self::FIELD, 0, 0, [array_search($step->kind, FieldKind::cases(), true)],
                    [...($step->box ?? [0, 0, 0, 0]), ...$step->radii, $step->from, $step->to]),
                $step instanceof Table => $add(self::TABLE, $input($step->in), 0,
                    [($step->red !== null ? 1 : 0) | ($step->green !== null ? 2 : 0) | ($step->blue !== null ? 4 : 0) | ($step->alpha !== null ? 8 : 0)],
                    [...($step->red ?? []), ...($step->green ?? []), ...($step->blue ?? []), ...($step->alpha ?? [])]),
                $step instanceof Displace => $add(self::DISPLACE, $input($step->in), is_string($step->map) ? $input($step->map) : 0,
                    [$step->scale, array_search($step->xChannel, Channel::cases(), true), array_search($step->yChannel, Channel::cases(), true), $step->bands, $step->spread, is_string($step->map) ? 1 : 0],
                    $step->bands === 1 ? [] : array_merge(...array_map(fn (array $w): array => $w, $step->weights ?? NativeFilters::bandWeights($step->bands))), is_string($step->map) ? -1 : $maps[$i]),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $names[$name] = $made;
            }
        }
        $add(self::STRAIGHTEN, $previous);

        return $passes;
    }

    /**
     * For each result number, the index of the last pass that reads it (as `in` or `in2`); the last result is read by the copy
     * back into the layer, after every pass. A device may give a result's image to a later pass as its output once the pass that
     * last reads it is done, so a chain holds as many images as it has results alive at once and not one for each pass.
     *
     * @param  list<array{kind: int, in: int, in2: int, params: list<float>, data: list<float>, map: int}>  $passes
     * @return array<int, int> result number => last reading pass (result 0, the layer's own pixels, is left out)
     */
    public static function lastReads(array $passes): array
    {
        $last = [];
        foreach ($passes as $i => $pass) {
            foreach ([$pass['in'], $pass['in2']] as $result) {
                $last[$result] = $i;
            }
        }
        unset($last[0]);
        $last[count($passes)] = PHP_INT_MAX;

        return $last;
    }

    private static function blur(Closure $add, int $in, Blur $blur): int
    {
        $edge = $blur->edge === EdgeMode::DUPLICATE ? 1 : 0;
        if ($blur->sampling === BlurSampling::SPIRAL) {
            if ($blur->x <= 0.0) {
                return $in;
            }

            return $add(self::SPIRAL, $in, 0, [$blur->samples, $edge], array_merge(...NativeFilters::spiralOffsets($blur->x, $blur->samples)));
        }
        foreach ([[0, $blur->x], [1, $blur->y]] as [$axis, $sigma]) {
            if ($sigma <= 0.0) {
                continue;
            }
            if ($sigma < 2.0) {
                $weights = NativeFilters::kernelWeights($sigma);
                $in = $add(self::KERNEL, $in, 0, [$axis, intdiv(count($weights) - 1, 2), $edge], $weights);

                continue;
            }
            foreach (NativeFilters::boxes($sigma) as [$size, $left]) {
                $in = $add(self::BOX, $in, 0, [$axis, $size, $left, $edge]);
            }
        }

        return $in;
    }

    private static function noise(Closure $add, Noise $noise, string $tables, int $width, int $height): int
    {
        if ($noise->type === NoiseType::HASH) {
            return $add(self::HASH, 0, 0, [($noise->seed & 0xFFFFFFFF) >> 16, $noise->seed & 0xFFFF]);
        }
        [$fx, $fy] = NativeFilters::stitched($noise, $width, $height);

        return $add(self::TURBULENCE, 0, 0, [$noise->type === NoiseType::FRACTAL ? 1 : 0, $noise->octaves, $fx, $fy], self::lattice($tables));
    }

    /**
     * The lattice bytes as floats: the 514 selector entries, then 4 × 514 gradient pairs.
     *
     * @return list<float>
     */
    private static function lattice(string $tables): array
    {
        return [...array_map('floatval', array_values(unpack('v514', $tables))), ...array_map('floatval', array_values(unpack('g*', substr($tables, 1028))))];
    }
}
