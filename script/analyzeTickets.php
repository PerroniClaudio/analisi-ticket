<?php

/**
 * Script semplice per analizzare i ticket dal bucket con Vertex AI
 */

// Configurazione
$apiUrl = 'https://analisi-ticket.test/api/simple-analysis/analyze-bucket';
// Carica le variabili d'ambiente
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$bucketName = $_ENV['VERTEX_AI_BUCKET_NAME'] ?? 'your-bucket-name';
$filePath = $_ENV['VERTEX_AI_DATASET_PATH'] ?? 'your-dataset-file.jsonl';
$model = 'gemini-2.0-flash-lite-001'; // Cambia con: gemini-1.5-pro, text-bison, chat-bison

echo "=== Analisi Ticket con Vertex AI ===\n";
echo "Bucket: {$bucketName}\n";
echo "File: {$filePath}\n";
echo "Modello: {$model}\n\n";

// Payload per la richiesta
$payload = [
    'bucket_name' => $bucketName,
    'file_path' => $filePath,
    'model' => $model
];

echo "Invio richiesta a Vertex AI...\n";
echo "ATTENZIONE: Questa operazione può richiedere alcuni minuti.\n\n";

// Inizializza cURL
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 300 // 5 minuti di timeout
]);

// Esegui la richiesta
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Mostra i risultati
echo "HTTP Code: $httpCode\n";

if ($error) {
    echo "Errore cURL: $error\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "Errore HTTP: $httpCode\n";
    echo "Risposta: $response\n";
    exit(1);
}

$data = json_decode($response, true);

if (!$data || !$data['success']) {
    echo "Errore nell'analisi: " . ($data['message'] ?? 'Errore sconosciuto') . "\n";
    exit(1);
}

// Mostra statistiche
$stats = $data['data']['statistics'];
echo "=== STATISTICHE ===\n";
echo "Ticket totali: " . $stats['total_tickets'] . "\n";
echo "Analisi riuscite: " . $stats['successful_analyses'] . "\n";
echo "Analisi fallite: " . $stats['failed_analyses'] . "\n";
echo "Tasso di successo: " . $stats['success_rate'] . "%\n";
echo "Tempo medio stimato: " . $stats['average_estimated_minutes'] . " minuti\n";
echo "Tempo medio stimato: " . $stats['average_estimated_hours'] . " ore\n\n";

// Mostra primi 10 risultati
$results = $data['data']['results'];
echo "=== PRIMI 10 RISULTATI ===\n";
printf("%-15s %-8s %-6s\n", "TICKET ID", "MINUTI", "STATUS");
echo str_repeat("-", 35) . "\n";

foreach (array_slice($results, 0, 10) as $result) {
    printf(
        "%-15s %-8s %-6s\n",
        $result['ticket_id'],
        $result['estimated_minutes'] ?? 'N/A',
        $result['status']
    );
}

if (count($results) > 10) {
    echo "... e altri " . (count($results) - 10) . " ticket\n";
}

// Salva risultati completi in file
$outputFile = 'ticket_analysis_results_' . date('Y-m-d_H-i-s') . '.json';
file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n=== RISULTATI SALVATI ===\n";
echo "File completo: $outputFile\n";

// Crea anche un CSV semplice
$csvFile = 'ticket_estimates_' . date('Y-m-d_H-i-s') . '.csv';
$csvContent = "Ticket ID,Minuti Stimati,Ore Stimate,Status\n";

foreach ($results as $result) {
    $hours = $result['estimated_minutes'] ? round($result['estimated_minutes'] / 60, 2) : '';
    $csvContent .= sprintf(
        "%s,%s,%s,%s\n",
        $result['ticket_id'],
        $result['estimated_minutes'] ?? '',
        $hours,
        $result['status']
    );
}

file_put_contents($csvFile, $csvContent);
echo "File CSV: $csvFile\n";

echo "\n=== ANALISI COMPLETATA ===\n";
