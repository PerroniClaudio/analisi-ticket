-- Query BigQuery per analisi ticket - EXAMPLE
-- NOTA: Sostituire 'your-gcp-project-id.your_dataset.your_table_name' con i propri valori
-- Basata sui dati esportati dal file JSONL

SELECT
    -- Identificativi del ticket
    ticket_id,
    opener_id,
    company_id,

-- Informazioni azienda e gestione
company_name,
involved_person,
managing_group_id,
managing_group_name,
assigned_user_gest,

-- Dettagli del ticket
subject, ticket_type, channel, status_stadium, closing_comment,

-- Tempi e date
open_date,
close_date,
TIMESTAMP_DIFF (
    CAST(close_date AS TIMESTAMP),
    CAST(open_date AS TIMESTAMP),
    MINUTE
) AS resolution_time_minutes,
TIMESTAMP_DIFF (
    CAST(close_date AS TIMESTAMP),
    CAST(open_date AS TIMESTAMP),
    HOUR
) AS resolution_time_hours,
TIMESTAMP_DIFF (
    CAST(close_date AS TIMESTAMP),
    CAST(open_date AS TIMESTAMP),
    DAY
) AS resolution_time_days,

-- Calcolo del first response time
-- Estrae il primo timestamp dai messaggi dove l'autore è 'Agent'
(
    SELECT MIN(DATETIME(timestamp))
    FROM UNNEST (
            JSON_EXTRACT_ARRAY (
                TO_JSON_STRING (all_messages_json)
            )
        ) AS message_obj
    WHERE
        JSON_EXTRACT_SCALAR (message_obj, '$.author_role') = 'Agent'
        AND JSON_EXTRACT_SCALAR (message_obj, '$.timestamp') IS NOT NULL
) AS first_agent_response_time,

-- Calcolo del first response time in minuti
DATETIME_DIFF (
    (
        SELECT MIN(DATETIME(timestamp))
        FROM UNNEST (
                JSON_EXTRACT_ARRAY (
                    TO_JSON_STRING (all_messages_json)
                )
            ) AS message_obj
        WHERE
            JSON_EXTRACT_SCALAR (message_obj, '$.author_role') = 'Agent'
            AND JSON_EXTRACT_SCALAR (message_obj, '$.timestamp') IS NOT NULL
    ),
    open_date,
    MINUTE
) AS first_response_time_minutes,

-- Conteggi di messaggi e aggiornamenti
JSON_ARRAY_LENGTH(
    TO_JSON_STRING (all_messages_json)
) AS total_messages_count,
JSON_ARRAY_LENGTH(
    TO_JSON_STRING (all_updates_json)
) AS total_updates_count,

-- Conteggio messaggi per ruolo
(
    SELECT COUNT(*)
    FROM UNNEST (
            JSON_EXTRACT_ARRAY (
                TO_JSON_STRING (all_messages_json)
            )
        ) AS message_obj
    WHERE
        JSON_EXTRACT_SCALAR (message_obj, '$.author_role') = 'Agent'
) AS agent_messages_count,
(
    SELECT COUNT(*)
    FROM UNNEST (
            JSON_EXTRACT_ARRAY (
                TO_JSON_STRING (all_messages_json)
            )
        ) AS message_obj
    WHERE
        JSON_EXTRACT_SCALAR (message_obj, '$.author_role') = 'Client'
) AS client_messages_count,

-- Dati del richiedente (estratti dal webform)
requester_email,
requester_phone,
pc_identifier,
username_system,
software_description,

-- Dati strutturati per analisi avanzate
all_messages_json,
all_updates_json
FROM
    `your-gcp-project-id.your_dataset.your_table_name`
WHERE
    open_date IS NOT NULL
    AND close_date IS NOT NULL
    AND ticket_id IS NOT NULL
ORDER BY open_date DESC;

-- Query aggiuntiva per analisi delle performance
-- Metriche di performance per categoria
SELECT
    ticket_type,
    channel,
    COUNT(*) AS total_tickets,
    AVG(
        DATETIME_DIFF (close_date, open_date, MINUTE)
    ) AS avg_resolution_minutes,
    MIN(
        DATETIME_DIFF (close_date, open_date, MINUTE)
    ) AS min_resolution_minutes,
    MAX(
        DATETIME_DIFF (close_date, open_date, MINUTE)
    ) AS max_resolution_minutes,
    STDDEV (
        DATETIME_DIFF (close_date, open_date, MINUTE)
    ) AS std_resolution_minutes,

-- Percentili di risoluzione
APPROX_QUANTILES (
    DATETIME_DIFF (close_date, open_date, MINUTE),
    100
) [OFFSET(50)] AS median_resolution_minutes,
APPROX_QUANTILES (
    DATETIME_DIFF (close_date, open_date, MINUTE),
    100
) [OFFSET(75)] AS p75_resolution_minutes,
APPROX_QUANTILES (
    DATETIME_DIFF (close_date, open_date, MINUTE),
    100
) [OFFSET(90)] AS p90_resolution_minutes,

-- Analisi first response time
AVG(
    DATETIME_DIFF (
        (
            SELECT MIN(DATETIME(timestamp))
            FROM UNNEST (
                    JSON_EXTRACT_ARRAY (
                        TO_JSON_STRING (all_messages_json)
                    )
                ) AS message_obj
            WHERE
                JSON_EXTRACT_SCALAR (message_obj, '$.author_role') = 'Agent'
                AND JSON_EXTRACT_SCALAR (message_obj, '$.timestamp') IS NOT NULL
        ),
        open_date,
        MINUTE
    )
) AS avg_first_response_minutes,

-- Analisi messaggi
AVG(
    JSON_ARRAY_LENGTH(
        TO_JSON_STRING (all_messages_json)
    )
) AS avg_messages_per_ticket,
AVG(
    JSON_ARRAY_LENGTH(
        TO_JSON_STRING (all_updates_json)
    )
) AS avg_updates_per_ticket
FROM
    `your-gcp-project-id.your_dataset.your_table_name`
WHERE
    open_date IS NOT NULL
    AND close_date IS NOT NULL
    AND ticket_id IS NOT NULL
GROUP BY
    ticket_type,
    channel
ORDER BY total_tickets DESC;

-- Query per analisi temporale (trend mensili)
SELECT
    EXTRACT (
        YEAR
        FROM open_date
    ) AS year,
    EXTRACT (
        MONTH
        FROM open_date
    ) AS month,
    COUNT(*) AS tickets_opened,
    COUNT(
        CASE
            WHEN close_date IS NOT NULL THEN 1
        END
    ) AS tickets_closed,
    AVG(
        DATETIME_DIFF (close_date, open_date, MINUTE)
    ) AS avg_resolution_minutes,

-- Distribuzione per canale
COUNTIF (channel = 'email') AS email_tickets,
COUNTIF (channel = 'phone') AS phone_tickets,
COUNTIF (channel = 'web') AS web_tickets,

-- Distribuzione per tipo
COUNTIF (ticket_type = 'bug') AS bug_tickets,
COUNTIF (ticket_type = 'feature') AS feature_tickets,
COUNTIF (ticket_type = 'support') AS support_tickets
FROM
    `your-gcp-project-id.your_dataset.your_table_name`
WHERE
    open_date IS NOT NULL
GROUP BY
    year,
    month
ORDER BY year, month;