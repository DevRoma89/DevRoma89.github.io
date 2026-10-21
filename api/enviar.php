<?php
/**
 * Recibe los formularios del sitio (contacto, solicitud de socio y newsletter)
 * y los envía por correo a la CEMAP usando mail() de PHP (disponible en cPanel).
 *
 * CONFIGURACIÓN: editar config.php (no este archivo).
 */

// Los avisos de PHP no deben mezclarse con la respuesta JSON
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/config.php';

function responder($ok, $codigo = 200, $mensaje = '') {
    http_response_code($codigo);
    echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 405, 'Método no permitido');
}

// Campo trampa: si un bot lo completó, se simula éxito y no se envía nada
if (!empty($_POST['sitio_web'])) {
    responder(true);
}

// Límite simple por IP: N envíos por hora
$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$dirLimite = sys_get_temp_dir() . '/cemap_formularios';
if (!is_dir($dirLimite)) @mkdir($dirLimite, 0700, true);
$archivoIp = $dirLimite . '/' . hash('sha256', $ip);
$envios = array_filter(
    is_file($archivoIp) ? (array) json_decode((string) file_get_contents($archivoIp), true) : [],
    fn($t) => $t > time() - 3600
);
if (count($envios) >= $config['envios_por_hora']) {
    responder(false, 429, 'Demasiados envíos. Intentá más tarde.');
}

$tipos = [
    'contacto'   => ['asunto' => 'Contacto desde el sitio web', 'requeridos' => ['nombre', 'correo', 'asunto', 'mensaje']],
    'interes'    => ['asunto' => 'Solicitud de interés para asociarse', 'requeridos' => ['razon_social', 'ruc', 'correo', 'telefono', 'contacto_nombre']],
    'socio'      => ['asunto' => 'Ficha completa de socio', 'requeridos' => ['razon_social', 'correo', 'firmante', 'declaracion']],
    'newsletter' => ['asunto' => 'Nueva suscripción al newsletter', 'requeridos' => ['correo']],
];
$tipo = $_POST['tipo'] ?? '';
if (!isset($tipos[$tipo])) {
    responder(false, 400, 'Formulario desconocido');
}

foreach ($tipos[$tipo]['requeridos'] as $campo) {
    if (trim((string) ($_POST[$campo] ?? '')) === '') {
        responder(false, 422, "Falta el campo $campo");
    }
}

$correo = trim((string) $_POST['correo']);
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responder(false, 422, 'Correo inválido');
}

// Arma el cuerpo con todos los campos recibidos
$ignorar = ['tipo', 'sitio_web', 'tipo_id'];
$lineas = [];
foreach ($_POST as $clave => $valor) {
    if (in_array($clave, $ignorar, true) || is_array($valor)) continue;
    $valor = trim(mb_substr((string) $valor, 0, 5000));
    if ($valor === '') continue;
    $etiqueta = mb_convert_case(str_replace('_', ' ', $clave), MB_CASE_TITLE, 'UTF-8');
    $lineas[] = "$etiqueta: $valor";
}
$texto = $tipos[$tipo]['asunto'] . "\n"
    . str_repeat('-', 50) . "\n\n"
    . implode("\n", $lineas) . "\n\n"
    . str_repeat('-', 50) . "\n"
    . 'Enviado el ' . date('d/m/Y H:i') . " desde el sitio web.\n";

// Adjuntos (solo en solicitud de socio)
$adjuntos = [];
if ($tipo === 'socio' && !empty($_FILES['adjuntos']['name'][0])) {
    $permitidos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $cantidad = count($_FILES['adjuntos']['name']);
    if ($cantidad > $config['max_adjuntos']) {
        responder(false, 422, 'Demasiados archivos adjuntos');
    }
    for ($i = 0; $i < $cantidad; $i++) {
        if ($_FILES['adjuntos']['error'][$i] !== UPLOAD_ERR_OK) {
            responder(false, 422, 'Error al subir un archivo');
        }
        $tmp = $_FILES['adjuntos']['tmp_name'][$i];
        $nombre = basename($_FILES['adjuntos']['name'][$i]);
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $mime = $finfo->file($tmp);
        if (!isset($permitidos[$ext]) || $permitidos[$ext] !== $mime) {
            responder(false, 422, 'Tipo de archivo no permitido');
        }
        if (filesize($tmp) > $config['max_mb_adjunto'] * 1024 * 1024) {
            responder(false, 422, 'Archivo demasiado grande');
        }
        $adjuntos[] = [
            'nombre' => preg_replace('/[^\w.\- ]+/u', '_', $nombre),
            'mime' => $mime,
            'datos' => file_get_contents($tmp),
        ];
    }
}

// Encabezados: el remitente es una casilla del propio dominio; la respuesta va a quien completó el formulario
$detalle = trim((string) ($_POST['razon_social'] ?? ''));
$asunto = '=?UTF-8?B?' . base64_encode('[Sitio CEMAP] ' . $tipos[$tipo]['asunto'] . ($detalle !== '' ? ' – ' . mb_substr($detalle, 0, 80) : '')) . '?=';
$encabezados = [
    'From: ' . $config['remitente'],
    'Reply-To: ' . $correo,
    'MIME-Version: 1.0',
];

if ($adjuntos) {
    $limite = 'cemap_' . bin2hex(random_bytes(12));
    $encabezados[] = "Content-Type: multipart/mixed; boundary=\"$limite\"";
    $cuerpo = "--$limite\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($texto)) . "\r\n";
    foreach ($adjuntos as $a) {
        $cuerpo .= "--$limite\r\n"
            . "Content-Type: {$a['mime']}; name=\"{$a['nombre']}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$a['nombre']}\"\r\n\r\n"
            . chunk_split(base64_encode($a['datos'])) . "\r\n";
    }
    $cuerpo .= "--$limite--";
} else {
    $encabezados[] = 'Content-Type: text/plain; charset=UTF-8';
    $encabezados[] = 'Content-Transfer-Encoding: base64';
    $cuerpo = chunk_split(base64_encode($texto));
}

$destino = $config['destinatarios'][$tipo] ?? $config['destinatarios']['contacto'];
$enviado = mail($destino, $asunto, $cuerpo, implode("\r\n", $encabezados));

if (!$enviado) {
    responder(false, 500, 'No se pudo enviar el correo');
}

$envios[] = time();
@file_put_contents($archivoIp, json_encode(array_values($envios)));
responder(true);
