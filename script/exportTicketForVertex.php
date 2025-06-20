<?php

declare(strict_types=1);

require '../vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

// ----------------------------------------------------
// 1. Configurazione del Database MySQL
// ----------------------------------------------------

// Parametri configurabili per il filtro dei dati
$filterYear = '2024';  // Anno dei ticket da estrarre
$filterCompany = 'Labor Medical Srl';  // Nome dell'azienda da filtrare

// Carica le variabili d'ambiente dal file .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$servername = $_ENV['OLD_DB_HOST'] ?? '';
$username = $_ENV['OLD_DB_USER'] ?? '';
$password = $_ENV['OLD_DB_PASS'] ?? '';
$dbname = $_ENV['OLD_DB_NAME'] ?? '';

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    echo "Connesso al database con successo.\n";
} catch (PDOException $e) {
    exit("Errore di connessione al database: " . $e->getMessage());
}

// ----------------------------------------------------
// 2. Query SQL per estrarre i dati dei ticket
// ----------------------------------------------------

$sql = <<<SQL
SELECT
    t.tid AS ticket_id,
    t.supid AS opener_id,
    t.azienda AS company_name,
    t.persona AS involved_person,
    t.aid AS company_id,
    t.gid AS managing_group_id,
    t.nomegruppo AS managing_group_name,
    t.obj AS subject,
    STR_TO_DATE(t.tdate, '%d-%m-%Y') AS open_date,
    t.channel AS channel,
    t.type AS ticket_type,
    t.stadium AS status_stadium, 
    t.commento AS closing_comment,
    SUBSTRING_INDEX(t.gest, '-', -1) AS assigned_user_gest,
    COALESCE(STR_TO_DATE(t.cdate, '%d-%m-%Y'), t.mdate) AS close_date,
    GROUP_CONCAT(tm.message ORDER BY tm.rdate ASC SEPARATOR ' ---MSG_SEP--- ') AS all_messages_text,
    GROUP_CONCAT(DATE_FORMAT(tm.rdate, '%Y-%m-%d %H:%i:%s') ORDER BY tm.rdate ASC SEPARATOR ',') AS message_timestamps,
    GROUP_CONCAT(DATE_FORMAT(tm.rdate, '%Y-%m-%d %H:%i:%s') ORDER BY tm.rdate ASC SEPARATOR ',') AS message_creation_dates,
    GROUP_CONCAT(
        CASE
            WHEN tm.suid IS NOT NULL AND tm.suid != 0 THEN 'Agent'
            WHEN t.supid IS NOT NULL AND t.supid != 0 THEN 'Client'
            WHEN tm.peid IS NOT NULL AND tm.peid != 0 THEN 'Agent'
            ELSE 'Agent'
        END
        ORDER BY tm.rdate ASC SEPARATOR ','
    ) AS message_authors_roles,
    GROUP_CONCAT(tu.descr ORDER BY tu.rdate ASC SEPARATOR ' ---UPD_SEP--- ') AS all_updates_text,
    GROUP_CONCAT(DATE_FORMAT(tu.rdate, '%Y-%m-%d %H:%i:%s') ORDER BY tu.rdate ASC SEPARATOR ',') AS update_timestamps,
    GROUP_CONCAT(DATE_FORMAT(tu.rdate, '%Y-%m-%d %H:%i:%s') ORDER BY tu.rdate ASC SEPARATOR ',') AS update_creation_dates,
    GROUP_CONCAT(
        CASE
            WHEN tu.suid IS NOT NULL AND tu.suid != 0 THEN 'Agent'
            WHEN tu.peid IS NOT NULL AND tu.peid != 0 THEN 'Client'
            WHEN tu.supid IS NOT NULL AND tu.supid != 0 THEN 'Agent'
            ELSE 'Unknown'
        END
        ORDER BY tu.rdate ASC SEPARATOR ','
    ) AS update_authors_roles
FROM
    tickets AS t
LEFT JOIN
    tickets_messages AS tm ON t.tid = tm.tid
LEFT JOIN
    ticket_upd AS tu ON t.tid = tu.tid
WHERE
    t.tdate LIKE '%$filterYear'
    AND t.azienda = '$filterCompany'
GROUP BY
    t.tid
ORDER BY
    t.rdate ASC
SQL;

$stmt = $conn->prepare($sql);
$stmt->execute();
$ticketData = $stmt->fetchAll();

echo "Estratti " . count($ticketData) . " ticket dal database.\n";

$conn = null;

// ----------------------------------------------------
// 3. Elaborazione Dati: Estrazione Webform e Preparazione per Excel
// ----------------------------------------------------

$processedTickets = [];

