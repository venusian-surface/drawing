<?php

namespace Surface\Drawing\Gpu;

use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Framebuffers\Spans;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Rasterize\Stroker;

/**
 * A frame's commands (RenderingEngine) as a DrawList, the same for every GPU
 * device and both edge modes. Paths are filled stencil-then-cover from
 * triangle lists: a contour of n points is the n − 2 triangles from its first
 * point, so nothing is tessellated and the cost is copying the points.
 */
final class Lowering
{
    /** Floats packed per pack() call. */
    private const int CHUNK = 4096;

    private string $vertices = '';

    /** @var list<array<int, mixed>> */
    private array $operations = [];

    private ?Region $scissor = null;

    /** @var array<int, int> Texture number by the source's object id. */
    private array $textures = [];

    private function __construct() {}

    /**
     * @param  list<array<int, mixed>>  $commands
     */
    public static function lower(array $commands, int $width, int $height): DrawList
    {
        $lowering = new self;
        foreach ($commands as $command) {
            $lowering->command($command);
        }

        return new DrawList($width, $height, $lowering->vertices, $lowering->operations);
    }

    /** @param array<int, mixed> $command */
    private function command(array $command): void
    {
        switch ($command[0]) {
            case 'clear':
                if (! isset($command[2])) {
                    $this->operations[] = [Op::CLEAR, $command[1]];

                    return;
                }
                $region = $command[2];
                $this->clip($region);
                $this->operations[] = [Op::SOLID, $this->quad($region->x, $region->y, $region->right(), $region->bottom()), $command[1]];

                return;

            case 'path':
                $this->fill($command[1], $command[2], $command[3], $command[4]);

                return;

            case 'polyline':
                $outline = array_map(fn (array $flat): array => array_chunk($flat, 2), Stroker::outline($command[1], $command[2], $command[3]));
                $this->fill($outline, FillRule::NON_ZERO, $command[4], $command[5]);

                return;

            case 'ellipse':
                [, $cx, $cy, $rx, $ry, $rgba, $clip] = $command;
                $this->clip($clip);
                $this->operations[] = [Op::ELLIPSE, $this->quad($cx - $rx - 1.0, $cy - $ry - 1.0, $cx + $rx + 1.0, $cy + $ry + 1.0), $cx, $cy, $rx, $ry, $rgba];

                return;

            case 'ring':
                [, $cx, $cy, $rx, $ry, $stroke, $rgba, $clip] = $command;
                $reach_x = $rx + $stroke / 2 + 1.0;
                $reach_y = $ry + $stroke / 2 + 1.0;
                $this->clip($clip);
                $this->operations[] = [Op::RING, $this->quad($cx - $reach_x, $cy - $reach_y, $cx + $reach_x, $cy + $reach_y), $cx, $cy, $rx, $ry, $stroke, $rgba];

                return;

            case 'image':
                [, $source, $placement, $opacity, $filter, $clip] = $command;
                $id = spl_object_id($source);
                if (! isset($this->textures[$id])) {
                    $this->textures[$id] = count($this->textures);
                    $this->operations[] = [Op::UPLOAD, $this->textures[$id], $source];
                }
                $width = (float) $source->viewportWidth();
                $height = (float) $source->viewportHeight();
                [$ax, $ay] = $placement->apply(0.0, 0.0);
                [$bx, $by] = $placement->apply($width, 0.0);
                [$cx, $cy] = $placement->apply($width, $height);
                [$dx, $dy] = $placement->apply(0.0, $height);
                $this->clip($clip);
                $this->operations[] = [Op::IMAGE, $this->push([$ax, $ay, $bx, $by, $cx, $cy, $ax, $ay, $cx, $cy, $dx, $dy]), $this->textures[$id], $placement->inverse(), $opacity, $filter];

                return;

            case 'spans':
                [, $spans, $rgba, $clip] = $command;
                $floats = [];
                foreach (Spans::unpack($spans) as [$y, $x, $length]) {
                    array_push($floats, $x, $y, $x + $length, $y, $x + $length, $y + 1, $x, $y, $x + $length, $y + 1, $x, $y + 1);
                }
                $this->clip($clip);
                $this->operations[] = [Op::RECTS, $this->push($floats), intdiv(count($floats), 2), $rgba];

                return;
        }
    }

    /**
     * @param  list<list<array{float, float}>>  $contours
     */
    private function fill(array $contours, FillRule $rule, int $rgba, Region $clip): void
    {
        $floats = [];
        $xs = [];
        $ys = [];
        foreach ($contours as $contour) {
            [$x0, $y0] = $contour[0];
            for ($i = 1, $last = count($contour) - 1; $i < $last; $i++) {
                array_push($floats, $x0, $y0, $contour[$i][0], $contour[$i][1], $contour[$i + 1][0], $contour[$i + 1][1]);
            }
            foreach ($contour as [$x, $y]) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        if ($floats === []) {
            return;
        }

        $this->clip($clip);
        $this->operations[] = [Op::STENCIL_FILL, $this->push($floats), intdiv(count($floats), 2), $rule];
        $this->operations[] = [Op::COVER, $this->quad(floor(min($xs)), floor(min($ys)), ceil(max($xs)), ceil(max($ys))), $rgba];
    }

    private function clip(Region $region): void
    {
        if ($this->scissor != $region) {
            $this->operations[] = [Op::SCISSOR, $region];
            $this->scissor = $region;
        }
    }

    /** @return int The quad's first vertex. */
    private function quad(float $x0, float $y0, float $x1, float $y1): int
    {
        return $this->push([$x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1]);
    }

    /**
     * @param  list<int|float>  $floats  x, y pairs
     * @return int The first vertex pushed.
     */
    private function push(array $floats): int
    {
        $first = intdiv(strlen($this->vertices), 8);
        foreach (array_chunk($floats, self::CHUNK) as $chunk) {
            $this->vertices .= pack('g*', ...$chunk);
        }

        return $first;
    }
}
