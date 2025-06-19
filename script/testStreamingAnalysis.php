<?php

/**
 * Script per testare l'analisi in streaming dei ticket
 * Questo script mostra come utilizzare l'endpoint di streaming
 */

echo "=== Test Streaming Analisi Ticket ===\n";

// Carica le variabili d'ambiente
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$bucketName = $_ENV['VERTEX_AI_BUCKET_NAME'] ?? 'your-bucket-name';
$filePath = $_ENV['VERTEX_AI_DATASET_PATH'] ?? 'your-dataset-file.jsonl';
$model = 'gemini-2.0-flash-lite-001';

$url = 'http://localhost/api/simple-analysis/analyze-stream?' . http_build_query([
    'bucket_name' => $bucketName,
    'file_path' => $filePath,
    'model' => $model
]);

echo "URL: $url\n";
echo "Bucket: $bucketName\n";
echo "File: $filePath\n";
echo "Modello: $model\n\n";

echo "Avvio streaming...\n";
echo str_repeat("-", 50) . "\n";

// Inizializza cURL per SSE
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function ($ch, $data) {
        // Processa i dati SSE
        if (strpos($data, 'data: ') === 0) {
            $json = substr($data, 6);
            $json = trim($json);

            if ($json) {
                $event = json_decode($json, true);
                if ($event) {
                    handleStreamEvent($event);
                }
            }
        }
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 300, // 5 minuti
    CURLOPT_HTTPHEADER => [
        'Accept: text/event-stream',
        'Cache-Control: no-cache'
    ]
]);

$results = [];
$stats = ['processed' => 0, 'successful' => 0, 'failed' => 0, 'total' => 0];

function handleStreamEvent($event) {
    global $results, $stats;

    switch ($event['type']) {
        case 'init':
            echo "🚀 " . $event['message'] . "\n";
            break;

        case 'progress':
            echo "📝 " . $event['message'] . "\n";
            break;

        case 'tickets_loaded':
            echo "📦 " . $event['message'] . "\n";
            $stats['total'] = $event['total'];
            echo "    Totale ticket da processare: {$event['total']}\n\n";
            break;

        case 'processing':
            echo "⚙️  " . $event['message'] . "\n";
            break;

        case 'result':
            $result = $event['result'];
            $currentStats = $event['statistics'];

            if ($result['status'] === 'success') {
                echo "✅ {$result['ticket_id']}: {$result['estimated_minutes']} minuti\n";
                $stats['successful']++;
            } else {
                echo "❌ {$result['ticket_id']}: ERRORE - {$result['error']}\n";
                $stats['failed']++;
            }

            $results[] = $result;
            $stats['processed'] = $currentStats['processed'];

            // Mostra progresso ogni 10 ticket
            if ($stats['processed'] % 10 === 0) {
                $percentage = round(($stats['processed'] / $stats['total']) * 100, 1);
                echo "📊 Progresso: {$stats['processed']}/{$stats['total']} ({$percentage}%) - " .
                    "Successi: {$stats['successful']}, Errori: {$stats['failed']}\n";
            }
            break;

        case 'completed':
            echo "\n🎉 ANALISI COMPLETATA!\n";
            echo str_repeat("=", 50) . "\n";

            $finalStats = $event['statistics'];
            echo "📈 STATISTICHE FINALI:\n";
            echo "   Ticket totali: {$finalStats['total_tickets']}\n";
            echo "   Analisi riuscite: {$finalStats['successful_analyses']}\n";
            echo "   Analisi fallite: {$finalStats['failed_analyses']}\n";
            echo "   Tasso di successo: {$finalStats['success_rate']}%\n";
            echo "   Tempo medio: {$finalStats['average_estimated_minutes']} minuti\n";
            echo "   Tempo medio: {$finalStats['average_estimated_hours']} ore\n\n";

            // Salva risultati
            saveResults($event['results'], $finalStats);
            break;

        case 'error':
            echo "💥 ERRORE: " . $event['message'] . "\n";
            break;
    }
}

function saveResults($results, $stats) {
    // Salva JSON completo
    $jsonFile = 'streaming_analysis_results_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($jsonFile, json_encode([
        'results' => $results,
        'statistics' => $stats,
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Salva CSV semplice
    $csvFile = 'streaming_estimates_' . date('Y-m-d_H-i-s') . '.csv';
    $csvContent = "Ticket ID,Minuti Stimati,Ore Stimate,Stato,Errore\n";

    foreach ($results as $result) {
        $hours = $result['estimated_minutes'] ? round($result['estimated_minutes'] / 60, 2) : '';
        $error = str_replace('"', '""', $result['error'] ?? '');

        $csvContent .= sprintf(
            '"%s","%s","%s","%s","%s"' . "\n",
            $result['ticket_id'],
            $result['estimated_minutes'] ?? '',
            $hours,
            $result['status'],
            $error
        );
    }

    file_put_contents($csvFile, $csvContent);

    echo "💾 Risultati salvati:\n";
    echo "   JSON: $jsonFile\n";
    echo "   CSV: $csvFile\n";
}

// Esegui la richiesta
echo "Connessione al server di streaming...\n";
$result = curl_exec($ch);

if (curl_error($ch)) {
    echo "Errore cURL: " . curl_error($ch) . "\n";
}

curl_close($ch);
echo "\nStreaming terminato.\n";
