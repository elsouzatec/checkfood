<?php

namespace App\Http\Controllers;

use App\Models\AppSession;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * POST /api/feedbacks
     * Cliente envia avaliação após encerramento da sessão.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => 'required|exists:sessions_app,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        // Verificar se a sessão pertence ao usuário
        $session = AppSession::find($data['session_id']);

        if (!$session || $session->user_id !== $user->id) {
            return response()->json(['message' => 'Sessão não encontrada.'], 404);
        }

        // Verificar se já enviou feedback para esta sessão
        if (Feedback::where('session_id', $session->id)->exists()) {
            return response()->json(['message' => 'Feedback já enviado para esta sessão.'], 422);
        }

        $feedback = Feedback::create([
            'restaurant_id' => $session->restaurant_id,
            'session_id' => $session->id,
            'user_id' => $user->id,
            'table_id' => $session->table_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json([
            'message' => 'Obrigado pela sua avaliação!',
            'feedback' => $feedback,
        ], 201);
    }

    /**
     * GET /api/feedbacks/check/{sessionId}
     * Verifica se já existe feedback para a sessão.
     */
    public function check(int $sessionId, Request $request): JsonResponse
    {
        $exists = Feedback::where('session_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->exists();

        return response()->json(['has_feedback' => $exists]);
    }
}
