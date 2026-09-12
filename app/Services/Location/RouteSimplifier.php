<?php

namespace App\Services\Location;

/**
 * Fewer points, same shape.
 *
 * ## Why not every-nth-point
 *
 * The first version of the trail endpoint kept one point in N. It is one line
 * of code and it is wrong in a way that only shows on a map: keeping by
 * *position* is blind to shape, so it spends most of its budget on the
 * straight stretch of highway where nothing happens and then throws away the
 * roundabout, because the roundabout happened to fall between two strides.
 *
 * The result is a route that looks approximately right at a glance and cuts
 * corners through buildings when you zoom in — which, on a screen somebody
 * opened to find out where their child actually went, is the failure that
 * matters.
 *
 * ## Douglas–Peucker
 *
 * Recursive: keep the two endpoints, find the point furthest from the line
 * between them, and if it is further than the tolerance, keep it and repeat on
 * both halves. Points on a straight run are all close to that line and vanish;
 * a corner is by definition far from it and survives.
 *
 * So the budget goes where the shape is. A two-hour drive down a motorway
 * reduces to a handful of points, and the three turns getting off it keep all
 * of theirs.
 *
 * ## Iterative, not recursive
 *
 * The textbook version recurses. A day's driving can be twenty thousand
 * points, and a pathological one — a route that is nearly straight, so every
 * split is lopsided — recurses to a depth of twenty thousand. PHP's stack does
 * not survive that, and the failure is a segfault rather than an exception,
 * which is the worst kind of failure to debug. An explicit stack costs four
 * lines and cannot blow up.
 */
class RouteSimplifier
{
    private const EARTH_METRES = 6371000.0;

    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     * @param  float  $toleranceMetres  how far a point may sit off the line
     *                                  before it is worth keeping
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function simplify(array $points, float $toleranceMetres): array
    {
        $count = count($points);

        if ($count <= 2) {
            return $points;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        /** @var array<int, array{int, int}> $stack */
        $stack = [[0, $count - 1]];

        while ($stack !== []) {
            [$first, $last] = array_pop($stack);

            if ($last <= $first + 1) {
                continue;
            }

            $furthest = -1;
            $worst = 0.0;

            for ($i = $first + 1; $i < $last; $i++) {
                $distance = self::perpendicular(
                    $points[$i],
                    $points[$first],
                    $points[$last],
                );

                if ($distance > $worst) {
                    $worst = $distance;
                    $furthest = $i;
                }
            }

            if ($worst <= $toleranceMetres || $furthest < 0) {
                continue;
            }

            $keep[$furthest] = true;

            $stack[] = [$first, $furthest];
            $stack[] = [$furthest, $last];
        }

        $out = [];

        for ($i = 0; $i < $count; $i++) {
            if ($keep[$i]) {
                $out[] = $points[$i];
            }
        }

        return $out;
    }

    /**
     * Simplify until the result fits, whatever the shape.
     *
     * A tolerance alone cannot promise a size: a genuinely wiggly walk through
     * a market keeps every point at any sensible tolerance. The payload has to
     * be bounded regardless, so the tolerance doubles until it fits.
     *
     * Doubling rather than a binary search because each pass is cheap relative
     * to the query that produced the points, and eight passes is the worst
     * case for going from 5 m to 1.3 km.
     *
     * @param  array<int, array{lat: float, lng: float}>  $points
     * @return array<int, array{lat: float, lng: float}>
     */
    public static function fit(array $points, int $max, float $tolerance = 8.0): array
    {
        if (count($points) <= $max) {
            return $points;
        }

        for ($pass = 0; $pass < 12; $pass++) {
            $simplified = self::simplify($points, $tolerance);

            if (count($simplified) <= $max) {
                return $simplified;
            }

            $tolerance *= 2;
        }

        // Twelve doublings is a 32 km tolerance and the thing still does not
        // fit, which means the input is not a route. Truncate rather than
        // loop: whatever this is, the map is not going to make sense of it.
        return array_slice(self::simplify($points, $tolerance), 0, $max);
    }

    /**
     * Distance from a point to the segment between two others, in metres.
     *
     * Works in a local flat projection rather than on the sphere. Over the
     * length of one segment of a journey — tens of metres, rarely more than a
     * kilometre — the curvature error is far below the tolerance being
     * compared against, and the alternative is a cross-track formula with
     * three trig calls per point on a hot loop.
     *
     * The cos(lat) factor on longitude is the part that cannot be skipped: at
     * Delhi's latitude a degree of longitude is 12% shorter than a degree of
     * latitude, and ignoring that distorts every diagonal.
     *
     * @param  array{lat: float, lng: float}  $point
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     */
    private static function perpendicular(array $point, array $a, array $b): float
    {
        $scale = cos(deg2rad($a['lat'])) * self::EARTH_METRES * M_PI / 180.0;
        $vertical = self::EARTH_METRES * M_PI / 180.0;

        $px = ($point['lng'] - $a['lng']) * $scale;
        $py = ($point['lat'] - $a['lat']) * $vertical;

        $bx = ($b['lng'] - $a['lng']) * $scale;
        $by = ($b['lat'] - $a['lat']) * $vertical;

        $lengthSquared = $bx * $bx + $by * $by;

        // A and B are the same place — the route doubled back on itself. The
        // "line" is a point, so the distance to it is the plain distance.
        if ($lengthSquared < 1e-9) {
            return sqrt($px * $px + $py * $py);
        }

        // Projection of AP onto AB, clamped to the segment so a point beyond
        // an endpoint measures to the endpoint rather than to the infinite
        // line through it.
        $t = max(0.0, min(1.0, ($px * $bx + $py * $by) / $lengthSquared));

        $dx = $px - $t * $bx;
        $dy = $py - $t * $by;

        return sqrt($dx * $dx + $dy * $dy);
    }
}
