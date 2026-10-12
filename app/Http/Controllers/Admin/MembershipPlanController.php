<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MembershipPlanRequest;
use App\Models\MembershipPlan;
use App\Support\AdminAudit;
use App\Support\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Membership plans: what they cost, how they bill and which features they unlock. */
class MembershipPlanController extends Controller
{
    public function index(): View
    {
        return view('admin.memberships.index', [
            'plans' => MembershipPlan::query()->withCount(['subscriptions as active_subscribers' => fn ($q) => $q->effective()])->orderBy('sort_order')->orderBy('price_minor')->get(),
        ]);
    }

    public function create(): View
    {
        $plan = new MembershipPlan(['status' => 'draft', 'currency' => 'USD', 'billing_interval' => 'month', 'interval_count' => 1, 'trial_days' => 0, 'grace_days' => 0, 'auto_renews' => true, 'sort_order' => 10, 'entitlements' => [], 'limits' => [], 'features' => [], 'store_product_ids' => []]);

        return view('admin.memberships.form', ['plan' => $plan, 'entitlements' => Entitlement::cases(), 'limits' => Entitlement::LIMITS]);
    }

    public function store(MembershipPlanRequest $request): RedirectResponse
    {
        $plan = $this->save(new MembershipPlan, $request);
        if ($plan instanceof RedirectResponse) {
            return $plan;
        }
        AdminAudit::record('membership_plan.create', ['plan_id' => $plan->id, 'slug' => $plan->slug]);

        return redirect()->route('admin.memberships.edit', $plan)->with('status', 'Plan created.');
    }

    public function edit(MembershipPlan $plan): View
    {
        return view('admin.memberships.form', ['plan' => $plan, 'entitlements' => Entitlement::cases(), 'limits' => Entitlement::LIMITS]);
    }

    public function update(MembershipPlanRequest $request, MembershipPlan $plan): RedirectResponse
    {
        $result = $this->save($plan, $request);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        AdminAudit::record('membership_plan.update', ['plan_id' => $plan->id, 'slug' => $plan->slug]);

        return redirect()->route('admin.memberships.edit', $plan)->with('status', 'Plan saved. Apps pick up the change on their next refresh.');
    }

    /** Plans are archived, never deleted: subscription history keeps pointing at them. */
    public function destroy(MembershipPlan $plan): RedirectResponse
    {
        if ($plan->is_default) {
            return back()->withErrors(['plan' => 'The default plan cannot be archived. Make another plan the default first.']);
        }

        $plan->update(['status' => 'archived']);
        AdminAudit::record('membership_plan.archive', ['plan_id' => $plan->id]);

        return redirect()->route('admin.memberships.index')->with('status', 'Plan archived. Current members keep it until their membership ends.');
    }

    private function save(MembershipPlan $plan, MembershipPlanRequest $request): MembershipPlan|RedirectResponse
    {
        $attributes = $request->planAttributes();
        $wantsDefault = $request->boolean('is_default');

        if (($wantsDefault || $plan->is_default) && $attributes['price_minor'] > 0) {
            return back()->withInput()->withErrors(['price' => 'The default plan is what everyone without a paid membership gets, so it must be free.']);
        }
        if (($wantsDefault || $plan->is_default) && $attributes['status'] !== 'published') {
            return back()->withInput()->withErrors(['status' => 'The default plan must be published.']);
        }
        if ($plan->is_default && ! $wantsDefault) {
            return back()->withInput()->withErrors(['is_default' => 'Choose another plan as the default instead of unsetting this one.']);
        }

        DB::transaction(function () use ($plan, $attributes, $wantsDefault) {
            if ($wantsDefault && ! $plan->is_default) {
                MembershipPlan::query()->where('is_default', true)->update(['is_default' => false]);
                $attributes['is_default'] = true;
            }
            $plan->fill($attributes);
            $plan->is_default = $attributes['is_default'] ?? (bool) $plan->is_default;
            $plan->save();
        });

        return $plan;
    }
}
