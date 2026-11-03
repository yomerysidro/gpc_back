<?php

use App\Models\Activity\Activity;
use App\Models\ActivitySession\ActivitySession;
use App\Models\ActivitySignature\ActivitySignature;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\Attendance\Attendance;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\CertificateInquiry\CertificateInquiry;
use App\Models\CertificateSignature\CertificateSignature;
use App\Models\CertificateType\CertificateType;
use App\Models\DocumentType\TipoDocumento;
use App\Models\Institution\Institution;
use App\Models\Participation\Participation;
use App\Models\ParticipationType\ParticipationType;
use App\Models\Person\Persona;
use App\Models\Person_Worker\Person_Worker;
use App\Models\PersonDocument\PersonaDocumento;
use App\Models\PersonSpeaker\PersonaPonente;
use App\Models\Practice\Practice;
use App\Models\Practicing_Individual\Practicing_Individual;
use App\Models\Recognition\Recognition;
use App\Models\Signature\Signature;
use App\Models\Supervisory_internship\Supervisory_internship;

return [
    // Null keeps access denied until the institutional permission scope is confirmed.
    'models' => [
        Activity::class => 'actividades',
        ActivityType::class => 'actividades',
        ActivitySession::class => 'actividades',
        ActivitySpeaker::class => 'ponentes-firmantes',
        Participation::class => 'participantes',
        ParticipationType::class => 'participantes',
        Attendance::class => 'participantes',
        CertificateType::class => 'emision',
        Certificate::class => 'emision',
        CertificateDocument::class => 'emision',
        CertificateSignature::class => 'emision',
        CertificateInquiry::class => 'emision',
        Recognition::class => 'reconocimientos',
        Signature::class => 'ponentes-firmantes',
        ActivitySignature::class => 'ponentes-firmantes',
        Person_Worker::class => 'ponentes-firmantes',
        PersonaPonente::class => 'ponentes-firmantes',
        Practicing_Individual::class => 'practicantes',
        Practice::class => 'practicantes',
        Supervisory_internship::class => 'practicantes',
        Persona::class => ['ponentes-firmantes', 'practicantes'],
        PersonaDocumento::class => ['ponentes-firmantes', 'practicantes'],
        TipoDocumento::class => ['ponentes-firmantes', 'practicantes'],
        Area::class => null,
        Institution::class => null,
    ],
];
