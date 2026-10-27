# SiGeRu-API-Recoleccion

API REST en Laravel 12 encargada de los recorridos de recolección de residuos, seguimiento GPS de camiones, vaciado de contenedores y descarga en el vertedero Felipe Cardoso.

## Ejecución local

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan serve --port=8002
```

La API funciona en: `http://127.0.0.1:8002`
