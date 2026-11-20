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
        .signature-space.has-image { height: 76px; }
        .signature-space img { max-height: 72px; max-width: 95%; }
        .signature-line { border-top: 1px solid #173448; padding-top: 7px; }
        .signature-name { font-size: 10px; font-weight: bold; line-height: 1.4; word-wrap: break-word; }
        .position { font-size: 9px; line-height: 1.4; color: #52606b; word-wrap: break-word; }
        .footer { width: 100%; margin-top: 12px; }
        .footer td { vertical-align: middle; }
        .qr { height: 86px; width: 86px; }
        .verification { font-size: 9px; text-align: right; color: #52606b; }
        .notice { font-size: 9px; margin: 8px 0 0; color: #6f695c; }
        .compact h1 { font-size: 24px; margin: 12px 0; }
        .compact .beneficiary { font-size: 23px; margin: 12px 0; }
        .compact .text { font-size: 12px; line-height: 1.5; }
        .compact .issued { margin-top: 8px; }
        .compact .signers { margin-top: 8px; }
        .compact .signature-space { height: 26px; }
        .compact .signature-space.has-image { height: 52px; }
        .compact .signature-space img { max-height: 48px; }
        .compact .signature-line { padding-top: 4px; }
        .compact .signature-name { font-size: 9px; }
        .compact .position { font-size: 8px; }
        .compact .footer { margin-top: 2px; }
        .compact .qr { height: 71px; width: 71px; }
        @if($context->origen === 'practica')
        .practice-frame { border: 3px solid #173448; padding: 9px; }
        .practice-certificate { padding: 22px 44px 16px; border-color: #ba9750; }
        .practice-header { text-align: center; }
        .practice-header .logo { height: 60px; }
        .practice-brand { font-size: 16px; font-weight: bold; letter-spacing: 1px; margin: 8px 0 0; }
        .practice-rule { width: 90px; border-top: 2px solid #ba9750; margin: 12px auto 0; }
        .practice-certificate h1 { font-size: 27px; letter-spacing: 1px; margin: 20px 0 14px; color: #173448; }
        .practice-certificate .intro { font-size: 14px; margin: 0 0 9px; color: #52606b; }
        .practice-certificate .beneficiary { font-family: "DejaVu Serif", serif; font-size: 34px; margin: 0 18px 17px; padding: 0 0 12px; border-bottom: 1px solid #ba9750; }
        .practice-certificate .text { font-size: 14px; line-height: 1.8; margin: 0 20px; }
        .practice-certificate .issued { font-size: 13px; margin: 17px 0 0; }
        .practice-certificate .signers { margin-top: 18px; }
        .practice-certificate .signature-space { height: 26px; }
        .practice-certificate .signature-space.has-image { height: 52px; }
        .practice-certificate .signature-space img { max-height: 48px; }
        .practice-certificate .footer { margin-top: 6px; }
        .practice-certificate .qr { height: 71px; width: 71px; }
        .practice-certificate.long-message { padding-top: 12px; padding-bottom: 10px; }
        .practice-certificate.long-message .logo { height: 40px; }
        .practice-certificate.long-message .practice-brand { font-size: 14px; margin-top: 5px; }
        .practice-certificate.long-message .practice-rule { margin-top: 8px; }
        .practice-certificate.long-message h1 { font-size: 23px; margin: 12px 0 8px; }
        .practice-certificate.long-message .beneficiary { font-size: 27px; margin-bottom: 9px; padding-bottom: 8px; }
        .practice-certificate.long-message .text { font-size: 11px; line-height: 1.4; }
        .practice-certificate.long-message .issued { margin-top: 9px; }
        .practice-certificate.long-message .signers { margin-top: 8px; }
        .practice-certificate.long-message .signature-space { height: 18px; }
        .practice-certificate.long-message .signature-space.has-image { height: 36px; }
        .practice-certificate.long-message .signature-space img { max-height: 32px; }
        .practice-certificate.long-message .footer { margin-top: 2px; }
        .practice-certificate.long-message .qr { height: 54px; width: 54px; }
        @endif
    </style>
</head>
<body>
<div class="frame {{ $context->origen === 'practica' ? 'practice-frame' : '' }}"><main class="certificate {{ count($signers) >= 4 || (in_array($context->origen, ['ponente_sesion', 'reconocimiento'], true) && isset($context->texto_reconocimiento)) || isset($context->texto_practica) ? 'compact' : '' }} {{ $context->origen === 'practica' ? 'practice-certificate'.(mb_strlen($customMessage) > 800 ? ' long-message' : '') : '' }}">
    @if($context->origen === 'practica')
    <div class="practice-header">
        <img class="logo" src="{{ $logoDataUri }}" alt="Escudo municipal">
        <p class="practice-brand">Municipalidad Distrital de Morales</p>
        <div class="practice-rule"></div>
    </div>
    @else
    <table class="header"><tr>
        <td width="12%"><img class="logo" src="{{ $logoDataUri }}" alt="Escudo municipal"></td>
        <td class="brand">Municipalidad Distrital de Morales</td>
        <td class="type">{{ $context->tipo_certificado }}</td>
    </tr></table>
    @endif
    <h1>{{ $context->origen === 'practica' ? 'CERTIFICADO DE PRÁCTICAS PREPROFESIONALES' : 'RECONOCIMIENTO' }}</h1>
    <p class="intro">{{ $context->origen === 'practica' ? 'Otorgado a:' : 'Se otorga el presente certificado a:' }}</p>
    <h2 class="beneficiary">{{ trim(implode(' ', array_filter([$context->nombres, $context->apellido_paterno, $context->apellido_materno]))) }}</h2>
    @if(in_array($context->origen, ['ponente_sesion', 'reconocimiento'], true) && isset($context->texto_reconocimiento))
        <p class="text custom-message"><strong>Motivo:</strong> {{ $context->texto_reconocimiento['motivo'] }}</p>
    @endif
    <p class="text custom-message">{{ $customMessage }}</p>
    @if($context->origen === 'practica')
        <p class="issued">Morales, {{ \Illuminate\Support\Carbon::parse($certificate->fecha_emision ?? $certificate->fecha_generacion ?? now())->locale('es')->translatedFormat('j \d\e F \d\e Y') }}</p>
    @endif
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
    @endif
    @if($context->origen !== 'practica' && $certificate !== null && $certificate->fecha_emision !== null)
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
                    @if(isset($signatureImages[$signer['id_persona_trabajador'] ?? 0]))
                        <div class="signature-space has-image">
                            <img src="{{ $signatureImages[$signer['id_persona_trabajador']] }}" alt="Firma">
                        </div>
                    @else
                        <div class="signature-space"></div>
                    @endif
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
