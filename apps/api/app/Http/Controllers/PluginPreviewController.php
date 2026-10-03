<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A Sofabuilt plugin in WordPress Playground (docs/specs/sofabuilt.md): the blueprint tells
 * Playground to log in, install WooCommerce when the plugin needs it, and install the latest built
 * ZIP. Playground runs in the visitor's browser and fetches both from here (CORS is open on /api).
 * The project id is the only key, as for the app previews.
 */
class PluginPreviewController extends Controller
{
    public function blueprint(Project $project): JsonResponse
    {
        abort_unless($project->kind === 'plugin' && $this->latest($project) !== null, 404);
        $requires = $project->order?->quote?->breakdown['scope']['requires'] ?? [];
        $steps = [];
        if (! empty($requires['woocommerce'])) {
            $steps[] = ['step' => 'installPlugin', 'pluginData' => ['resource' => 'wordpress.org/plugins', 'slug' => 'woocommerce'], 'options' => ['activate' => true]];
        }
        $steps[] = ['step' => 'installPlugin', 'pluginData' => ['resource' => 'url', 'url' => rtrim(config('app.url'), '/')."/api/plugin/{$project->id}/plugin.zip"], 'options' => ['activate' => true]];

        return response()->json([
            '$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/wp-admin/plugins.php',
            'preferredVersions' => ['php' => '8.3', 'wp' => 'latest'],
            'features' => ['networking' => true],
            'login' => true,
            'steps' => $steps,
        ])->header('Cache-Control', 'no-store');
    }

    public function zip(Project $project): BinaryFileResponse
    {
        abort_unless($project->kind === 'plugin', 404);
        $path = $this->latest($project);
        abort_if($path === null, 404);

        return response()->download($path, basename($path), ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
    }

    private function latest(Project $project): ?string
    {
        $build = $project->builds()->where('platform', 'plugin')->latest('updated_at')->first();
        if ($build === null || ! $build->artifact_path) {
            return null;
        }
        $path = rtrim((string) config('services.worker.artifacts_path'), '/').'/'.ltrim($build->artifact_path, '/');

        return is_file($path) ? $path : null;
    }
}
