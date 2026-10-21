<?php
// Configuración de los formularios del sitio. Editar desde el Administrador de archivos de cPanel.
return [
    // A dónde llega cada formulario
    'destinatarios' => [
        'contacto'   => 'cemap@gmail.com',
        'interes'    => 'cemap@gmail.com',
        'socio'      => 'cemap@gmail.com',
        'newsletter' => 'cemap@gmail.com',
    ],

    // Remitente: debe ser una casilla del dominio del sitio (crearla en cPanel → Cuentas de correo),
    // si no, Gmail y otros proveedores pueden marcar los correos como spam.
    'remitente' => 'CEMAP Sitio Web <no-responder@cemap.org.py>',

    'envios_por_hora' => 5,
    'max_adjuntos'    => 5,
    'max_mb_adjunto'  => 5,
];
