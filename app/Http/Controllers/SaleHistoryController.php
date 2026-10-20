<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Services\ReceiptPrintService;
use App\Services\SaleHistoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Find a past sale, see what was in it, reprint it. Read-only: printing goes
 * through ReceiptController (`?copy=1`), which is also where reprints are audited.
 * Both routes sit outside the shift gate so earlier sales stay reachable after the
 * shift is closed.
 */
class SaleHistoryController extends Controller
{
    public function __construct(
        private readonly SaleHistoryService $history,
        private readonly ReceiptPrintService $prints,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $f = $this->history->filters($request->validate([
            'q' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'user_id' => 'nullable|integer',
            'status' => ['nullable', Rule::in(Sale::STATUSES)],
            'payment_method' => ['nullable', Rule::in(Sale::METHODS)],
        ]));

        return view('sales.index', [
            'f' => $f,
            'sales' => $this->history->paginate($f, $user)->withQueryString(),
            'summary' => $this->history->summary($f, $user),
            // Only managerial roles can see anyone else's sales, so only they get the cashier filter.
            'cashiers' => $user->isManagerial() ? User::orderBy('name')->get(['id', 'name']) : collect(),
        ] + $this->printing());
    }

    public function show(Request $request, Sale $sale): View
    {
        // A sale this user may not open looks exactly like one that does not exist.
        abort_unless($sale->canBeSeenBy($request->user()), 404);

        $this->history->detail($sale);

        return view('sales.show', [
            'sale' => $sale,
            // VAT rates and the item/cart discount split, read from the stored lines (no recalculation).
            'inv' => $this->prints->invoice($sale),
            'canReturn' => $this->history->canReturn($sale),
        ] + $this->printing());
    }

    /** What the reprint buttons need: format order, whether to ask, and whether A4 can go straight to a printer. */
    private function printing(): array
    {
        return $this->history->reprintFormats() + ['a4Silent' => $this->prints->silentA4Available()];
    }
}
