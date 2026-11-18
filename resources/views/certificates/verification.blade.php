<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificación documental · Municipalidad Distrital de Morales</title>
<style>body{font-family:Arial,sans-serif;background:#f3f5f7;color:#173448;margin:0;padding:24px}main{max-width:900px;margin:auto;background:white;border-top:5px solid #b79650;padding:24px;border-radius:8px}h1{font-size:24px}dt{font-weight:bold;margin-top:12px}dd{margin:4px 0}.notice{padding:14px;background:#fff4d8;border-left:4px solid #b79650}iframe{width:100%;height:75vh;border:1px solid #d8dee5;margin-top:18px}a{color:#125573}.code{overflow-wrap:anywhere}</style>
</head><body><main>
<p>Municipalidad Distrital de Morales</p><h1>Verificación documental</h1>
@if($verification['demostracion'])<p class="notice">Certificado de demostración · Sin firma digital institucional</p>@endif
<dl><dt>Código</dt><dd class="code">{{ $verification['codigo_verificacion'] }}</dd>
<dt>Estado del certificado</dt><dd>{{ $verification['estado_certificado'] }}</dd>
<dt>Fecha de emisión registrada</dt><dd>{{ $verification['fecha_emision'] }}</dd>
@foreach(['tipo_certificado' => 'Tipo', 'beneficiario' => 'Beneficiario', 'actividad' => 'Actividad', 'sesion' => 'Sesión', 'fecha_sesion' => 'Fecha de la sesión'] as $key => $label)
@if(!empty($verification[$key]))<dt>{{ $label }}</dt><dd>{{ $verification[$key] }}</dd>@endif
@endforeach
<dt>Integridad del archivo</dt><dd>{{ $verification['integridad_verificada'] ? 'Coincide con el registro almacenado' : 'No verificada' }}</dd>
</dl>
@if($verification['puede_ver_documento'])
<a href="{{ $verification['url_documento'] }}" target="_blank" rel="noopener noreferrer">Abrir PDF almacenado</a>
<iframe src="{{ $verification['url_documento'] }}" title="PDF almacenado del certificado"></iframe>
@else
<p class="notice">Documento no disponible para consulta pública.</p>
@endif
<p>Esta consulta comprueba el registro y la integridad del archivo. No acredita firma criptográfica ni emisión institucional.</p>
</main></body></html>
