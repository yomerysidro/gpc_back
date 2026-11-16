<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificado de {{ $context->tipo_certificado }}</title>
    <style>
        @page { margin: 24px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #173448; margin: 0; }
        .frame { border: 3px solid #173448; padding: 10px; }
        .certificate { border: 1px solid #ba9750; padding: 20px 35px 14px; text-align: center; }
        .header { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: middle; }
        .brand { text-align: left; font-size: 13px; }
        .logo { height: 65px; }
        .type { text-align: right; font-size: 12px; color: #826025; }
        h1 { font-size: 27px; letter-spacing: 1px; margin: 24px 0 18px; }
        .intro { font-size: 15px; }
        .beneficiary { font-size: 25px; margin: 22px 0; padding-bottom: 10px; border-bottom: 1px solid #ba9750; }
        .text { font-size: 14px; line-height: 1.9; margin: 0 22px; }
        .custom-message { white-space: pre-wrap; overflow-wrap: break-word; }
        .issued { font-size: 12px; margin-top: 15px; }
        .signers { margin-top: 18px; }
        .signature-row { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 20px 0; page-break-inside: avoid; }
        .signature-row td { text-align: center; vertical-align: top; padding: 0 0 12px; }
        .signature-block { max-width: 270px; margin: 0 auto; page-break-inside: avoid; }
        .signature-space { height: 42px; }
        .signature-line { border-top: 1px solid #173448; padding-top: 7px; }
        .signature-name { font-size: 10px; font-weight: bold; line-height: 1.4; word-wrap: break-word; }
        .position { font-size: 9px; line-height: 1.4; color: #52606b; word-wrap: break-word; }
        .footer { width: 100%; margin-top: 18px; }
        .footer td { vertical-align: middle; }
        .qr { height: 80px; width: 80px; }
        .verification { font-size: 9px; text-align: right; color: #52606b; }
        .notice { font-size: 9px; margin: 8px 0 0; color: #6f695c; }
        .compact h1 { font-size: 24px; margin: 12px 0; }
        .compact .beneficiary { font-size: 23px; margin: 12px 0; }
        .compact .text { font-size: 12px; line-height: 1.5; }
        .compact .issued { margin-top: 8px; }
        .compact .signers { margin-top: 8px; }
        .compact .signature-space { height: 26px; }
        .compact .signature-line { padding-top: 4px; }
        .compact .signature-name { font-size: 9px; }
        .compact .position { font-size: 8px; }
        .compact .footer { margin-top: 6px; }
        .compact .qr { height: 60px; width: 60px; }
    </style>
</head>
<body>
<div class="frame"><main class="certificate {{ count($signers) >= 4 || ($context->origen === 'ponente_sesion' && isset($context->texto_reconocimiento)) ? 'compact' : '' }}">
    <table class="header"><tr>
        <td width="12%"><img class="logo" src="{{ $logoDataUri }}" alt="Escudo municipal"></td>
        <td class="brand">Municipalidad Distrital de Morales</td>
        <td class="type">{{ $context->tipo_certificado }}</td>
    </tr></table>
    <h1>{{ in_array($context->origen, ['reconocimiento', 'ponente_actividad', 'ponente_sesion'], true) ? 'RECONOCIMIENTO' : 'CONSTANCIA DE PRÁCTICAS' }}</h1>
    <p class="intro">{{ $context->origen === 'practica' ? 'Se expide la presente constancia a:' : 'Se otorga el presente certificado a:' }}</p>
    <h2 class="beneficiary">{{ trim(implode(' ', array_filter([$context->nombres, $context->apellido_paterno, $context->apellido_materno]))) }}</h2>
    @if($context->origen === 'ponente_sesion' && isset($context->texto_reconocimiento))
        <p class="text custom-message"><strong>Motivo:</strong> {{ $context->texto_reconocimiento['motivo'] }}</p>
    @endif
    <p class="text custom-message">{{ $customMessage }}</p>
    @if(in_array($context->origen, ['ponente_actividad', 'ponente_sesion'], true))
        @if($context->origen === 'ponente_sesion')
            <p class="issued">Sesión: {{ $context->sesion }} · {{ $context->fecha_sesion }}</p>
        @endif
        <p class="issued">Actividad: {{ $context->actividad }}</p>
        <p class="issued">Del {{ \Illuminate\Support\Carbon::parse($context->datos_origen['fecha_inicio_actividad'])->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($context->datos_origen['fecha_fin_actividad'])->format('d/m/Y') }}</p>
    @elseif($context->origen === 'reconocimiento')
        <p class="issued">{{ \Illuminate\Support\Carbon::parse($context->datos_origen['fecha_reconocimiento'])->format('d/m/Y') }}</p>
        @if(!empty($context->datos_origen['descripcion']))
            <p class="text">{{ $context->datos_origen['descripcion'] }}</p>
        @endif
    @else
        <p class="issued">{{ $context->datos_origen['institucion'] }} · {{ $context->datos_origen['carrera'] }}</p>
    @endif
    @if($certificate !== null && $certificate->fecha_emision !== null)
        <p class="issued">Morales, {{ \Illuminate\Support\Carbon::parse($certificate->fecha_emision)->format('d/m/Y') }}</p>
    @endif
    @php
        $signatureBlocks = $signers === [] ? [null] : $signers;
        $signatureColumns = min(4, count($signatureBlocks));
    @endphp
    <div class="signers">
        @foreach(array_chunk($signatureBlocks, $signatureColumns) as $signatureRow)
        <table class="signature-row">
        <tr>
        @foreach($signatureRow as $signer)
            <td width="{{ 100 / count($signatureRow) }}%">
                <div class="signature-block">
                    <div class="signature-space"></div>
                    <div class="signature-line">
                        <div class="signature-name">{{ $signer['nombre'] ?? "\u{00A0}" }}</div>
                        <div class="position">{{ $signer['cargo'] ?? "\u{00A0}" }}</div>
                    </div>
                </div>
            </td>
        @endforeach
        </tr>
        </table>
        @endforeach
    </div>
    @if($code !== null && $qrDataUri !== null)
        <table class="footer"><tr>
            <td><img class="qr" src="{{ $qrDataUri }}" alt="QR de verificación documental"></td>
            <td class="verification">Código de verificación<br>{{ $code }}</td>
        </tr></table>
    @endif
    @if(!($administrative ?? false) && $certificate !== null)
        <p class="notice">Certificado de demostración · Sin firma digital institucional</p>
    @endif
</main></div>
</body>
</html>
