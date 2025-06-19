<?php

/**
 * Script di esempio per testare l'API di predizione dei ticket
 * 
 * Usa questo script per testare le predizioni prima di integrare
 * con il tuo sistema esistente.
 */

// Dati di esempio per un ticket
$ticketData = [
    'ticket_id' => 'TICKET-001',
    'subject' => 'Problema configurazione server email',
    'description' => 'Il server email non invia le email correttamente',
    'software_description' => 'Configurazione SMTP non funzionante, errori di connessione timeout',
    'ticket_type' => 'tecnico',
    'channel' => 'email',
    'company_name' => 'Azienda Test SRL',
    'all_messages_json' => [
        [
            'message' => 'Salve, abbiamo problemi con le email',
            'timestamp' => '2025-06-19 10:00:00'
        ],
        [
            'message' => 'Le email non partono dal server',
            'timestamp' => '2025-06-19 10:05:00'
        ]
    ],
    'all_updates_json' => [
        [
            'update' => 'Ticket aperto',
            'timestamp' => '2025-06-19 10:00:00'
        ]
    ]
];

// URL dell'API locale (modifica se necessario)
$apiUrl = 'http://localhost/api/ticket-predictions/predict';

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
    CURLOPT_TIMEOUT => 30
]);

// Esegui la richiesta
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Mostra i risultati
echo "=== Test API Predizione Ticket ===\n";
echo "HTTP Code: $httpCode\n";

if ($error) {
    echo "Errore cURL: $error\n";
} else {
    echo "Risposta:\n";
    $responseData = json_decode($response, true);
    echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    if ($responseData && $responseData['success']) {
        $prediction = $responseData['data'];
        echo "\n=== Risultato Predizione ===\n";
        echo "Ticket ID: " . $prediction['ticket_id'] . "\n";
        echo "Tempo stimato: " . $prediction['predicted_minutes'] . " minuti\n";
        echo "Tempo stimato: " . $prediction['predicted_hours'] . " ore\n";
        echo "Confidenza: " . ($prediction['confidence_score'] * 100) . "%\n";
        echo "Qualità: " . $prediction['prediction_quality'] . "\n";
    }
}

echo "\n=== Test API Statistiche ===\n";

// Test delle statistiche
$statsUrl = 'http://localhost/api/ticket-predictions/statistics';
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $statsUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($response) {
    $statsData = json_decode($response, true);
    echo json_encode($statsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
