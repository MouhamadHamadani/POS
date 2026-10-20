<?php

namespace App\Http\Controllers;

use App\Exceptions\FeatureDependencyException;
use App\Facades\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings > Plan & Features: per-install feature overrides. Vendor territory — the
 * routes sit in the `role:super_admin` group in routes/web.php, and deliberately carry
 * no `feature:` key, so no plan or override can ever lock the vendor out of this screen.
 * Which plan the install is on is SettingController::updatePlan; the rules (dependencies,
 * audit) are FeatureService's.
 */
class FeatureController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        // The form lists every toggleable feature, so an unticked box is simply absent.
        $posted = (array) $request->input('features', []);
        $desired = [];
        foreach (Feature::toggleable() as $key) {
            $desired[$key] = isset($posted[$key]);
        }

        try {
            Feature::sync($desired, $request->user()->id);
        } catch (FeatureDependencyException $e) {
            return $this->back()->withErrors(['features' => $e->getMessage()]);
        }

        return $this->back()->with('success', __('messages.features_saved'));
    }

    public function reset(Request $request): RedirectResponse
    {
        try {
            Feature::reset($request->user()->id);
        } catch (FeatureDependencyException $e) {
            return $this->back()->withErrors(['features' => $e->getMessage()]);
        }

        return $this->back()->with('success', __('messages.features_reset', ['plan' => __('plan.tier.'.Feature::plan())]));
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'plan']);
    }
}
