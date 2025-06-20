<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeTicketsJob;
use App\Models\TicketPrediction;
use Illuminate\Console\Command;

class TestAnalyzeTicketsJob extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:analyze-tickets 
                            {--bucket=your-bucket-name : Nome del bucket Google Cloud Storage}
                            {--file=your-dataset-file.jsonl : Percorso del file JSONL nel bucket}
                            {--model=gemini-2.0-flash-lite-001 : Modello da utilizzare}
                            {--sync : Esegui in modalità sincrona invece che job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa il job di analisi ticket o esegui una query sui risultati nel database';

    /**
     * Execute the console command.
     */
    public function handle() {
        $bucket = $this->option('bucket');
        $file = $this->option('file');
        $model = $this->option('model');
        $sync = $this->option('sync');

        // Mostra statistiche attuali del database
        $this->info('=== Statistiche Database Attuali ===');
        $stats = [
            'total' => TicketPrediction::count(),
            'processed' => TicketPrediction::where('status', 'processed')->count(),
            'failed' => TicketPrediction::where('status', 'failed')->count(),
            'pending' => TicketPrediction::where('status', 'pending')->count(),
        ];

        $this->table(
            ['Stato', 'Conteggio'],
            [
                ['Totale', $stats['total']],
                ['Processati', $stats['processed']],
                ['Falliti', $stats['failed']],
                ['In attesa', $stats['pending']],
            ]
        );

        if ($stats['total'] > 0) {
            $avgMinutes = TicketPrediction::where('status', 'processed')->avg('predicted_minutes');
            if ($avgMinutes) {
                $this->info("Tempo medio predetto: " . round($avgMinutes, 1) . " minuti (" . round($avgMinutes / 60, 2) . " ore)");
            }
        }

        // Chiedi se lanciare un nuovo job
        if ($this->confirm('Vuoi lanciare un nuovo job di analisi?')) {

            if (!$bucket || $bucket === 'your-bucket-name') {
                $bucket = $this->ask('Nome del bucket:', config('services.vertex_ai.bucket_name'));
            }

            if (!$file || $file === 'your-dataset-file.jsonl') {
                $file = $this->ask('Percorso file JSONL:', config('services.vertex_ai.dataset_path'));
            }

            $batchId = time();

            $this->info("Avvio analisi con:");
            $this->line("- Bucket: {$bucket}");
            $this->line("- File: {$file}");
            $this->line("- Modello: {$model}");
            $this->line("- Batch ID: {$batchId}");
            $this->line("- Modalità: " . ($sync ? 'Sincrona' : 'Job in background'));

            if ($sync) {
                // Esecuzione sincrona per test
                $this->info('Esecuzione sincrona...');

                $job = new AnalyzeTicketsJob($bucket, $file, $model, $batchId);
                $job->handle(app(\App\Services\SimpleVertexAiService::class));

                $this->info('Analisi completata!');
            } else {
                // Dispatch del job
                AnalyzeTicketsJob::dispatch($bucket, $file, $model, $batchId);
                $this->info('Job dispatched! Batch ID: ' . $batchId);
                $this->info('Monitora i log con: tail -f storage/logs/laravel.log');
            }
        }

        // Mostra ultimi risultati
        if ($this->confirm('Vuoi vedere gli ultimi risultati?')) {
            $latestResults = TicketPrediction::orderBy('created_at', 'desc')->limit(10)->get();

            if ($latestResults->count() > 0) {
                $this->info('=== Ultimi 10 Risultati ===');
                $tableData = $latestResults->map(function ($result) {
                    return [
                        $result->ticket_id,
                        $result->status,
                        $result->predicted_minutes ?? 'N/A',
                        $result->error_message ? substr($result->error_message, 0, 50) . '...' : '',
                        $result->created_at->format('Y-m-d H:i:s')
                    ];
                })->toArray();

                $this->table(
                    ['Ticket ID', 'Stato', 'Minuti', 'Errore', 'Creato'],
                    $tableData
                );
            } else {
                $this->warn('Nessun risultato trovato nel database.');
            }
        }
    }
}
