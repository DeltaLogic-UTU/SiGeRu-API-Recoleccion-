<?php
// Configuración de CORS para permitir que el frontend consulte la API
// desde otro puerto y pueda enviar el header Authorization: Bearer <token>
return [
     // 1. Aplicamos CORS a todas las rutas que empiecen con /api/
    'paths' => ['api/*'],

     // 2. Permitimos todos los métodos HTTP (GET, POST, PUT, DELETE, OPTIONS)
    'allowed_methods' => ['*'],

    // 3. Permitimos peticiones desde cualquier origen local (localhost, 127.0.0.1, etc.)
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],
    
    // 4. Permitimos todos los headers (fundamental para Authorization y Content-Type)
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

      // 5. En false porque usamos token JWT por header, no cookies de sesión
    'supports_credentials' => false,

];
