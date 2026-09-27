<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Requests\DocumentRequestItem;
use App\Domain\Documents\Requests\DocumentRequests;
use App\Http\Controllers\Api\V1\DocumentRequestController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A client's side of document requests: what the firm needs, and uploading it. */
class PortalDocumentRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(DocumentRequest::where('client_id', $request->user('client')->id)
            ->where('status', '!=', DocumentRequest::CANCELLED)
            ->with(['items.file', 'matter:id,title'])
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('id')
            ->get()
            ->map(fn (DocumentRequest $r) => [...DocumentRequestController::present($r, forClient: true), 'matter' => $r->matter?->title]));
    }

    public function show(Request $request, int $documentRequest): JsonResponse
    {
        $r = DocumentRequest::where('client_id', $request->user('client')->id)->with(['items.file', 'matter:id,title'])->findOrFail($documentRequest);

        return response()->json([...DocumentRequestController::present($r, forClient: true), 'matter' => $r->matter?->title]);
    }

    public function upload(Request $request, int $item, DocumentRequests $requests): JsonResponse
    {
        $request->validate(['file' => ['required', StoreMatterFile::rule()]]);
        $client = $request->user('client');
        $model = DocumentRequestItem::whereHas('request', fn ($q) => $q->where('client_id', $client->id))->findOrFail($item);

        $requests->upload($model, $request->file('file'), $client);

        $r = DocumentRequest::with(['items.file', 'matter:id,title'])->findOrFail($model->document_request_id);

        return response()->json([...DocumentRequestController::present($r, forClient: true), 'matter' => $r->matter?->title]);
    }
}
