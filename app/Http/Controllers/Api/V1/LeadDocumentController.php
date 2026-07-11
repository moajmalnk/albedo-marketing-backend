<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LeadDocumentController extends Controller
{
    public function index(Lead $lead)
    {
        // Add authorization check if necessary (e.g., policy or role check)
        $documents = $lead->documents()->with('uploader:id,first_name,last_name,email')->get();
        return response()->json($documents);
    }

    public function store(Request $request, Lead $lead)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'document_type' => 'nullable|string|max:100',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $path = $file->store('lead_documents/' . $lead->id, 'local');

        $document = $lead->documents()->create([
            'uploaded_by' => auth()->id(),
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'document_type' => $request->document_type,
        ]);

        return response()->json($document->load('uploader:id,first_name,last_name,email'), 201);
    }

    public function destroy(Lead $lead, LeadDocument $document)
    {
        if ($document->lead_id !== $lead->id) {
            abort(404);
        }

        // Only allow uploader or super_admin/admin to delete
        $user = auth()->user();
        if ($user->id !== $document->uploaded_by && !in_array($user->role, ['super_admin', 'admin', 'department_head'])) {
            abort(403, 'Unauthorized to delete this document');
        }

        // Soft delete the document record
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    public function download(Lead $lead, LeadDocument $document)
    {
        if ($document->lead_id !== $lead->id) {
            abort(404);
        }

        if (!Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File not found on server');
        }

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }
}

