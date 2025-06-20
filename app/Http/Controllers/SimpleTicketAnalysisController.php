<?php

namespace App\Http\Controllers;

use App\Services\SimpleVertexAiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Jobs\AnalyzeTicketsJob;
use App\Models\TicketPrediction;

class SimpleTicketAnalysisController extends Controller {
    private $vertexAiService;

    public function __construct(SimpleVertexAiService $vertexAiService) {
        $this->vertexAiService = $vertexAiService;
    }

    /**
     * Analizza tutti i ticket dal bucket e restituisce le stime
     */
    public function analyzeTicketsFromBucket(Request $request): JsonResponse {
        try {
            $bucketName = $request->get('bucket_name', config('services.vertex_ai.bucket_name'));
            $filePath = $request->get('file_path', config('services.vertex_ai.dataset_path'));
            $modelName = $request->get('model', 'gemini-2.0-flash-lite-001');

            Log::info('Inizio analisi ticket dal bucket', [
                'bucket' => $bucketName,
                'file' => $filePath,
                'model' => $modelName
            ]);

            $results = $this->vertexAiService->analyzeTicketsFromBucket($bucketName, $filePath, $modelName);

            // Calcola statistiche
            $successful = array_filter($results, fn($r) => $r['status'] === 'success');
            $failed = array_filter($results, fn($r) => $r['status'] === 'error');

            $avgMinutes = count($successful) > 0 ?
                array_sum(array_column($successful, 'estimated_minutes')) / count($successful) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'results' => $results,
                    'statistics' => [
                        'total_tickets' => count($results),
                        'successful_analyses' => count($successful),
                        'failed_analyses' => count($failed),
                        'success_rate' => count($results) > 0 ?
                            round((count($successful) / count($results)) * 100, 2) : 0,
                        'average_estimated_minutes' => round($avgMinutes, 1),
                        'average_estimated_hours' => round($avgMinutes / 60, 2)
                    ]
                ],
                'metadata' => [
                    'bucket_name' => $bucketName,
                    'file_path' => $filePath,
                    'model_used' => $modelName,
                    'analyzed_at' => now()->toISOString()
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Errore nell\'analisi dei ticket dal bucket', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nell\'analisi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analizza un singolo ticket
     */
    public function analyzeSingleTicket(Request $request): JsonResponse {
        try {
            $ticketData = $request->all();
            $modelName = $request->get('model', 'gemini-2.0-flash-lite-001');

            // Crea un array temporaneo con il ticket
            $tempFile = tempnam(sys_get_temp_dir(), 'ticket_');
            file_put_contents($tempFile, json_encode($ticketData));

            // Per ora usiamo il metodo di analisi (da migliorare)
            // In alternativa, possiamo creare un metodo dedicato

            return response()->json([
                'success' => false,
                'message' => 'Funzione in sviluppo - usa analyzeTicketsFromBucket per ora'
            ], 501);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lista i modelli disponibili
     */
    public function getAvailableModels(): JsonResponse {
        return response()->json([
            'success' => true,
            'models' => $this->vertexAiService->getAvailableModels()
        ]);
    }

    /**
     * Esporta i risultati in formato CSV
     */
    public function exportResults(Request $request): JsonResponse {
        try {
            $results = $request->get('results', []);

            if (empty($results)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nessun risultato da esportare'
                ], 400);
            }

            $csv = "Ticket ID,Estimated Minutes,Estimated Hours,Status,Error\n";

            foreach ($results as $result) {
                $hours = $result['estimated_minutes'] ? round($result['estimated_minutes'] / 60, 2) : '';
                $error = $result['error'] ?? '';

                $csv .= sprintf(
                    "%s,%s,%s,%s,\"%s\"\n",
                    $result['ticket_id'],
                    $result['estimated_minutes'] ?? '',
                    $hours,
                    $result['status'],
                    str_replace('"', '""', $error)
                );
            }

            return response()->json([
                'success' => true,
                'csv_data' => $csv,
                'filename' => 'ticket_estimates_' . date('Y-m-d_H-i-s') . '.csv'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore nell\'esportazione: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analizza i ticket in streaming (Server-Sent Events)
     */
    public function analyzeTicketsStream(Request $request) {

        $bucketName = $request->get('bucket_name', config('services.vertex_ai.bucket_name'));
        $filePath = $request->get('file_path', config('services.vertex_ai.dataset_path'));
        $modelName = $request->get('model', 'gemini-2.0-flash-lite-001');

        return response()->stream(function () use ($bucketName, $filePath, $modelName) {
            // Imposta headers per SSE
            echo "data: " . json_encode(['type' => 'init', 'message' => 'Inizializzazione...']) . "\n\n";
            flush();

            try {
                // Carica i ticket dal bucket
                echo "data: " . json_encode(['type' => 'progress', 'message' => 'Caricamento ticket dal bucket...']) . "\n\n";
                flush();

                $tickets = $this->vertexAiService->loadTicketsFromBucketPublic($bucketName, $filePath);
                $totalTickets = count($tickets);

                echo "data: " . json_encode([
                    'type' => 'tickets_loaded',
                    'total' => $totalTickets,
                    'message' => "Caricati {$totalTickets} ticket. Inizio analisi..."
                ]) . "\n\n";
                flush();

                $results = [];
                $processed = 0;
                $successful = 0;
                $failed = 0;

                foreach ($tickets as $index => $ticket) {
                    // Verifica se la connessione è ancora attiva
                    if (connection_aborted()) {
                        Log::info('Connessione client interrotta durante lo streaming');
                        break;
                    }

                    $ticketId = $ticket['tid'] ?? $ticket['ticket_id'] ?? "TICKET-{$index}";

                    echo "data: " . json_encode([
                        'type' => 'processing',
                        'ticket_id' => $ticketId,
                        'current' => $processed + 1,
                        'total' => $totalTickets,
                        'message' => "Analizzando ticket {$ticketId}..."
                    ]) . "\n\n";
                    flush();

                    try {
                        $estimatedMinutes = $this->vertexAiService->estimateTicketTimePublic($ticket, $modelName);

                        $result = [
                            'ticket_id' => $ticketId,
                            'estimated_minutes' => $estimatedMinutes,
                            'status' => 'success'
                        ];

                        $results[] = $result;
                        $successful++;

                        // Invia il risultato in tempo reale
                        echo "data: " . json_encode([
                            'type' => 'result',
                            'result' => $result,
                            'statistics' => [
                                'processed' => $processed + 1,
                                'successful' => $successful,
                                'failed' => $failed,
                                'total' => $totalTickets
                            ]
                        ]) . "\n\n";
                    } catch (Exception $e) {
                        $result = [
                            'ticket_id' => $ticketId,
                            'estimated_minutes' => null,
                            'status' => 'error',
                            'error' => $e->getMessage()
                        ];

                        $results[] = $result;
                        $failed++;

                        echo "data: " . json_encode([
                            'type' => 'result',
                            'result' => $result,
                            'statistics' => [
                                'processed' => $processed + 1,
                                'successful' => $successful,
                                'failed' => $failed,
                                'total' => $totalTickets
                            ]
                        ]) . "\n\n";
                    }

                    $processed++;
                    flush();

                    // Pausa per evitare rate limiting
                    usleep(200000); // 0.2 secondi
                }

                // Calcola statistiche finali
                $avgMinutes = $successful > 0 ?
                    array_sum(array_column(array_filter($results, fn($r) => $r['status'] === 'success'), 'estimated_minutes')) / $successful : 0;

                echo "data: " . json_encode([
                    'type' => 'completed',
                    'results' => $results,
                    'statistics' => [
                        'total_tickets' => $totalTickets,
                        'successful_analyses' => $successful,
                        'failed_analyses' => $failed,
                        'success_rate' => $totalTickets > 0 ? round(($successful / $totalTickets) * 100, 2) : 0,
                        'average_estimated_minutes' => round($avgMinutes, 1),
                        'average_estimated_hours' => round($avgMinutes / 60, 2)
                    ]
                ]) . "\n\n";
            } catch (Exception $e) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => 'Errore nell\'analisi: ' . $e->getMessage()
                ]) . "\n\n";
            }

            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Nginx
        ]);
    }

    /**
     * Avvia l'analisi dei ticket usando un job in background
     */
    public function analyzeTicketsJob(Request $request): JsonResponse {
        try {
            $bucketName = $request->get('bucket_name', config('services.vertex_ai.bucket_name'));
            $filePath = $request->get('file_path', config('services.vertex_ai.dataset_path'));
            $modelName = $request->get('model', 'gemini-2.0-flash-lite-001');
            $batchId = time(); // Usa timestamp come batch ID

            Log::info('Avvio job analisi ticket', [
                'bucket' => $bucketName,
                'file' => $filePath,
                'model' => $modelName,
                'batch_id' => $batchId
            ]);

            // Dispatch del job
            AnalyzeTicketsJob::dispatch($bucketName, $filePath, $modelName, $batchId);

            return response()->json([
                'success' => true,
                'message' => 'Job di analisi avviato con successo',
                'data' => [
                    'batch_id' => $batchId,
                    'bucket_name' => $bucketName,
                    'file_path' => $filePath,
                    'model' => $modelName,
                    'started_at' => now()->toISOString()
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Errore nell\'avvio del job di analisi', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nell\'avvio del job: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ottieni lo stato dell'analisi e i risultati salvati nel database
     */
    public function getAnalysisResults(Request $request): JsonResponse {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);
            $status = $request->get('status');
            $company = $request->get('company');

            $query = TicketPrediction::query()
                ->orderBy('created_at', 'desc');

            // Filtri
            if ($status) {
                $query->where('status', $status);
            }

            if ($company) {
                $query->byCompany($company);
            }

            $results = $query->paginate($perPage, ['*'], 'page', $page);

            // Statistiche
            $stats = [
                'total' => TicketPrediction::count(),
                'processed' => TicketPrediction::where('status', 'processed')->count(),
                'failed' => TicketPrediction::where('status', 'failed')->count(),
                'pending' => TicketPrediction::where('status', 'pending')->count(),
            ];

            $stats['success_rate'] = $stats['total'] > 0
                ? round(($stats['processed'] / $stats['total']) * 100, 2)
                : 0;

            // Media dei minuti stimati
            $avgMinutes = TicketPrediction::successful()
                ->avg('predicted_minutes');

            $stats['average_minutes'] = $avgMinutes ? round($avgMinutes, 1) : 0;
            $stats['average_hours'] = $avgMinutes ? round($avgMinutes / 60, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'results' => $results->items(),
                    'pagination' => [
                        'current_page' => $results->currentPage(),
                        'last_page' => $results->lastPage(),
                        'per_page' => $results->perPage(),
                        'total' => $results->total(),
                        'from' => $results->firstItem(),
                        'to' => $results->lastItem(),
                    ],
                    'statistics' => $stats
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Errore nel recupero risultati analisi', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero dei risultati: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ottieni statistiche dettagliate sui risultati
     */
    public function getAnalysisStatistics(): JsonResponse {
        try {
            $stats = [
                'overview' => [
                    'total_tickets' => TicketPrediction::count(),
                    'processed' => TicketPrediction::where('status', 'processed')->count(),
                    'failed' => TicketPrediction::where('status', 'failed')->count(),
                    'pending' => TicketPrediction::where('status', 'pending')->count(),
                ],
                'timing' => [
                    'average_minutes' => TicketPrediction::successful()->avg('predicted_minutes'),
                    'min_minutes' => TicketPrediction::successful()->min('predicted_minutes'),
                    'max_minutes' => TicketPrediction::successful()->max('predicted_minutes'),
                ],
                'by_company' => TicketPrediction::successful()
                    ->selectRaw('company_name, COUNT(*) as count, AVG(predicted_minutes) as avg_minutes')
                    ->groupBy('company_name')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
                'by_type' => TicketPrediction::successful()
                    ->selectRaw('ticket_type, COUNT(*) as count, AVG(predicted_minutes) as avg_minutes')
                    ->groupBy('ticket_type')
                    ->orderBy('count', 'desc')
                    ->get(),
                'recent_activity' => TicketPrediction::orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get(['ticket_id', 'status', 'predicted_minutes', 'created_at'])
            ];

            // Calcola percentuali
            if ($stats['overview']['total_tickets'] > 0) {
                $total = $stats['overview']['total_tickets'];
                $stats['overview']['success_rate'] = round(($stats['overview']['processed'] / $total) * 100, 2);
                $stats['overview']['failure_rate'] = round(($stats['overview']['failed'] / $total) * 100, 2);
                $stats['overview']['pending_rate'] = round(($stats['overview']['pending'] / $total) * 100, 2);
            }

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (Exception $e) {
            Log::error('Errore nel recupero statistiche', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero delle statistiche: ' . $e->getMessage()
            ], 500);
        }
    }
}
