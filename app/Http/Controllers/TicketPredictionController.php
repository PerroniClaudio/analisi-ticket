<?php

namespace App\Http\Controllers;

use App\Models\TicketPrediction;
use App\Services\VertexAiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class TicketPredictionController extends Controller {
    private $vertexAiService;

    public function __construct(VertexAiService $vertexAiService) {
        $this->vertexAiService = $vertexAiService;
    }

    /**
     * Predice il tempo di esecuzione per un ticket
     */
    public function predictExecutionTime(Request $request): JsonResponse {
        try {
            // Validazione dei dati di input
            $validator = Validator::make($request->all(), [
                'ticket_id' => 'required|string',
                'subject' => 'required|string',
                'description' => 'nullable|string',
                'software_description' => 'nullable|string',
                'ticket_type' => 'nullable|string',
                'channel' => 'nullable|string',
                'company_name' => 'nullable|string',
                'all_messages_json' => 'nullable|array',
                'all_updates_json' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dati di input non validi',
                    'errors' => $validator->errors()
                ], 400);
            }

            $ticketData = $request->all();

            // Effettua la predizione usando Vertex AI
            $prediction = $this->vertexAiService->predictExecutionTime($ticketData);

            // Salva la predizione nel database
            $ticketPrediction = TicketPrediction::create([
                'ticket_id' => $ticketData['ticket_id'],
                'predicted_minutes' => $prediction['predicted_minutes'],
                'confidence_score' => $prediction['confidence_score'],
                'model_version' => 'vertex-ai-v1',
                'input_features' => json_encode($ticketData),
                'raw_prediction_response' => json_encode($prediction['raw_response']),
                'predicted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'prediction_id' => $ticketPrediction->id,
                    'ticket_id' => $ticketData['ticket_id'],
                    'predicted_minutes' => $prediction['predicted_minutes'],
                    'predicted_hours' => round($prediction['predicted_minutes'] / 60, 2),
                    'confidence_score' => $prediction['confidence_score'],
                    'prediction_quality' => $this->getPredictionQuality($prediction['confidence_score']),
                    'predicted_at' => $ticketPrediction->predicted_at->toISOString(),
                ],
                'message' => 'Predizione completata con successo'
            ]);
        } catch (Exception $e) {
            Log::error('Errore nella predizione del ticket', [
                'ticket_id' => $request->get('ticket_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante la predizione: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Predice usando il modello di testo (fallback)
     */
    public function predictWithTextModel(Request $request): JsonResponse {
        try {
            $validator = Validator::make($request->all(), [
                'ticket_id' => 'required|string',
                'subject' => 'required|string',
                'description' => 'nullable|string',
                'software_description' => 'nullable|string',
                'ticket_type' => 'nullable|string',
                'channel' => 'nullable|string',
                'company_name' => 'nullable|string',
                'all_messages_json' => 'nullable|array',
                'all_updates_json' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dati di input non validi',
                    'errors' => $validator->errors()
                ], 400);
            }

            $ticketData = $request->all();

            // Usa il modello di testo o euristica
            $prediction = $this->vertexAiService->predictWithTextModel($ticketData);

            // Salva la predizione
            $ticketPrediction = TicketPrediction::create([
                'ticket_id' => $ticketData['ticket_id'],
                'predicted_minutes' => $prediction['predicted_minutes'],
                'confidence_score' => $prediction['confidence_score'],
                'model_version' => 'text-model-v1',
                'input_features' => json_encode($ticketData),
                'raw_prediction_response' => json_encode($prediction['raw_response']),
                'predicted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'prediction_id' => $ticketPrediction->id,
                    'ticket_id' => $ticketData['ticket_id'],
                    'predicted_minutes' => $prediction['predicted_minutes'],
                    'predicted_hours' => round($prediction['predicted_minutes'] / 60, 2),
                    'confidence_score' => $prediction['confidence_score'],
                    'prediction_quality' => $this->getPredictionQuality($prediction['confidence_score']),
                    'predicted_at' => $ticketPrediction->predicted_at->toISOString(),
                ],
                'message' => 'Predizione completata con successo (text model)'
            ]);
        } catch (Exception $e) {
            Log::error('Errore nella predizione con text model', [
                'ticket_id' => $request->get('ticket_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante la predizione: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recupera le predizioni per un ticket
     */
    public function getTicketPredictions(string $ticketId): JsonResponse {
        try {
            $predictions = TicketPrediction::where('ticket_id', $ticketId)
                ->orderBy('predicted_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $predictions->map(function ($prediction) {
                    return [
                        'id' => $prediction->id,
                        'predicted_minutes' => $prediction->predicted_minutes,
                        'predicted_hours' => round($prediction->predicted_minutes / 60, 2),
                        'confidence_score' => $prediction->confidence_score,
                        'prediction_quality' => $this->getPredictionQuality($prediction->confidence_score),
                        'model_version' => $prediction->model_version,
                        'predicted_at' => $prediction->predicted_at->toISOString(),
                        'actual_minutes' => $prediction->actual_minutes,
                        'accuracy_score' => $prediction->accuracy_score,
                    ];
                }),
                'count' => $predictions->count()
            ]);
        } catch (Exception $e) {
            Log::error('Errore nel recupero delle predizioni', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero delle predizioni'
            ], 500);
        }
    }

    /**
     * Aggiorna una predizione con il tempo effettivo
     */
    public function updateActualTime(Request $request, int $predictionId): JsonResponse {
        try {
            $validator = Validator::make($request->all(), [
                'actual_minutes' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dati non validi',
                    'errors' => $validator->errors()
                ], 400);
            }

            $prediction = TicketPrediction::findOrFail($predictionId);
            $actualMinutes = $request->get('actual_minutes');

            // Calcola l'accuratezza
            $accuracyScore = $this->calculateAccuracy($prediction->predicted_minutes, $actualMinutes);

            $prediction->update([
                'actual_minutes' => $actualMinutes,
                'accuracy_score' => $accuracyScore,
                'actual_time_updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'prediction_id' => $prediction->id,
                    'predicted_minutes' => $prediction->predicted_minutes,
                    'actual_minutes' => $actualMinutes,
                    'accuracy_score' => $accuracyScore,
                    'accuracy_percentage' => round($accuracyScore * 100, 2) . '%',
                ],
                'message' => 'Tempo effettivo aggiornato con successo'
            ]);
        } catch (Exception $e) {
            Log::error('Errore nell\'aggiornamento del tempo effettivo', [
                'prediction_id' => $predictionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nell\'aggiornamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiche sulle predizioni
     */
    public function getStatistics(): JsonResponse {
        try {
            $totalPredictions = TicketPrediction::count();
            $predictionsWithActual = TicketPrediction::whereNotNull('actual_minutes')->count();

            $avgAccuracy = TicketPrediction::whereNotNull('accuracy_score')
                ->avg('accuracy_score');

            $avgPredictedTime = TicketPrediction::avg('predicted_minutes');
            $avgActualTime = TicketPrediction::whereNotNull('actual_minutes')
                ->avg('actual_minutes');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_predictions' => $totalPredictions,
                    'predictions_with_actual_time' => $predictionsWithActual,
                    'completion_rate' => $totalPredictions > 0 ?
                        round(($predictionsWithActual / $totalPredictions) * 100, 2) : 0,
                    'average_accuracy' => $avgAccuracy ? round($avgAccuracy, 3) : null,
                    'average_accuracy_percentage' => $avgAccuracy ?
                        round($avgAccuracy * 100, 2) . '%' : null,
                    'average_predicted_minutes' => round($avgPredictedTime ?? 0, 1),
                    'average_actual_minutes' => round($avgActualTime ?? 0, 1),
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Errore nel calcolo delle statistiche', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nel calcolo delle statistiche'
            ], 500);
        }
    }

    /**
     * Determina la qualità della predizione basata sul confidence score
     */
    private function getPredictionQuality(?float $confidenceScore): string {
        if ($confidenceScore === null) {
            return 'unknown';
        }

        if ($confidenceScore >= 0.8) {
            return 'high';
        } elseif ($confidenceScore >= 0.6) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Calcola l'accuratezza della predizione
     */
    private function calculateAccuracy(float $predicted, float $actual): float {
        if ($actual == 0) {
            return $predicted == 0 ? 1.0 : 0.0;
        }

        // Calcola l'errore relativo
        $relativeError = abs($predicted - $actual) / $actual;

        // Converti in accuracy (1 - errore relativo, con limite minimo di 0)
        return max(0, 1 - $relativeError);
    }
}
