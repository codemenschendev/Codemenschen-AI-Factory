<?php

namespace App\Http\Controllers;

use App\Domain\Werk\Ads;
use App\Domain\Werk\Business;
use App\Domain\Werk\Overview;
use App\Domain\Werk\Probe;
use App\Domain\Werk\Range;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * werk-connect for appmitki.com (Werk docs/API.md 7.10): Werk and werk.ai read the site with its
 * probe token (middleware WerkToken). Public address https://appmitki.com/_werk/v1/*, which the
 * Next.js storefront hands on to /api/werk/v1/* here. Read only, counts only.
 */
class WerkController extends Controller
{
    public function probe(Probe $probe): JsonResponse
    {
        return response()->json($probe->status());
    }

    /** 7.9: from..to at most 200 days, since inside it. */
    public function overview(Request $request, Overview $overview): JsonResponse
    {
        $range = Range::parse($request->query('from'), $request->query('to'), $request->query('since'), 200);

        return $range === null ? self::invalid() : response()->json($overview->build($range));
    }

    /** Appwerk's own campaigns; the last 30 days by default, at most 400. */
    public function ads(Request $request, Ads $ads): JsonResponse
    {
        $range = Range::parse($request->query('from'), $request->query('to'), null, 400, 30);

        return $range === null ? self::invalid() : response()->json($ads->build($range));
    }

    /** The funnel and the pipeline as counts; the last 30 days by default, at most 400. */
    public function business(Request $request, Business $business): JsonResponse
    {
        $range = Range::parse($request->query('from'), $request->query('to'), null, 400, 30);

        return $range === null ? self::invalid() : response()->json($business->build($range));
    }

    private static function invalid(): JsonResponse
    {
        return response()->json(['error' => 'invalid'], 422);
    }
}
