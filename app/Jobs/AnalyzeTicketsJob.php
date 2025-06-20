<?php

namespace App\Jobs;

use App\Models\TicketPrediction;
use App\Services\SimpleVertexAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class AnalyzeTicketsJob implements ShouldQueue {
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Timeout del job in secondi (1 ora)
     */
    public $timeout = 3600;

    /**
     * Numero di tentativi massimi
     */
    public $tries = 3;

    protected string $bucketName;
    protected string $filePath;
    protected string $modelName;
    protected ?int $batchId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $bucketName,
        string $filePath,
        string $modelName = 'gemini-2.0-flash-lite-001',
        ?int $batchId = null
    ) {
        $this->bucketName = $bucketName;
        $this->filePath = $filePath;
        $this->modelName = $modelName;
        $this->batchId = $batchId;
    }

    /**
     * Execute the job.
     */
    public function handle(SimpleVertexAiService $vertexAiService): void {
        Log::info('Inizio analisi ticket batch', [
            'bucket' => $this->bucketName,
            'file' => $this->filePath,
            'model' => $this->modelName,
            'batch_id' => $this->batchId
        ]);

        try {
            // 1. Carica i ticket dal bucket
            $tickets = $vertexAiService->loadTicketsFromBucketPublic($this->bucketName, $this->filePath);
            $totalTickets = count($tickets);

            Log::info("Caricati {$totalTickets} ticket dal bucket");

            $processed = 0;
            $successful = 0;
            $failed = 0;

            // 2. Processa ogni ticket
            foreach ($tickets as $index => $ticket) {
                // Controllo periodico del timeout
                if ($processed > 0 && $processed % 50 === 0) {
                    Log::info("Checkpoint progresso", [
                        'processed' => $processed,
                        'successful' => $successful,
                        'failed' => $failed,
                        'total' => $totalTickets,
                        'batch_id' => $this->batchId
                    ]);
                }

                $ticketId = $ticket['tid'] ?? $ticket['ticket_id'] ?? "TICKET-{$index}";

                try {
                    // Verifica se il ticket è già stato processato
                    $existingPrediction = TicketPrediction::where('ticket_id', $ticketId)->first();

                    if ($existingPrediction && $existingPrediction->status === 'processed') {
                        Log::info("Ticket {$ticketId} già processato, skip");
                        continue;
                    }

                    // Crea o aggiorna il record
                    $prediction = TicketPrediction::updateOrCreate(
                        ['ticket_id' => $ticketId],
                        [
                            'company_name' => $ticket['company_name'] ?? 'Unknown',
                            'subject' => $ticket['subject'] ?? '',
                            'description' => $ticket['description'] ?? '',
                            'ticket_type' => $ticket['type'] ?? 'unknown',
                            'channel' => $ticket['channel'] ?? 'unknown',
                            'ticket_data' => $ticket,
                            'status' => 'pending',
                            'model_version' => $this->modelName,
                        ]
                    );

                    Log::info("Processando ticket {$ticketId}");

                    // 3. Esegui la predizione
                    $estimatedMinutes = $vertexAiService->estimateTicketTimePublic($ticket, $this->modelName);

                    // 4. Aggiorna il record con i risultati
                    $prediction->update([
                        'predicted_minutes' => $estimatedMinutes,
                        'status' => 'processed',
                        'predicted_at' => now(),
                        'error_message' => null,
                    ]);

                    $successful++;
                    Log::info("Ticket {$ticketId} processato con successo: {$estimatedMinutes} minuti");
                } catch (Exception $e) {
                    Log::error("Errore nel processamento del ticket {$ticketId}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Aggiorna il record con l'errore
                    if (isset($prediction)) {
                        $prediction->update([
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                        ]);
                    } else {
                        // Crea un record di errore se non esiste
                        TicketPrediction::create([
                            'ticket_id' => $ticketId,
                            'company_name' => $ticket['company_name'] ?? 'Unknown',
                            'subject' => $ticket['subject'] ?? '',
                            'description' => $ticket['description'] ?? '',
                            'ticket_type' => $ticket['type'] ?? 'unknown',
                            'channel' => $ticket['channel'] ?? 'unknown',
                            'ticket_data' => $ticket,
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                            'model_version' => $this->modelName,
                        ]);
                    }

                    $failed++;
                }

                $processed++;

                // Pausa per evitare rate limiting (ridotta per job lunghi)
                usleep(100000); // 0.1 secondi
            }

            // 5. Log finale
            Log::info('Analisi ticket completata', [
                'total_tickets' => $totalTickets,
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'success_rate' => $processed > 0 ? round(($successful / $processed) * 100, 2) : 0,
                'batch_id' => $this->batchId
            ]);
        } catch (Exception $e) {
            Log::error('Errore critico nell\'analisi dei ticket', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bucket' => $this->bucketName,
                'file' => $this->filePath,
                'batch_id' => $this->batchId
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void {
        Log::error('Job AnalyzeTicketsJob fallito', [
            'bucket' => $this->bucketName,
            'file' => $this->filePath,
            'model' => $this->modelName,
            'batch_id' => $this->batchId,
            'error' => $exception ? $exception->getMessage() : 'Unknown error'
        ]);
    }
}
