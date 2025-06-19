-- Query BigQuery semplificata per analisi ticket Labor Medical 2023

SELECT
    ticket_id,
    company_name,
    subject,
    ticket_type,
    channel,
    status_stadium,
    open_date,
    close_date,
    assigned_user_gest,
    requester_email,
    requester_phone,
    pc_identifier,
    username_system,
    software_description,
    all_messages_json,
    all_updates_json
FROM
    `supporto-ift.dati_ticket.dati_ticket_labor_medical_2023`
WHERE
    open_date IS NOT NULL
    AND close_date IS NOT NULL
ORDER BY open_date DESC;