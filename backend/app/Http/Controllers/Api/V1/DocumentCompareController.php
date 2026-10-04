<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Compare\DocumentComparison;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Redlines between document versions, or a version and the other side's draft. */
class DocumentCompareController extends Controller
{
    public function __construct(private readonly DocumentComparison $comparison) {}

    public function sources(Document $document): JsonResponse
    {
        return response()->json($this->comparison->sources($document));
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        [$base, $other] = $this->sides($request);

        return response()->json($this->comparison->compare($document, $base, $other));
    }

    /** The redline as a PDF, to send to the other side or keep on file. */
    public function pdf(Request $request, Document $document, PdfRenderer $pdf): Response
    {
        [$base, $other] = $this->sides($request);
        $result = $this->comparison->compare($document, $base, $other);
        $document->loadMissing('matter');

        return $pdf->download('pdf.redline', [
            'document' => $document,
            'firm' => Firm::findOrFail($document->firm_id),
            'result' => $result,
            'by' => $request->user()->name,
        ], "{$document->title} redline");
    }

    /** @return array{0: string, 1: string} */
    private function sides(Request $request): array
    {
        $validated = $request->validate([
            'base' => ['required', 'string', 'max:20'],
            'other' => ['required', 'string', 'max:20', 'different:base'],
        ], ['other.different' => 'Choose two different versions or files.']);

        return [$validated['base'], $validated['other']];
    }
}
