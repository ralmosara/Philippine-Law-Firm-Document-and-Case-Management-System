<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Correspondence\InboundEmails;
use App\Domain\Correspondence\Models\MatterEmail;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** A matter's correspondence: its email address, the emails filed to it, and those awaiting review. */
class MatterEmailController extends Controller
{
    public function __construct(private readonly InboundEmails $emails) {}

    public function index(Matter $matter): JsonResponse
    {
        return response()->json([
            'enabled' => InboundEmails::enabled(),
            'address' => $this->emails->addressFor($matter),
            'emails' => MatterEmail::where('matter_id', $matter->id)
                ->with(['senderUser:id,name', 'reviewer:id,name'])
                ->latest('id')->limit(300)->get()
                ->map(fn (MatterEmail $e) => $this->present($e)),
        ]);
    }

    public function show(MatterEmail $matterEmail): JsonResponse
    {
        return response()->json([...$this->present($matterEmail->load(['senderUser:id,name', 'reviewer:id,name'])), 'body_text' => $matterEmail->body_text]);
    }

    /** File an email saved from Outlook or Gmail as an .eml file. */
    public function upload(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $request->validate(['file' => ['required', 'file', 'extensions:eml', 'max:'.config('services.inbound_email.max_kilobytes')]]);

        $email = $this->emails->upload($matter, (string) file_get_contents($request->file('file')->getRealPath()), $request->user());
        abort_unless($email, 422, 'This email is already filed with the matter.');

        return response()->json($this->present($email->refresh()), 201);
    }

    public function accept(Request $request, MatterEmail $matterEmail): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_unless($matterEmail->status === MatterEmail::REVIEW, 422, 'This email is not awaiting review.');
        $this->emails->accept($matterEmail, $request->user());

        return response()->json($this->present($matterEmail->refresh()));
    }

    public function reject(Request $request, MatterEmail $matterEmail): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_unless($matterEmail->status === MatterEmail::REVIEW, 422, 'This email is not awaiting review.');
        $this->emails->reject($matterEmail, $request->user());

        return response()->json($this->present($matterEmail->refresh()));
    }

    /** A new address when the old one has leaked; the old one stops working. */
    public function rotate(Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_unless(InboundEmails::enabled(), 422, 'Email to matter is not set up.');

        return response()->json(['address' => $this->emails->rotate($matter)]);
    }

    private function present(MatterEmail $e): array
    {
        return [
            'id' => $e->id,
            'status' => $e->status,
            'source' => $e->source,
            'from_email' => $e->from_email,
            'from_name' => $e->from_name,
            'to' => $e->to,
            'cc' => $e->cc,
            'subject' => $e->subject,
            'sent_at' => $e->sent_at?->toIso8601String(),
            'received_at' => $e->created_at?->toIso8601String(),
            'preview' => $e->body_text ? mb_substr(preg_replace('/\s+/', ' ', $e->body_text), 0, 160) : null,
            'attachments' => $e->attachments ?? [],
            'eml_file_id' => $e->eml_file_id,
            'filed_by' => $e->senderUser?->name,
            'reviewed_by' => $e->reviewer?->name,
        ];
    }
}
