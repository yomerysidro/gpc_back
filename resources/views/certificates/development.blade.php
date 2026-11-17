<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Documento de {{ $context->tipo_certificado }}</title>
    <style>
        @page { margin: 22px; }
        body { font-family: "DejaVu Sans", sans-serif; text-align: center; color: #173448; margin: 0; }
        main { border: 3px solid #173448; padding: 16px 34px; }
        .inner { border: 1px solid #bc9b53; padding: 14px 24px; }
        .logo { height: 72px; }
        .municipality { font-size: 19px; margin: 5px 0; }
        .type { font-size: 26px; color: #173448; margin: 14px 0 6px; }
        .person { font-size: 22px; margin: 14px 0; border-bottom: 1px solid #bc9b53; padding-bottom: 8px; }
        .activity { font-size: 18px; margin: 12px 0; }
        p { font-size: 12px; margin: 7px 0; }
        .notice { color: #9b2424; font-weight: bold; font-size: 12px; }
        .code { font-size: 9px; }
        .signatures { font-size: 10px; margin-top: 15px; padding-top: 8px; border-top: 1px solid #bc9b53; }
        .qr { width: 92px; height: 92px; }
    </style>
</head>
<body>
<main><div class="inner">
    <img class="logo" src="{{ $logoDataUri }}" alt="Escudo municipal del proyecto">
    <p class="municipality">Municipalidad Distrital de Morales</p>
    <h1 class="type">CERTIFICADO DE {{ mb_strtoupper($context->tipo_certificado) }}</h1>
    <p class="notice">DOCUMENTO DE DESARROLLO — SIN EMISIÓN OFICIAL</p>
    <h2 class="person">{{ $context->nombres }} {{ $context->apellido_paterno }} {{ $context->apellido_materno }}</h2>
    <p>Registro administrativo de participación vinculado a:</p>
    <h2 class="activity">{{ $context->actividad }}</h2>
    <p>No se acredita una sesión específica: este registro solo identifica la participación.</p>
    <p>Fecha registrada del certificado: {{ $certificate->fecha_emision }}</p>
    <p>Este documento no acredita asistencia, elegibilidad ni emisión institucional.</p>
    <img class="qr" src="{{ $qrDataUri }}" alt="QR de consulta del registro documental almacenado">
    <p class="code">Código de consulta: {{ $code }}</p>
    <p class="code">El QR consulta el archivo registrado; su integridad no equivale a una firma digital.</p>
    <p class="signatures">Sin firma digital institucional aplicada. No se representan autoridades ni firmas no verificadas.</p>
</div></main>
</body>
</html>