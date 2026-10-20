<?php

namespace App\Services;

use App\Http\Controllers\ReturnController;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Support\License;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of `sales` for the Sales History screens. Nothing here prices, totals
 * or taxes anything: it filters and presents what the sale already stored, and
 * reprints go through the existing receipt renderers (see ReceiptController).
 *
 * Every query starts from Sale::visibleTo($user), so the ownership rule (a cashier
 * sees only their own sales) is part of the query, not a hidden column.
 */
class SaleHistoryService
{
    public const PER_PAGE = 25;

    public function __construct(private readonly ReceiptPrintService $prints) {}

    /**
     * Normalise raw query input into the filter shape every method here takes.
     * Day boundaries are built the way the reports and Stock Movements build them
     * (Carbon in the app timezone, startOfDay / endOfDay).
     *
     * With no dates and no search the screen shows today only, which keeps opening it
     * one cheap range scan. A text search with no dates looks through every date:
     * "find any past receipt" must not need the date pickers moved first.
     *
     * @return array{q:string,from:?Carbon,to:?Carbon,defaulted:bool,user_id:?int,status:?string,payment_method:?string}
     */
    public function filters(array $input): array
    {
        $q = trim((string) ($input['q'] ?? ''));
        $from = ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : null;
        $to = ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : null;

        $defaulted = $q === '' && ! $from && ! $to;
        if ($defaulted) {
            [$from, $to] = [now()->startOfDay(), now()->endOfDay()];
        }

        return [
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'defaulted' => $defaulted,
            'user_id' => ! empty($input['user_id']) ? (int) $input['user_id'] : null,
            'status' => in_array($input['status'] ?? null, Sale::STATUSES, true) ? $input['status'] : null,
            'payment_method' => in_array($input['payment_method'] ?? null, Sale::METHODS, true) ? $input['payment_method'] : null,
        ];
    }

    public function query(array $f, User $user): Builder
    {
        $q = Sale::query()->visibleTo($user);

        if ($f['from']) {
            $q->where('sales.created_at', '>=', $f['from']);
        }
        if ($f['to']) {
            $q->where('sales.created_at', '<=', $f['to']);
        }
        if ($f['status']) {
            $q->where('sales.status', $f['status']);
        }
        if ($f['payment_method']) {
            $q->where('sales.payment_method', $f['payment_method']);
        }
        // A cashier's own filter is a no-op next to visibleTo(): another id matches nothing.
        if ($f['user_id']) {
            $q->where('sales.user_id', $f['user_id']);
        }

        // Receipt number (exact or prefix) or the customer's name / phone. withTrashed:
        // a deleted customer's sales stay findable.
        if ($f['q'] !== '') {
            $s = $f['q'];
            $q->where(fn ($w) => $w->where('sales.receipt_number', 'like', "{$s}%")
                ->orWhereIn('sales.customer_id', Customer::withTrashed()->select('id')
                    ->where(fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))));
        }

        return $q;
    }

    /**
     * Count and money total of the filtered set, in one aggregate query. Voided and
     * held sales are listed but are not takings, so they stay out of the total.
     *
     * @return array{count:int,total:float}
     */
    public function summary(array $f, User $user): array
    {
        $row = $this->query($f, $user)->toBase()->selectRaw(
            'COUNT(*) AS n, COALESCE(SUM(CASE WHEN sales.status IN (?, ?) THEN 0 ELSE sales.total_usd END), 0) AS total',
            [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD],
        )->first();

        return ['count' => (int) $row->n, 'total' => round((float) $row->total, 2)];
    }

    public function paginate(array $f, User $user): LengthAwarePaginator
    {
        return $this->query($f, $user)
            ->select('sales.id', 'sales.receipt_number', 'sales.customer_id', 'sales.user_id', 'sales.status',
                'sales.payment_method', 'sales.total_usd', 'sales.total_lbp', 'sales.created_at')
            ->with(['customer' => fn ($c) => $c->withTrashed()->select('id', 'name'), 'user:id,name'])
            ->orderByDesc('sales.created_at')->orderByDesc('sales.id')
            ->paginate(self::PER_PAGE);
    }

    /** Everything the detail page shows, loaded once. Deleted products and customers stay readable. */
    public function detail(Sale $sale): Sale
    {
        return $sale->load([
            'items.product' => fn ($p) => $p->withTrashed()->select('id', 'name', 'name_ar', 'sku', 'unit'),
            'customer' => fn ($c) => $c->withTrashed(),
            'user:id,name',
            'shift:id,opened_at,closed_at',
            'returns:id,sale_id,return_number,status,refund_amount_usd,created_at',
        ]);
    }

    /**
     * Receipt formats in the order the reprint buttons appear: the store's default
     * first. `ask` has no default (the sell screen asks every time), so there both
     * buttons carry equal weight.
     *
     * @return array{formats:list<string>,ask:bool}
     */
    public function reprintFormats(): array
    {
        $first = $this->prints->defaultFormat();
        $other = $first === ReceiptPrintService::FORMAT_A4 ? ReceiptPrintService::FORMAT_THERMAL : ReceiptPrintService::FORMAT_A4;

        return [
            'formats' => [$first, $other],
            'ask' => $this->prints->formatSetting() === ReceiptPrintService::FORMAT_ASK,
        ];
    }

    /** Same refusal the Returns screens apply, plus the Returns feature being on for this tier. */
    public function canReturn(Sale $sale): bool
    {
        return License::allows('returns') && ! in_array($sale->status, ReturnController::NOT_RETURNABLE, true);
    }
}
