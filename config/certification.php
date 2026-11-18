<?php

$developmentClosureEnabled = (bool) env('CERTIFICATION_DEVELOPMENT_CLOSURE_ENABLED', false);
$approvedClosure = env('CERTIFICATION_SESSION_CLOSURE_POLICY');
$approvedClosure = is_string($approvedClosure) && trim($approvedClosure) !== ''
    ? json_decode($approvedClosure, true) : null;
$sessionTypes = trim((string) env('CERTIFICATION_DEVELOPMENT_SESSION_TYPE_IDS', ''));
$preparationTypes = trim((string) env('CERTIFICATION_PREPARATION_SESSION_TYPE_IDS', ''));
$practiceCompletionState = trim((string) env('CERTIFICATION_PRACTICE_COMPLETION_STATE', ''));
if ($practiceCompletionState === '' || mb_strlen($practiceCompletionState) > 100
    || mb_strtolower($practiceCompletionState) === 'activo' || preg_match('/[\x00-\x1F\x7F]/', $practiceCompletionState)) {
    $practiceCompletionState = 'finalizado';
}

return [
    'origins' => [
        'reconocimiento' => [
            'academic_enabled' => (bool) env('CERTIFICATION_RECOGNITION_ACADEMIC_ENABLED', false),
            'academic_ids' => array_map('intval', array_filter(explode(',', (string) env('CERTIFICATION_RECOGNITION_ACADEMIC_IDS', '')), fn (string $id): bool => preg_match('/^[1-9][0-9]*$/D', $id) === 1)),
            'academic_states' => array_values(array_filter(explode('|', (string) env('CERTIFICATION_RECOGNITION_ACADEMIC_STATES', '')))),
            'reference' => env('CERTIFICATION_RECOGNITION_POLICY_REFERENCE'),
            'eligible_states' => array_values(array_filter(explode('|', (string) env('CERTIFICATION_RECOGNITION_APPROVED_STATES', '')))),
            'type_name' => 'Reconocimiento',
        ],
        'practica' => [
            'reference' => env('CERTIFICATION_PRACTICE_POLICY_REFERENCE'),
            'completion_state' => $practiceCompletionState,
            'supervision_required' => (bool) env('CERTIFICATION_PRACTICE_SUPERVISION_REQUIRED', false),
            'eligible_states' => array_values(array_filter(explode('|', (string) env('CERTIFICATION_PRACTICE_APPROVED_STATES', '')))),
            'supervisor_states' => array_values(array_filter(explode('|', (string) env('CERTIFICATION_PRACTICE_SUPERVISOR_STATES', '')))),
            'type_name' => 'Prácticas',
        ],
    ],
    'stage_b' => [
        'demo_enabled' => (bool) env('CERTIFICATION_STAGE_B_DEMO_ENABLED', false),
    ],
    'preparation' => [
        'execution_timeout_seconds' => 120,
        'session_type_ids' => preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $preparationTypes)
            ? array_map('intval', explode(',', $preparationTypes)) : [],
    ],
    'documents' => [
        'verification_base_url' => env('CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL'),
        'allow_http_lan' => (bool) env('CERTIFICATION_ALLOW_HTTP_LAN_VERIFICATION', false),
        'public_beneficiary_name' => (bool) env('CERTIFICATION_PUBLIC_BENEFICIARY_NAME', false),
    ],
    'public_lookup' => [
        'pdf_policy_reference' => env('CERTIFICATION_PUBLIC_PDF_POLICY_REFERENCE'),
        'pdf_codes' => array_values(array_filter(explode('|', (string) env('CERTIFICATION_PUBLIC_PDF_CODES', '')))),
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
    ] : $approvedClosure,
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
