<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiCommandService;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function command(Request $request, AiCommandService $service)
    {
        $data = $request->validate([
            'text'            => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer',
        ]);

        return response()->json($service->handle(trim($data['text']), $data['conversation_id'] ?? null));
    }
}
