<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Workflows\BalanceInquiryWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Turnover;
use Illuminate\Http\JsonResponse;
use Workflow\WorkflowStub;

class BalanceController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/accounts/{uuid}/balance",
     *     operationId="getAccountBalance",
     *     tags={"Balance"},
     *     summary="Get account balance",
     *     description="Retrieves the current balance and turnover information for an account",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         description="Account UUID",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Balance information retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Balance")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Account not found",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     */
    public function show(string $uuid): JsonResponse
    {
        $account = Account::where('uuid', $uuid)->firstOrFail();
        
        $accountUuid = new AccountUuid($uuid);
        
        // For now, just use the account balance directly
        // The workflow would typically be used in a more complex scenario
        $balance = $account->balance;

        $turnover = Turnover::where('account_uuid', $uuid)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'data' => [
                'account_uuid' => $uuid,
                'balance' => $balance,
                'frozen' => $account->frozen ?? false,
                'last_updated' => $account->updated_at,
                'turnover' => $turnover ? [
                    'debit' => $turnover->debit,
                    'credit' => $turnover->credit,
                    'period_start' => $turnover->created_at,
                    'period_end' => $turnover->updated_at,
                ] : null,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/accounts/{uuid}/balance/summary",
     *     operationId="getAccountBalanceSummary",
     *     tags={"Balance"},
     *     summary="Get account balance summary",
     *     description="Retrieves detailed balance statistics including 12-month turnover data",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         description="Account UUID",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Balance summary retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="account_uuid", type="string", format="uuid"),
     *                 @OA\Property(property="current_balance", type="integer", example=50000),
     *                 @OA\Property(property="frozen", type="boolean", example=false),
     *                 @OA\Property(property="statistics", type="object",
     *                     @OA\Property(property="total_debit_12_months", type="integer", example=120000),
     *                     @OA\Property(property="total_credit_12_months", type="integer", example=170000),
     *                     @OA\Property(property="average_monthly_debit", type="number", example=10000),
     *                     @OA\Property(property="average_monthly_credit", type="number", example=14166.67),
     *                     @OA\Property(property="months_analyzed", type="integer", example=12)
     *                 ),
     *                 @OA\Property(property="monthly_turnovers", type="array",
     *                     @OA\Items(type="object",
     *                         @OA\Property(property="month", type="integer", example=1),
     *                         @OA\Property(property="year", type="integer", example=2024),
     *                         @OA\Property(property="debit", type="integer", example=10000),
     *                         @OA\Property(property="credit", type="integer", example=15000)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Account not found",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     */
    public function summary(string $uuid): JsonResponse
    {
        $account = Account::where('uuid', $uuid)->firstOrFail();
        
        $turnovers = Turnover::where('account_uuid', $uuid)
            ->orderBy('created_at', 'desc')
            ->take(12)
            ->get();

        $totalDebit = $turnovers->sum('debit');
        $totalCredit = $turnovers->sum('credit');
        $averageMonthlyDebit = $turnovers->count() > 0 ? $totalDebit / $turnovers->count() : 0;
        $averageMonthlyCredit = $turnovers->count() > 0 ? $totalCredit / $turnovers->count() : 0;

        return response()->json([
            'data' => [
                'account_uuid' => $uuid,
                'current_balance' => $account->balance,
                'frozen' => $account->frozen ?? false,
                'statistics' => [
                    'total_debit_12_months' => $totalDebit,
                    'total_credit_12_months' => $totalCredit,
                    'average_monthly_debit' => (int) $averageMonthlyDebit,
                    'average_monthly_credit' => (int) $averageMonthlyCredit,
                    'months_analyzed' => $turnovers->count(),
                ],
                'monthly_turnovers' => $turnovers->map(function ($turnover) {
                    return [
                        'month' => $turnover->created_at->format('Y-m'),
                        'debit' => $turnover->debit,
                        'credit' => $turnover->credit,
                        'net' => $turnover->credit - $turnover->debit,
                    ];
                }),
            ],
        ]);
    }
}