foreach ($ticketData as $ticket) {
    $firstMessage = '';
    $messagesArray = explode(' ---MSG_SEP--- ', $ticket['all_messages_text'] ?? '');
    if (!empty($messagesArray)) {
        $firstMessage = $messagesArray[0];
    }

    // Inizializza i campi del webform a null
    $requesterEmail = null;
    $requesterPhone = null;
    $pcIdentifier = null;
    $usernameSystem = null;
    $softwareDescription = null;

    // Espressioni regolari per estrarre i dati dal webform
    if (preg_match('/<br\/>email\s*:\s*([^<]+)<br\/>/i', $firstMessage, $matches)) {
        $requesterEmail = trim($matches[1]);
    }
    if (preg_match('/<br\/>Contatto Telefonico\s*:\s*([^<]+)<br\/>/i', $firstMessage, $matches)) {
        $requesterPhone = trim($matches[1]);
    }
    if (preg_match('/<br\/>Identificativo PC\s*:\s*([^<]+)<br\/>/i', $firstMessage, $matches)) {
        $pcIdentifier = trim($matches[1]);
    }
    if (preg_match('/<br\/>Utenza\s*:\s*([^<]+)<br\/>/i', $firstMessage, $matches)) {
        $usernameSystem = trim($matches[1]);
    }
    if (preg_match('/<br\/>Descrizione\s*:\s*([^<]+)/i', $firstMessage, $matches)) {
        $softwareDescription = trim(str_replace('<br/>', '', $matches[1]));
    }

    // Aggiungi i dati estratti al ticket corrente
    $ticket['requester_email'] = $requesterEmail;
    $ticket['requester_phone'] = $requesterPhone;
    $ticket['pc_identifier'] = $pcIdentifier;
    $ticket['username_system'] = $usernameSystem;
    $ticket['software_description'] = $softwareDescription;

    // ----------------------------------------------------
    // Trasformazione dei campi problematici in JSON
    // ----------------------------------------------------

    // Trasforma all_messages_text in array strutturato
    if (!empty($ticket['all_messages_text'])) {
        $messagesArray = explode(' ---MSG_SEP--- ', $ticket['all_messages_text']);
        $timestampsArray = !empty($ticket['message_timestamps']) ? explode(',', $ticket['message_timestamps']) : [];
        $creationDatesArray = !empty($ticket['message_creation_dates']) ? explode(',', $ticket['message_creation_dates']) : [];
        $authorsArray = !empty($ticket['message_authors_roles']) ? explode(',', $ticket['message_authors_roles']) : [];

        $messagesStructured = [];
        foreach ($messagesArray as $index => $message) {
            $messagesStructured[] = [
                'message' => trim($message),
                'timestamp' => $timestampsArray[$index] ?? null,
                'creation_date' => $creationDatesArray[$index] ?? null,
                'author_role' => $authorsArray[$index] ?? null
            ];
        }
        $ticket['all_messages_json'] = $messagesStructured;
    } else {
        $ticket['all_messages_json'] = [];
    }

    // Trasforma all_updates_text in array strutturato
    if (!empty($ticket['all_updates_text'])) {
        $updatesArray = explode(' ---UPD_SEP--- ', $ticket['all_updates_text']);
        $updateTimestampsArray = !empty($ticket['update_timestamps']) ? explode(',', $ticket['update_timestamps']) : [];
        $updateCreationDatesArray = !empty($ticket['update_creation_dates']) ? explode(',', $ticket['update_creation_dates']) : [];
        $updateAuthorsArray = !empty($ticket['update_authors_roles']) ? explode(',', $ticket['update_authors_roles']) : [];

        $updatesStructured = [];
        foreach ($updatesArray as $index => $update) {
            $updatesStructured[] = [
                'update' => trim($update),
                'timestamp' => $updateTimestampsArray[$index] ?? null,
                'creation_date' => $updateCreationDatesArray[$index] ?? null,
                'author_role' => $updateAuthorsArray[$index] ?? null
            ];
        }
        $ticket['all_updates_json'] = $updatesStructured;
    } else {
        $ticket['all_updates_json'] = [];
    }

    // Rimuovi i campi originali problematici per evitare confusione nel JSONL
    unset($ticket['all_messages_text']);
    unset($ticket['message_timestamps']);
    unset($ticket['message_creation_dates']);
    unset($ticket['message_authors_roles']);
    unset($ticket['all_updates_text']);
    unset($ticket['update_timestamps']);
    unset($ticket['update_creation_dates']);
    unset($ticket['update_authors_roles']);

    $processedTickets[] = $ticket;
}

echo "Elaborazione dati webform completata.\n";

// ----------------------------------------------------
// 4. Esportazione in formato JSONL (JSON Lines)
// ----------------------------------------------------

if (empty($processedTickets)) {
    echo "Nessun dato da esportare in JSONL.\n";
    exit;
}

$jsonlFileName = 'ticket_data_for_analysis.jsonl';

