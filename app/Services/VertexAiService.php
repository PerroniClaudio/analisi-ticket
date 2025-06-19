<?php

namespace App\Services;

use Google\Cloud\AiPlatform\V1\PredictionServiceClient;
use Google\Cloud\AiPlatform\V1\PredictRequest;
use Google\Cloud\Storage\StorageClient;
use Google\Protobuf\Value;
use Google\Protobuf\Struct;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;

class VertexAiService {
    private $client;
    private $storageClient;
    private $projectId;
    private $location;
    private $endpointId;
    private $bucketName;
    private $datasetPath;

    public function __construct() {
        $this->projectId = config('services.vertex_ai.project_id');
        $this->location = config('services.vertex_ai.location');
        $this->endpointId = config('services.vertex_ai.endpoint_id');

        // Inizializza il client con le credenziali
        $keyFilePath = config('services.vertex_ai.key_file_path', base_path('keys/service-account.json'));

        $this->client = new PredictionServiceClient([
            'credentials' => $keyFilePath
        ]);

        $this->storageClient = new StorageClient([
            'keyFilePath' => $keyFilePath
        ]);

        $this->bucketName = config('services.vertex_ai.bucket_name');
        $this->datasetPath = config('services.vertex_ai.dataset_path');
    }

    /**
     * Predice i minuti di esecuzione per un ticket
     */
    public function predictExecutionTime(array $ticketData): array {
        try {
            // Prepara i dati per il modello
            $inputData = $this->prepareInputData($ticketData);

            // Crea la richiesta di predizione
            $request = $this->createPredictRequest($inputData);

            // Effettua la predizione
            $response = $this->client->predict($request);

            // Processa la risposta
            return $this->processResponse($response);
        } catch (Exception $e) {
            Log::error('Errore durante la predizione Vertex AI', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticketData['ticket_id'] ?? 'unknown'
            ]);

            throw new Exception("Errore nella predizione: " . $e->getMessage());
        }
    }

    /**
     * Prepara i dati di input per il modello
     */
    private function prepareInputData(array $ticketData): array {
        // Estrai le features più rilevanti per la predizione
        $features = [
            'subject' => $ticketData['subject'] ?? '',
            'description' => $ticketData['software_description'] ?? $ticketData['description'] ?? '',
            'ticket_type' => $ticketData['ticket_type'] ?? '',
            'channel' => $ticketData['channel'] ?? '',
            'company_name' => $ticketData['company_name'] ?? '',
            'messages_count' => count($ticketData['all_messages_json'] ?? []),
            'updates_count' => count($ticketData['all_updates_json'] ?? []),
        ];

        // Combina soggetto e descrizione per creare un testo unificato
        $combinedText = trim($features['subject'] . ' ' . $features['description']);

        // Aggiungi i primi messaggi se disponibili
        if (!empty($ticketData['all_messages_json'])) {
            $firstMessages = array_slice($ticketData['all_messages_json'], 0, 3);
            $messagesText = implode(' ', array_column($firstMessages, 'message'));
            $combinedText .= ' ' . $messagesText;
        }

        $features['combined_text'] = $combinedText;
        $features['text_length'] = strlen($combinedText);

        return $features;
    }

    /**
     * Crea la richiesta di predizione per Vertex AI
     */
    private function createPredictRequest(array $inputData): PredictRequest {
        $endpoint = sprintf(
            'projects/%s/locations/%s/endpoints/%s',
            $this->projectId,
            $this->location,
            $this->endpointId
        );

        // Crea la struttura dei dati per la predizione
        $instanceStruct = new Struct();

        foreach ($inputData as $key => $value) {
            $valueProto = new Value();
            if (is_string($value)) {
                $valueProto->setStringValue($value);
            } elseif (is_numeric($value)) {
                $valueProto->setNumberValue($value);
            } else {
                $valueProto->setStringValue(strval($value));
            }
            $instanceStruct->getFields()[$key] = $valueProto;
        }

        $instance = new Value();
        $instance->setStructValue($instanceStruct);

        $request = new PredictRequest();
        $request->setEndpoint($endpoint);
        $request->setInstances([$instance]);

        return $request;
    }

    /**
     * Processa la risposta del modello
     */
    private function processResponse($response): array {
        $predictions = $response->getPredictions();

        if (empty($predictions)) {
            throw new Exception('Nessuna predizione ricevuta dal modello');
        }

        $prediction = $predictions[0];

        // Estrai i valori dalla risposta
        // La struttura dipende dal tipo di modello utilizzato
        $predictedMinutes = null;
        $confidenceScore = null;

        if ($prediction->hasStructValue()) {
            $struct = $prediction->getStructValue();
            $fields = $struct->getFields();

            // Cerca i campi comuni nelle risposte di Vertex AI
            if (isset($fields['predicted_minutes'])) {
                $predictedMinutes = $fields['predicted_minutes']->getNumberValue();
            } elseif (isset($fields['prediction'])) {
                $predictedMinutes = $fields['prediction']->getNumberValue();
            } elseif (isset($fields['value'])) {
                $predictedMinutes = $fields['value']->getNumberValue();
            }

            if (isset($fields['confidence'])) {
                $confidenceScore = $fields['confidence']->getNumberValue();
            } elseif (isset($fields['probability'])) {
                $confidenceScore = $fields['probability']->getNumberValue();
            }
        } elseif ($prediction->hasNumberValue()) {
            $predictedMinutes = $prediction->getNumberValue();
        }

        return [
            'predicted_minutes' => $predictedMinutes,
            'confidence_score' => $confidenceScore,
            'raw_response' => $this->convertProtobufToArray($prediction)
        ];
    }

    /**
     * Converte una risposta Protobuf in array PHP
     */
    private function convertProtobufToArray($protobuf): array {
        $json = $protobuf->serializeToJsonString();
        return json_decode($json, true);
    }

    /**
     * Predizione usando un modello pre-addestrato di testo (es. per classificazione)
     */
    public function predictWithTextModel(array $ticketData): array {
        try {
            // Per modelli di testo, usa un approccio diverso
            $prompt = $this->createPromptForTextModel($ticketData);

            // Questa è una versione semplificata che potresti adattare
            // per modelli specifici di Vertex AI come PaLM o altri LLM
            $inputData = [
                'instances' => [
                    [
                        'prompt' => $prompt
                    ]
                ],
                'parameters' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 256,
                    'topP' => 0.8,
                    'topK' => 40
                ]
            ];

            // Per ora restituiamo una stima basata su euristiche
            return $this->generateHeuristicPrediction($ticketData);
        } catch (Exception $e) {
            Log::error('Errore nella predizione con text model', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticketData['ticket_id'] ?? 'unknown'
            ]);

            throw $e;
        }
    }

    /**
     * Crea un prompt strutturato per il modello di testo
     */
    private function createPromptForTextModel(array $ticketData): string {
        $subject = $ticketData['subject'] ?? '';
        $description = $ticketData['software_description'] ?? $ticketData['description'] ?? '';
        $type = $ticketData['ticket_type'] ?? '';
        $channel = $ticketData['channel'] ?? '';

        $messagesCount = count($ticketData['all_messages_json'] ?? []);

        return "Analizza questo ticket di supporto tecnico e stima i minuti necessari per risolverlo:

Oggetto: {$subject}
Descrizione: {$description}
Tipo: {$type}
Canale: {$channel}
Numero di messaggi: {$messagesCount}

Basandoti su ticket simili, fornisci una stima in minuti per la risoluzione. Considera:
- Complessità tecnica del problema
- Numero di interazioni richieste
- Tipo di supporto necessario

Rispondi solo con il numero di minuti stimati.";
    }

    /**
     * Genera una predizione euristica quando il modello AI non è disponibile
     */
    private function generateHeuristicPrediction(array $ticketData): array {
        $baseMinutes = 30; // Tempo base
        $subject = strtolower($ticketData['subject'] ?? '');
        $description = strtolower($ticketData['software_description'] ?? $ticketData['description'] ?? '');
        $messagesCount = count($ticketData['all_messages_json'] ?? []);

        // Fattori che aumentano il tempo
        $complexityFactors = [
            'errore' => 15,
            'installazione' => 25,
            'configurazione' => 20,
            'rete' => 30,
            'database' => 35,
            'server' => 40,
            'sicurezza' => 45,
            'backup' => 20,
            'ripristino' => 50,
            'migrazione' => 60,
            'aggiornamento' => 25,
            'crash' => 35,
            'lento' => 20,
            'non funziona' => 25,
            'problema' => 15
        ];

        $additionalMinutes = 0;
        $combinedText = $subject . ' ' . $description;

        foreach ($complexityFactors as $keyword => $minutes) {
            if (strpos($combinedText, $keyword) !== false) {
                $additionalMinutes += $minutes;
            }
        }

        // Fattore basato sul numero di messaggi
        $messagesFactor = min($messagesCount * 5, 60); // Max 60 minuti aggiuntivi

        $totalMinutes = $baseMinutes + $additionalMinutes + $messagesFactor;

        // Arrotonda a multipli di 5
        $totalMinutes = round($totalMinutes / 5) * 5;

        // Limita tra 15 e 240 minuti
        $totalMinutes = max(15, min(240, $totalMinutes));

        return [
            'predicted_minutes' => $totalMinutes,
            'confidence_score' => 0.65, // Confidence moderata per predizioni euristiche
            'raw_response' => [
                'method' => 'heuristic',
                'base_minutes' => $baseMinutes,
                'complexity_bonus' => $additionalMinutes,
                'messages_bonus' => $messagesFactor,
                'detected_keywords' => array_keys(array_filter($complexityFactors, function ($keyword) use ($combinedText) {
                    return strpos($combinedText, $keyword) !== false;
                }, ARRAY_FILTER_USE_KEY))
            ]
        ];
    }

    /**
     * Predice usando il dataset esistente dal bucket per migliorare le stime
     */
    public function predictWithDatasetAnalysis(array $ticketData): array {
        try {
            // Carica e analizza il dataset dal bucket
            $similarTickets = $this->findSimilarTicketsFromDataset($ticketData);

            // Se troviamo ticket simili, usa la loro media come base
            if (!empty($similarTickets)) {
                $prediction = $this->generatePredictionFromSimilarTickets($ticketData, $similarTickets);
            } else {
                // Fallback all'algoritmo euristico
                $prediction = $this->generateHeuristicPrediction($ticketData);
            }

            return $prediction;
        } catch (Exception $e) {
            Log::error('Errore nella predizione con dataset analysis', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticketData['ticket_id'] ?? 'unknown'
            ]);

            // Fallback all'algoritmo euristico
            return $this->generateHeuristicPrediction($ticketData);
        }
    }

    /**
     * Carica il dataset dal bucket Google Cloud Storage
     */
    private function loadDatasetFromBucket(): array {
        $cacheKey = "vertex_ai_dataset_{$this->bucketName}_{$this->datasetPath}";

        // Controlla se il dataset è già in cache (valido per 1 ora)
        $cachedDataset = Cache::get($cacheKey);
        if ($cachedDataset) {
            return $cachedDataset;
        }

        try {
            $bucket = $this->storageClient->bucket($this->bucketName);
            $object = $bucket->object($this->datasetPath);

            if (!$object->exists()) {
                throw new Exception("Dataset non trovato: gs://{$this->bucketName}/{$this->datasetPath}");
            }

            // Scarica il contenuto del file JSONL
            $content = $object->downloadAsString();

            // Parsa ogni riga del JSONL
            $dataset = [];
            $lines = explode("\n", trim($content));

            foreach ($lines as $line) {
                if (trim($line)) {
                    $ticketData = json_decode($line, true);
                    if ($ticketData) {
                        $dataset[] = $ticketData;
                    }
                }
            }

            // Salva in cache per 1 ora
            Cache::put($cacheKey, $dataset, 3600);

            Log::info("Dataset caricato dal bucket", [
                'bucket' => $this->bucketName,
                'path' => $this->datasetPath,
                'tickets_count' => count($dataset)
            ]);

            return $dataset;
        } catch (Exception $e) {
            Log::error('Errore nel caricamento del dataset dal bucket', [
                'bucket' => $this->bucketName,
                'path' => $this->datasetPath,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Trova ticket simili nel dataset basandosi su caratteristiche comuni
     */
    private function findSimilarTicketsFromDataset(array $targetTicket): array {
        $dataset = $this->loadDatasetFromBucket();
        $similarTickets = [];

        $targetSubject = strtolower($targetTicket['subject'] ?? '');
        $targetDescription = strtolower($targetTicket['software_description'] ?? $targetTicket['description'] ?? '');
        $targetType = $targetTicket['ticket_type'] ?? '';
        $targetCompany = $targetTicket['company_name'] ?? '';

        foreach ($dataset as $ticket) {
            $similarity = $this->calculateTicketSimilarity($targetTicket, $ticket);

            // Considera simili i ticket con similarity > 0.3
            if ($similarity > 0.3) {
                $ticket['similarity_score'] = $similarity;
                $similarTickets[] = $ticket;
            }
        }

        // Ordina per similarità decrescente
        usort($similarTickets, function ($a, $b) {
            return $b['similarity_score'] <=> $a['similarity_score'];
        });

        // Prendi i primi 10 ticket più simili
        return array_slice($similarTickets, 0, 10);
    }

    /**
     * Calcola la similarità tra due ticket
     */
    private function calculateTicketSimilarity(array $ticket1, array $ticket2): float {
        $score = 0;
        $maxScore = 0;

        // Similarità del soggetto (peso: 40%)
        $subject1 = strtolower($ticket1['subject'] ?? '');
        $subject2 = strtolower($ticket2['obj'] ?? $ticket2['subject'] ?? '');
        if ($subject1 && $subject2) {
            $subjectSimilarity = $this->calculateTextSimilarity($subject1, $subject2);
            $score += $subjectSimilarity * 0.4;
        }
        $maxScore += 0.4;

        // Similarità del tipo (peso: 20%)
        $type1 = $ticket1['ticket_type'] ?? '';
        $type2 = $ticket2['type'] ?? $ticket2['ticket_type'] ?? '';
        if ($type1 === $type2 && $type1 !== '') {
            $score += 0.2;
        }
        $maxScore += 0.2;

        // Similarità della descrizione (peso: 30%)
        $desc1 = strtolower($ticket1['software_description'] ?? $ticket1['description'] ?? '');
        $desc2 = strtolower($ticket2['software_description'] ?? $ticket2['description'] ?? '');
        if ($desc1 && $desc2) {
            $descSimilarity = $this->calculateTextSimilarity($desc1, $desc2);
            $score += $descSimilarity * 0.3;
        }
        $maxScore += 0.3;

        // Similarità dell'azienda (peso: 10%)
        $company1 = $ticket1['company_name'] ?? '';
        $company2 = $ticket2['azienda'] ?? $ticket2['company_name'] ?? '';
        if ($company1 === $company2 && $company1 !== '') {
            $score += 0.1;
        }
        $maxScore += 0.1;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    /**
     * Calcola la similarità tra due testi usando parole comuni
     */
    private function calculateTextSimilarity(string $text1, string $text2): float {
        // Tokenizza i testi
        $words1 = array_filter(explode(' ', preg_replace('/[^\w\s]/u', '', $text1)));
        $words2 = array_filter(explode(' ', preg_replace('/[^\w\s]/u', '', $text2)));

        if (empty($words1) || empty($words2)) {
            return 0;
        }

        // Calcola l'intersezione e l'unione
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        // Jaccard similarity
        return count($intersection) / count($union);
    }

    /**
     * Genera una predizione basata su ticket simili trovati nel dataset
     */
    private function generatePredictionFromSimilarTickets(array $targetTicket, array $similarTickets): array {
        // Calcola i tempi stimati basandosi sui ticket simili
        $estimatedTimes = [];
        $confidenceScores = [];

        foreach ($similarTickets as $ticket) {
            // Stima il tempo basandosi sui dati del ticket simile
            $estimatedTime = $this->estimateTimeFromHistoricalTicket($ticket);
            if ($estimatedTime > 0) {
                $estimatedTimes[] = $estimatedTime;
                $confidenceScores[] = $ticket['similarity_score'];
            }
        }

        if (empty($estimatedTimes)) {
            return $this->generateHeuristicPrediction($targetTicket);
        }

        // Media ponderata basata sulla similarità
        $totalWeight = array_sum($confidenceScores);
        $weightedSum = 0;

        for ($i = 0; $i < count($estimatedTimes); $i++) {
            $weightedSum += $estimatedTimes[$i] * $confidenceScores[$i];
        }

        $predictedMinutes = $totalWeight > 0 ? round($weightedSum / $totalWeight) : array_sum($estimatedTimes) / count($estimatedTimes);

        // Limita il risultato
        $predictedMinutes = max(15, min(240, $predictedMinutes));

        // Calcola confidence basato sulla qualità dei match
        $avgSimilarity = array_sum($confidenceScores) / count($confidenceScores);
        $confidence = min(0.95, $avgSimilarity + 0.1); // Bonus per l'uso di dati storici

        return [
            'predicted_minutes' => $predictedMinutes,
            'confidence_score' => $confidence,
            'raw_response' => [
                'method' => 'dataset_analysis',
                'similar_tickets_count' => count($similarTickets),
                'average_similarity' => $avgSimilarity,
                'estimated_times' => $estimatedTimes,
                'confidence_scores' => $confidenceScores
            ]
        ];
    }

    /**
     * Stima il tempo di risoluzione da un ticket storico
     */
    private function estimateTimeFromHistoricalTicket(array $ticket): int {
        // Se il ticket ha date di apertura e chiusura, calcola la differenza
        $openDate = $ticket['open_date'] ?? null;
        $closeDate = $ticket['close_date'] ?? null;

        if ($openDate && $closeDate) {
            try {
                $start = new \DateTime($openDate);
                $end = new \DateTime($closeDate);
                $diff = $end->diff($start);

                // Converti in minuti (assumendo ore lavorative)
                $totalMinutes = ($diff->days * 8 * 60) + ($diff->h * 60) + $diff->i;

                // Limita a valori ragionevoli (max 8 ore al giorno)
                return min(480, max(15, $totalMinutes));
            } catch (Exception $e) {
                // Se non riusciamo a parsare le date, usa l'euristica
            }
        }

        // Fallback: usa il numero di messaggi e aggiornamenti come indicatore
        $messagesCount = count($ticket['all_messages_json'] ?? []);
        $updatesCount = count($ticket['all_updates_json'] ?? []);

        // Formula empirica basata sulla complessità
        $baseTime = 30;
        $messageTime = $messagesCount * 8; // 8 minuti per messaggio
        $updateTime = $updatesCount * 5;   // 5 minuti per aggiornamento

        return min(240, max(15, $baseTime + $messageTime + $updateTime));
    }
}
