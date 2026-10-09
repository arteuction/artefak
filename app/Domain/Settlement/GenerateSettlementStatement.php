<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Renders a PDF settlement statement from canonical ledger data.
 *
 * Figures are read directly from the settlements and settlement_lines tables —
 * this action never recalculates splits; it only formats what was recorded.
 */
final class GenerateSettlementStatement
{
    public function execute(int $settlementId): Response
    {
        $settlement = DB::table('settlements')->where('id', $settlementId)->first();

        if ($settlement === null) {
            throw new \InvalidArgumentException("Settlement {$settlementId} not found.");
        }

        $lines = collect(
            DB::table('settlement_lines')
              ->where('settlement_id', $settlementId)
              ->orderBy('id')
              ->get()
        );

        $pdf = Pdf::loadView('pdf.settlement_statement', [
            'settlement' => $settlement,
            'lines'      => $lines,
        ])->setPaper('a4', 'portrait');

        $filename = "settlement-{$settlement->stripe_payment_intent_id}.pdf";

        return $pdf->download($filename);
    }

    public function stream(int $settlementId): Response
    {
        $settlement = DB::table('settlements')->where('id', $settlementId)->first();

        if ($settlement === null) {
            throw new \InvalidArgumentException("Settlement {$settlementId} not found.");
        }

        $lines = collect(
            DB::table('settlement_lines')
              ->where('settlement_id', $settlementId)
              ->orderBy('id')
              ->get()
        );

        $pdf = Pdf::loadView('pdf.settlement_statement', [
            'settlement' => $settlement,
            'lines'      => $lines,
        ])->setPaper('a4', 'portrait');

        $filename = "settlement-{$settlement->stripe_payment_intent_id}.pdf";

        return $pdf->stream($filename);
    }
}