// Apri il file JSONL in scrittura
$jsonlFile = fopen($jsonlFileName, 'w');

// Scrivi ogni ticket come una riga JSON separata
foreach ($processedTickets as $ticket) {
    $jsonLine = json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite($jsonlFile, $jsonLine . "\n");
}

// Chiudi il file
fclose($jsonlFile);

echo "Dati esportati con successo in $jsonlFileName\n";

// ----------------------------------------------------
// 4.5. Verifica del file JSONL generato
// ----------------------------------------------------

echo "Verifica del file JSONL generato...\n";

// Statistiche del file
$fileSize = filesize($jsonlFileName);
echo "- Dimensione file: " . number_format($fileSize / 1024, 2) . " KB\n";

// Conta le righe del file (ogni riga è un ticket)
$lineCount = 0;
$handle = fopen($jsonlFileName, 'r');
while (($line = fgets($handle)) !== FALSE) {
    $lineCount++;
}
fclose($handle);
echo "- Numero di ticket: $lineCount\n";

// Verifica la prima riga per controllare la struttura JSON
$handle = fopen($jsonlFileName, 'r');
$firstLine = fgets($handle);
fclose($handle);

if ($firstLine) {
    $firstTicket = json_decode(trim($firstLine), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "- Formato JSON: ✓ Valido\n";
        echo "- Numero di campi per ticket: " . count($firstTicket) . "\n";

        // Verifica che i campi principali siano presenti
        $requiredFields = ['ticket_id', 'all_messages_json', 'all_updates_json'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $firstTicket)) {
                $missingFields[] = $field;
            }
        }

        if (empty($missingFields)) {
            echo "- Campi richiesti: ✓ Tutti presenti\n";
        } else {
            echo "- Campi richiesti: ✗ Mancanti: " . implode(', ', $missingFields) . "\n";
        }        // Verifica che i JSON interni siano validi
        if (isset($firstTicket['all_messages_json'])) {
            if (is_array($firstTicket['all_messages_json'])) {
                echo "- Array messaggi: ✓ Valido (contiene " . count($firstTicket['all_messages_json']) . " messaggi)\n";
            } else {
                echo "- Array messaggi: ✗ Non è un array\n";
            }
        }

        if (isset($firstTicket['all_updates_json'])) {
            if (is_array($firstTicket['all_updates_json'])) {
                echo "- Array aggiornamenti: ✓ Valido (contiene " . count($firstTicket['all_updates_json']) . " aggiornamenti)\n";
            } else {
                echo "- Array aggiornamenti: ✗ Non è un array\n";
            }
        }
    } else {
        echo "- Formato JSON: ✗ Errore - " . json_last_error_msg() . "\n";
    }
}

echo "Verifica completata. File pronto per l'upload.\n\n";

try {
    // ----------------------------------------------------
    // 5. Caricamento del file su Google Cloud Storage
    // ----------------------------------------------------

    echo "Avvio caricamento su Google Cloud Storage...\n";

    // Configurazione Google Cloud Storage
    $keyFilePath = __DIR__ . '/../keys/service-account.json';
    // Carica le variabili d'ambiente
    require_once __DIR__ . '/../vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    $projectId = $_ENV['VERTEX_AI_PROJECT_ID'] ?? 'your-gcp-project-id';
    $bucketName = $_ENV['VERTEX_AI_BUCKET_NAME'] ?? 'your-bucket-name';

    // Inizializa il client Storage
    $storage = new StorageClient([
        'projectId' => $projectId,
        'keyFilePath' => $keyFilePath
    ]);

    // Ottieni il bucket
    $bucket = $storage->bucket($bucketName);

    // Genera un nome file con timestamp per evitare sovrascritture
    $timestamp = date('Y-m-d_H-i-s');
    $gcsFileName = "ticket_data_for_analysis_{$timestamp}.jsonl";

    // Carica il file
    $object = $bucket->upload(
        fopen($jsonlFileName, 'r'),
        [
            'name' => $gcsFileName,
            'metadata' => [
                'contentType' => 'application/jsonl',
                'cacheControl' => 'public, max-age=3600',
                'metadata' => [
                    'uploaded_by' => 'exportTicketForVertex_script',
                    'upload_date' => date('c'),
                    'source' => 'ticket_export_script'
                ]
            ]
        ]
    );

    echo "File caricato con successo su Google Cloud Storage!\n";
    echo "Nome file nel bucket: $gcsFileName\n";
    echo "Bucket: $bucketName\n";
    echo "Progetto: $projectId\n";

    // Opzionale: rimuovi il file locale dopo il caricamento
    if (file_exists($jsonlFileName)) {
        unlink($jsonlFileName);
        echo "File locale rimosso dopo il caricamento.\n";
    }
} catch (Throwable $e) {
    echo "Errore durante l'esportazione o il caricamento: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
