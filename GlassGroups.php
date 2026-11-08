<?php

namespace Surface\Drawing;

use Closure;
use Surface\Contracts\Drawing\Effects\Glass;
use Surface\Contracts\Framebuffers\Region;

/**
 * Which glass panes of one command list can share a backdrop capture. A pane blurs what was drawn under it before it, so
 * a capture serves a later pane only while nothing drawn since (earlier panes, their shadows, content between them) has
 * touched the area that pane reads; a pane whose read area was touched starts a new group, captured at that pane's turn.
 */
final class GlassGroups
{
    /** The drawn boxes kept apart before they are merged into one: more than this and the check is made against their union. */
    private const int DIRTY_BOXES = 48;

    /**
     * @param  list<array<int, mixed>>  $commands
     * @param  Closure(array<int, mixed>): ?Region  $box  The pixels a command can write, null for none.
     * @return list<array{region: Region, depths: list<int>, members: list<int>}> In order; members are indexes into $commands. Empty when no pane.
     */
    public static function of(array $commands, Region $surface, ?Closure $box): array
    {
        $groups = [];
        $open = null;
        $dirty = [];
        foreach ($commands as $i => $command) {
            if ($command[0] !== 'glass') {
                if ($open !== null && $box !== null) {
                    $drawn = $box($command);
                    $drawn === null || $dirty[] = $drawn;
                    count($dirty) > self::DIRTY_BOXES && $dirty = [array_reduce($dirty, fn (?Region $all, Region $r): Region => $all === null ? $r : $all->union($r))];
                }

                continue;
            }
            $read = self::reads($command, $surface);
            $depth = Glass::levels($command[4]);
            if ($open !== null && $box !== null && $read !== null && self::untouched($dirty, $read)) {
                $groups[$open]['region'] = $groups[$open]['region']->union($read);
                $groups[$open]['members'][] = $i;
                in_array($depth, $groups[$open]['depths'], true) || $groups[$open]['depths'][] = $depth;
            } else {
                $groups[] = ['region' => $read ?? $command[1], 'depths' => [$depth], 'members' => [$i]];
                $open = array_key_last($groups);
                $dirty = [];
            }
            // the pane paints its own box
            $dirty[] = $command[1];
        }
        foreach ($groups as $g => $group) {
            sort($groups[$g]['depths']);
        }

        return $groups;
    }

    /** The pixels a pane reads: its area grown by the pyramid's reach and its refraction, kept on the surface. */
    public static function reads(array $glass, Region $surface): ?Region
    {
        $by = Glass::margin($glass[4], $glass[3][1]);
        $area = $glass[1];

        return (new Region($area->x - $by, $area->y - $by, $area->width + 2 * $by, $area->height + 2 * $by))->intersect($surface);
    }

    /** @param list<Region> $dirty */
    private static function untouched(array $dirty, Region $read): bool
    {
        foreach ($dirty as $region) {
            if (! is_null($region->intersect($read))) {
                return false;
            }
        }

        return true;
    }
}
