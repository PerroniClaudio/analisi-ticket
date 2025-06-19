<?php

/**
 * Script di test per la predizione usando l'analisi del dataset dal bucket
 * 
 * Questo script testa l'endpoint che utilizza il dataset esistente su Google Cloud Storage
 * per trovare ticket simili e fare predizioni più accurate.
 */

// Dati di esempio per testare la predizione con dataset
$ticketData = [
    'ticket_id' => 'TEST-DATASET-001',
    'subject' => 'Problema email server configurazione SMTP',
    'description' => 'Il server email non riesce a inviare le email',
    'software_description' => 'Errore timeout nella configurazione SMTP, le email non partono dal server aziendale',
    'ticket_type' => 'tecnico',
    'channel' => 'email',
    'company_name' => 'Labor Medical Srl',
    'all_messages_json' => [
        [
            'message' => 'Buongiorno, abbiamo problemi con le email dal server aziendale',
            'timestamp' => '2025-06-19 10:00:00',
            'author_role' => 'Client'
        ],
        [
            'message' => 'Le email non partono e riceviamo errori di timeout',
            'timestamp' => '2025-06-19 10:05:00',
            'author_role' => 'Client'
        ],
        [
            'message' => 'Stiamo verificando la configurazione SMTP',
            'timestamp' => '2025-06-19 10:15:00',
            'author_role' => 'Agent'
        ]
    ],
    'all_updates_json' => [
        [
            'update' => 'Ticket aperto - problema email server',
            'timestamp' => '2025-06-19 10:00:00',
            'author_role' => 'Agent'
        ],
        [
            'update' => 'In analisi configurazione SMTP',
            'timestamp' => '2025-06-19 10:15:00',
            'author_role' => 'Agent'
        ]
    ]
];

// URL dell'API per la predizione con dataset analysis
$apiUrl = 'http://localhost/api/ticket-predictions/predict-dataset';

echo "=== Test Predizione con Analisi Dataset ===\n";
echo "Ticket ID: " . $ticketData['ticket_id'] . "\n";
echo "Soggetto: " . $ticketData['subject'] . "\n";
echo "Azienda: " . $ticketData['company_name'] . "\n";
echo "Messaggi: " . count($ticketData['all_messages_json']) . "\n\n";

// Inizializza cURL
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($ticketData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 60 // Timeout più lungo per il caricamento del dataset
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
} else {
    echo "Risposta:\n";
    $responseData = json_decode($response, true);
    echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    if ($responseData && $responseData['success']) {
        $prediction = $responseData['data'];
        echo "\n=== Risultato Predizione Dataset ===\n";
        echo "Ticket ID: " . $prediction['ticket_id'] . "\n";
        echo "Tempo stimato: " . $prediction['predicted_minutes'] . " minuti\n";
        echo "Tempo stimato: " . $prediction['predicted_hours'] . " ore\n";
        echo "Confidenza: " . round($prediction['confidence_score'] * 100, 1) . "%\n";
        echo "Qualità: " . $prediction['prediction_quality'] . "\n";
        echo "Metodo: " . ($prediction['method'] ?? 'N/A') . "\n";
        echo "Ticket simili trovati: " . ($prediction['similar_tickets_found'] ?? 'N/A') . "\n";
    }
}

echo "\n=== Confronto con Predizione Euristica ===\n";

// Test della predizione con modello di testo per confronto
$textApiUrl = 'http://localhost/api/ticket-predictions/predict-text';
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $textApiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array_merge($ticketData, ['ticket_id' => 'TEST-HEURISTIC-001'])),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response) {
    $heuristicData = json_decode($response, true);
    if ($heuristicData && $heuristicData['success']) {
        $heuristicPrediction = $heuristicData['data'];
        echo "Predizione euristica: " . $heuristicPrediction['predicted_minutes'] . " minuti\n";
        echo "Confidenza euristica: " . round($heuristicPrediction['confidence_score'] * 100, 1) . "%\n";

        if (isset($prediction)) {
            $difference = $prediction['predicted_minutes'] - $heuristicPrediction['predicted_minutes'];
            echo "Differenza: " . ($difference > 0 ? '+' : '') . $difference . " minuti\n";

            $confidenceDiff = $prediction['confidence_score'] - $heuristicPrediction['confidence_score'];
            echo "Differenza confidenza: " . ($confidenceDiff > 0 ? '+' : '') . round($confidenceDiff * 100, 1) . "%\n";
        }
    }
}

echo "\n=== Test Completati ===\n";
echo "Suggerimento: Usa /predict-dataset per predizioni più accurate basate sui dati storici\n";
echo "Suggerimento: Usa /predict-text per predizioni rapide euristiche\n";
echo "Suggerimento: Usa /predict per predizioni con Vertex AI (se endpoint configurato)\n";
