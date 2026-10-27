<?php

// Configuración de CORS para permitir que el frontend (en otro puerto u origen)
// consulte la API y envíe el header Authorization: Bearer <token>.
return [
    // Aplica CORS a todas las rutas bajo /api/*
    'paths' => ['api/*'],

    // Permite todos los métodos HTTP (GET, POST, PUT, DELETE, OPTIONS, etc.)
    'allowed_methods' => ['*'],

    // Permite peticiones desde cualquier origen local (localhost, 127.0.0.1, etc.)
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // Permite enviar cualquier header, incluyendo Authorization y Content-Type
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Se desactiva el envío de cookies de sesión porque la autenticación se hace por JWT
    'supports_credentials' => false,
];