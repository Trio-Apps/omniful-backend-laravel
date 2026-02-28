<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SapAccountCategory;
use App\Models\SapBank;
use App\Models\SapBankAccount;
use App\Models\SapBankingDocument;
use App\Models\SapBranch;
use App\Models\SapChartOfAccount;
use App\Models\SapCurrency;
use App\Models\SapCustomerFinance;
use App\Models\SapExchangeRate;
use App\Models\SapFinanceDocument;
use App\Models\SapFinancialPeriod;
use App\Models\SapInventoryDocument;
use App\Models\SapItemGroup;
use App\Models\SapPaymentTerm;
use App\Models\SapProfitCenter;
use App\Models\SapSalesDocument;
use App\Models\SapSyncEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class IntegrationReadApiController extends Controller
{
    public function resources(Request $request): JsonResponse
    {
        if ($response = $this->authorizeRequest($request)) {
            return $response;
        }

        $resources = collect($this->resourceMap())
            ->map(fn (array $config, string $key) => [
                'resource' => $key,
                'label' => $config['label'],
                'mode' => 'read_only',
                'searchable_fields' => $config['search'],
                'default_sort' => 'id desc',
            ])
            ->values();

        return response()->json([
            'status' => 'ok',
            'data' => $resources,
        ]);
    }

    public function resourceIndex(Request $request, string $resource): JsonResponse
    {
        if ($response = $this->authorizeRequest($request)) {
            return $response;
        }

        $config = $this->resourceMap()[$resource] ?? null;
        if ($config === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unknown resource',
            ], 404);
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $model = new $modelClass();
        $table = $model->getTable();
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));
        $includePayload = filter_var((string) $request->query('include_payload', 'false'), FILTER_VALIDATE_BOOLEAN);

        $query = $modelClass::query();
        $this->applySearch($query, $request, $config['search']);
        $this->applyOptionalFilter($query, $request, $table, 'status');
        $this->applyOptionalFilter($query, $request, $table, 'document_type');
        $this->applyOptionalFilter($query, $request, $table, 'doc_entry');
        $this->applyOptionalFilter($query, $request, $table, 'doc_num');

        if (Schema::hasColumn($table, 'doc_date')) {
            $from = trim((string) $request->query('from_date', ''));
            $to = trim((string) $request->query('to_date', ''));
            if ($from !== '') {
                $query->whereDate($table . '.doc_date', '>=', $from);
            }
            if ($to !== '') {
                $query->whereDate($table . '.doc_date', '<=', $to);
            }
        }

        $paginator = $query
            ->orderByDesc($table . '.id')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Model $record) => $this->transformRecord($record, $includePayload)
            )
        );

        return response()->json([
            'status' => 'ok',
            'resource' => $resource,
            'label' => $config['label'],
            'mode' => 'read_only',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        if ($response = $this->authorizeRequest($request)) {
            return $response;
        }

        $recent = SapSyncEvent::query()
            ->where('source_type', 'sap_catalog')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (SapSyncEvent $event) => [
                'id' => $event->id,
                'event_key' => $event->event_key,
                'sap_action' => $event->sap_action,
                'sap_status' => $event->sap_status,
                'sap_doc_entry' => $event->sap_doc_entry,
                'sap_doc_num' => $event->sap_doc_num,
                'sap_error' => $event->sap_error,
                'created_at' => optional($event->created_at)?->toDateTimeString(),
                'updated_at' => optional($event->updated_at)?->toDateTimeString(),
                'payload' => $event->payload,
            ])
            ->values();

        $active = $recent->first(fn (array $event) => in_array($event['sap_status'], ['queued', 'running'], true));

        return response()->json([
            'status' => 'ok',
            'mode' => 'read_only',
            'active' => $active,
            'recent' => $recent,
        ]);
    }

    /**
     * @return array<string,array{label:string,model:class-string<Model>,search:array<int,string>}>
     */
    private function resourceMap(): array
    {
        return [
            'chart-of-accounts' => [
                'label' => 'Chart Of Accounts',
                'model' => SapChartOfAccount::class,
                'search' => ['code', 'name'],
            ],
            'account-categories' => [
                'label' => 'Account Categories',
                'model' => SapAccountCategory::class,
                'search' => ['code', 'name'],
            ],
            'financial-periods' => [
                'label' => 'Financial Periods',
                'model' => SapFinancialPeriod::class,
                'search' => ['period_code', 'period_name'],
            ],
            'banks' => [
                'label' => 'Banks',
                'model' => SapBank::class,
                'search' => ['bank_code', 'bank_name'],
            ],
            'bank-accounts' => [
                'label' => 'Bank Accounts',
                'model' => SapBankAccount::class,
                'search' => ['account_code', 'account_name', 'bank_code'],
            ],
            'payment-terms' => [
                'label' => 'Payment Terms',
                'model' => SapPaymentTerm::class,
                'search' => ['group_num', 'payment_terms_group_name'],
            ],
            'currencies' => [
                'label' => 'Currencies',
                'model' => SapCurrency::class,
                'search' => ['currency_code', 'currency_name'],
            ],
            'exchange-rates' => [
                'label' => 'Exchange Rates',
                'model' => SapExchangeRate::class,
                'search' => ['currency_code', 'rate_date'],
            ],
            'profit-centers' => [
                'label' => 'Profit Centers',
                'model' => SapProfitCenter::class,
                'search' => ['center_code', 'center_name'],
            ],
            'branches' => [
                'label' => 'Branches',
                'model' => SapBranch::class,
                'search' => ['branch_id', 'branch_name'],
            ],
            'customer-finance' => [
                'label' => 'Customer Finance',
                'model' => SapCustomerFinance::class,
                'search' => ['card_code', 'card_name'],
            ],
            'finance-documents' => [
                'label' => 'Finance Documents',
                'model' => SapFinanceDocument::class,
                'search' => ['document_type', 'doc_entry', 'doc_num', 'card_code'],
            ],
            'sales-documents' => [
                'label' => 'Sales Documents',
                'model' => SapSalesDocument::class,
                'search' => ['document_type', 'doc_entry', 'doc_num', 'card_code'],
            ],
            'item-groups' => [
                'label' => 'Item Groups',
                'model' => SapItemGroup::class,
                'search' => ['group_code', 'group_name'],
            ],
            'inventory-documents' => [
                'label' => 'Inventory Documents',
                'model' => SapInventoryDocument::class,
                'search' => ['document_type', 'doc_entry', 'doc_num', 'reference_code'],
            ],
            'banking-documents' => [
                'label' => 'Banking Documents',
                'model' => SapBankingDocument::class,
                'search' => ['document_type', 'doc_entry', 'doc_num', 'reference_code'],
            ],
        ];
    }

    /**
     * @param array<int,string> $columns
     */
    private function applySearch(Builder $query, Request $request, array $columns): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '' || $columns === []) {
            return;
        }

        $table = $query->getModel()->getTable();

        $query->where(function (Builder $innerQuery) use ($columns, $search, $table): void {
            $hasCondition = false;

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }

                $method = $hasCondition ? 'orWhere' : 'where';
                $innerQuery->{$method}($table . '.' . $column, 'like', '%' . $search . '%');
                $hasCondition = true;
            }
        });
    }

    private function applyOptionalFilter(Builder $query, Request $request, string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $value = $request->query($column);
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $query->where($table . '.' . $column, (string) $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function transformRecord(Model $record, bool $includePayload): array
    {
        $data = $record->toArray();

        if (!$includePayload) {
            unset($data['payload']);
        }

        return $data;
    }

    private function authorizeRequest(Request $request): ?JsonResponse
    {
        $configuredToken = trim((string) config('services.integration.read_api_token', ''));
        if ($configuredToken === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Integration read API token is not configured',
            ], 503);
        }

        $providedToken = $this->extractToken($request);
        if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        return null;
    }

    private function extractToken(Request $request): string
    {
        $headerToken = trim((string) $request->headers->get('X-Integration-Token'));
        if ($headerToken !== '') {
            return $headerToken;
        }

        $authorization = trim((string) $request->headers->get('Authorization'));
        if (str_starts_with(strtolower($authorization), 'bearer ')) {
            return trim(substr($authorization, 7));
        }

        return '';
    }
}
