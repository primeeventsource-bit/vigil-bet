<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\BillingEvent;
use App\Models\PublishedPick;
use App\Models\ShadowPick;
use App\Models\ShadowResult;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserBet;
use App\Services\Admin\FeatureFlags;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Entitlements\Entitlements;
use App\Services\Record\PublicRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The admin area. Read-only pages; writes live in AdminActionController.
 *
 * Split that way on purpose. Everything here is a question - how many
 * customers, what has been charged, what has the AI cost, what did the
 * engine refuse - and none of it should be able to change anything. The
 * writes are few, deliberate, and every one of them is audited.
 *
 * §11 lists what this has to show: users, subscriptions, billing events,
 * refunds, AI cost, sync health, the published-picks ledger and feature
 * flags. All of it is here, and all of it reads from the same tables the
 * customer-facing pages read - so admin and the product cannot disagree
 * about a figure, which is the usual way an admin dashboard starts
 * lying.
 */
class AdminController extends Controller
{
    public function overview(SyncHealth $health, PublicRecord $record, FeatureFlags $flags): View
    {
        return view('admin.overview', [
            'customers' => User::count(),
            'verified' => User::whereNotNull('email_verified_at')->count(),
            'subscribers' => Subscription::where('tier', '!=', 'free')
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->count(),
            'byTier' => Subscription::query()
                ->selectRaw('tier, count(*) as total')
                ->groupBy('tier')
                ->pluck('total', 'tier'),
            'pastDue' => Subscription::where('status', 'past_due')->count(),

            /*
             * Revenue is the sum of money that actually moved, read from
             * billing_events. Not from subscription prices multiplied by
             * subscriber counts - that figure is what a product *hopes*
             * it is charging, and the two diverge the first time a
             * payment fails.
             */
            'chargedCents' => (int) BillingEvent::query()
                ->whereIn('type', [BillingEvent::SUBSCRIBED, BillingEvent::RENEWED])
                ->sum('amount_cents'),
            'refundedCents' => (int) BillingEvent::query()
                ->where('type', BillingEvent::REFUNDED)
                ->sum('amount_cents'),

            'record' => $record->summary(),
            'syncHealth' => $health->all(),

            'aiSpendMonth' => (int) AiUsage::where('created_at', '>=', now()->startOfMonth())
                ->sum('cost_millicents'),
            'aiSpendAll' => (int) AiUsage::sum('cost_millicents'),
            'aiRejected' => AiUsage::where('accepted', false)->count(),
            'aiCalls' => AiUsage::count(),

            'trackedBets' => UserBet::count(),
            'alertsSent' => AlertDelivery::where('status', AlertDelivery::SENT)->count(),

            'flags' => $flags->describe(),

            // The most recent administrative writes, so the first thing
            // an admin sees is what was last changed and by whom.
            'audit' => AuditEvent::query()->with('actor')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function users(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('admin.users', [
            'search' => $search,
            'users' => User::query()
                ->with('subscription')
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                    $q->where('email', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                }))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    /**
     * One customer, and everything the product knows about them.
     *
     * On one page rather than four, because the question an admin
     * actually has is never "what are this person's billing events" - it
     * is "this person emailed us, what is going on", and that is
     * answered by seeing the plan, the payments, the overrides and the
     * bets together.
     */
    public function user(User $user): View
    {
        return view('admin.user', [
            'customer' => $user->load(['subscription', 'entitlementOverrides']),
            'billing' => BillingEvent::query()
                ->where('user_id', $user->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(50)->get(),
            'bets' => UserBet::query()
                ->where('user_id', $user->id)
                ->orderByDesc('placed_at')->limit(20)->get(),
            'alerts' => AlertDelivery::query()
                ->where('user_id', $user->id)->with('alert')
                ->orderByDesc('delivered_at')->limit(20)->get(),
            'aiSpend' => (int) AiUsage::where('user_id', $user->id)->sum('cost_millicents'),
            // Only what this customer could actually be granted.
            'features' => app(Entitlements::class)->allFeatures(),
            'tiers' => array_keys((array) config('plans.tiers')),
            'audit' => AuditEvent::query()->with('actor')
                ->where('subject_type', 'User')->where('subject_id', $user->id)
                ->orderByDesc('created_at')->limit(20)->get(),
        ]);
    }

    /**
     * Every billing event, newest first.
     *
     * §11 asks for billing events and refunds. They are the same list:
     * a refund is an event, and separating them would invite somebody to
     * read one without the other.
     */
    public function billing(Request $request): View
    {
        $type = (string) $request->query('type', '');

        return view('admin.billing', [
            'type' => $type,
            'types' => BillingEvent::query()->distinct()->orderBy('type')->pluck('type'),
            'events' => BillingEvent::query()
                ->with('user')
                ->when($type !== '', fn ($q) => $q->where('type', $type))
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            /*
             * Events with no customer attached: a processor billing
             * somebody we cannot identify. Surfaced rather than buried,
             * because it is the first sign of a webhook pointed at the
             * wrong environment.
             */
            'unmatched' => BillingEvent::whereNull('user_id')->count(),
        ]);
    }

    /** The published-picks ledger, §11's phrase, including the losers. */
    public function picks(): View
    {
        return view('admin.picks', [
            'picks' => PublishedPick::query()
                ->with('result')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate(50),
            'published' => PublishedPick::count(),
            'pending' => PublishedPick::query()->pending()->count(),
        ]);
    }

    /**
     * What the AI has cost, and how often it was refused.
     *
     * The rejection count is the number that matters. A rising spend
     * with a rising rejection rate is a prompt problem arriving as a
     * bill, and it is invisible unless the two are shown together.
     */
    public function ai(): View
    {
        return view('admin.ai', [
            'spendMonth' => (int) AiUsage::where('created_at', '>=', now()->startOfMonth())
                ->sum('cost_millicents'),
            'spendAll' => (int) AiUsage::sum('cost_millicents'),
            'calls' => AiUsage::count(),
            'rejected' => AiUsage::where('accepted', false)->count(),
            'byModel' => AiUsage::query()
                ->selectRaw('model, count(*) as calls, sum(cost_millicents) as cost,
                    sum(case when accepted = 0 then 1 else 0 end) as rejected')
                ->groupBy('model')
                ->get(),
            'byPurpose' => AiUsage::query()
                ->selectRaw('purpose, count(*) as calls, sum(cost_millicents) as cost')
                ->groupBy('purpose')
                ->orderByDesc('cost')
                ->get(),
            'recent' => AiUsage::query()
                ->whereNotNull('outcome')
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /** The audit log. Every administrative write, in order. */
    /**
     * The shadow record.
     *
     * Admin only, and the view says on every screen what it is. These
     * rows were never published, never shown to a customer and never
     * placeable - a selection the engine would have taken if the verdict
     * gate had not refused it.
     *
     * The headline figure is closing line value rather than win rate.
     * Whether a shadow pick won is mostly noise at any sample this will
     * reach soon; whether it beat the number the market closed at is the
     * thing that would eventually justify a verdict.
     */
    public function shadow(): View
    {
        $settled = ShadowResult::query()
            ->join('shadow_picks', 'shadow_picks.id', '=', 'shadow_results.shadow_pick_id')
            ->selectRaw('shadow_results.*, shadow_picks.sport as sport');

        $graded = (clone $settled)->whereIn('shadow_results.status', ['won', 'lost']);

        $gradedCount = (clone $graded)->count();
        $clvCount = (clone $settled)->whereNotNull('shadow_results.clv')->count();

        return view('admin.shadow', [
            'recorded' => ShadowPick::count(),
            'pending' => ShadowPick::query()->whereDoesntHave('result')->count(),
            'gradedCount' => $gradedCount,
            // No rate without a sample behind it. An unmeasured figure
            // and a zero look identical on a page and are not.
            'winRate' => $gradedCount > 0
                ? (clone $graded)->where('shadow_results.status', 'won')->count() / $gradedCount
                : null,
            'units' => (clone $settled)->sum('shadow_results.units'),
            'clvCount' => $clvCount,
            'meanClv' => $clvCount > 0 ? (clone $settled)->whereNotNull('shadow_results.clv')->avg('shadow_results.clv') : null,
            /*
             * Its own query, not a clone of $settled: that one selects
             * `shadow_results.*`, and a grouped query carrying every
             * column of the grouped table is a Postgres error. SQLite
             * permits it, so this has to be built deliberately rather
             * than inherited.
             */
            'bySport' => DB::table('shadow_results')
                ->join('shadow_picks', 'shadow_picks.id', '=', 'shadow_results.shadow_pick_id')
                ->selectRaw('shadow_picks.sport as sport, count(*) as n, sum(shadow_results.units) as units, avg(shadow_results.clv) as clv')
                ->groupBy('shadow_picks.sport')
                ->orderByDesc('n')
                ->get(),
            'recent' => ShadowPick::query()->with('result')->orderByDesc('recorded_at')->limit(40)->get(),
        ]);
    }

    public function audit(Request $request): View
    {
        $action = (string) $request->query('action', '');

        return view('admin.audit', [
            'action' => $action,
            'actions' => AuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
            'events' => AuditEvent::query()
                ->with('actor')
                ->when($action !== '', fn ($q) => $q->where('action', $action))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
