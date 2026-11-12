<?php

$developmentClosureEnabled = (bool) env('CERTIFICATION_DEVELOPMENT_CLOSURE_ENABLED', false);
$sessionTypes = trim((string) env('CERTIFICATION_DEVELOPMENT_SESSION_TYPE_IDS', ''));
$preparationTypes = trim((string) env('CERTIFICATION_PREPARATION_SESSION_TYPE_IDS', ''));

return [
    'stage_b' => [
        'demo_enabled' => (bool) env('CERTIFICATION_STAGE_B_DEMO_ENABLED', false),
    ],
    'preparation' => [
        'session_type_ids' => preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $preparationTypes)
            ? array_map('intval', explode(',', $preparationTypes)) : [],
    ],
    'documents' => [
        'verification_base_url' => env('CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL'),
        'allow_http_lan' => (bool) env('CERTIFICATION_ALLOW_HTTP_LAN_VERIFICATION', false),
        'public_beneficiary_name' => (bool) env('CERTIFICATION_PUBLIC_BENEFICIARY_NAME', false),
    ],
    // Configure only from an approved institutional policy, never from HTTP input.
    'development_closure_enabled' => $developmentClosureEnabled,
    'development_schema_authorized' => (bool) env('CERTIFICATION_DEVELOPMENT_SCHEMA_AUTHORIZED', false),
    'development_session_type_ids' => preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $sessionTypes)
        ? array_map('intval', explode(',', $sessionTypes)) : [],
    'development_connection' => [
        'authorized' => (bool) env('CERTIFICATION_DEVELOPMENT_CONNECTION_AUTHORIZED', false),
        'connection' => env('CERTIFICATION_DEVELOPMENT_CONNECTION'),
        'database' => env('CERTIFICATION_DEVELOPMENT_DATABASE'),
        'username' => env('CERTIFICATION_DEVELOPMENT_USERNAME'),
        'server_address' => env('CERTIFICATION_DEVELOPMENT_SERVER_ADDRESS'),
        'server_port' => env('CERTIFICATION_DEVELOPMENT_SERVER_PORT'),
    ],
    'session_closure' => $developmentClosureEnabled ? [
        'scope' => 'development',
        'reference' => 'DESARROLLO PROVISIONAL: cierre de integracion, sin aprobacion municipal',
        'final_state' => 'Finalizada',
        'open_states' => ['Activo'],
        'active_participation_states' => ['Activo'],
        'eligible_attendance_states' => ['PRESENTE'],
        'ineligible_attendance_states' => ['AUSENTE'],
        'require_entry' => false,
        'require_exit' => false,
        'regularizations' => 'not_applicable',
    ] : null,
    // Inactive proposal. Copy to session_closure only after the municipal decisions are approved.
    'session_closure_proposal' => [
        'reference' => null,
        'final_state' => 'Finalizada',
        'open_states' => ['Activo', 'activo'],
        'active_participation_states' => ['Activo'],
        'eligible_attendance_states' => ['PRESENTE'],
        'ineligible_attendance_states' => ['AUSENTE'],
        'require_entry' => false,
        'require_exit' => false,
        'regularizations' => null,
    ],
    'eligibility' => ['sesion' => [], 'actividad' => []],

    // Operational labels for reporting only; these do not authorize closure or certification.
    'attendance_summary' => [
        'active_participation_states' => ['Activo'],
        'present_states' => ['PRESENTE'],
        'absent_states' => ['AUSENTE'],
    ],
];
