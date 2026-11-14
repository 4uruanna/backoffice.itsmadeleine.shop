<?php

// Page de test M1 : à supprimer du serveur après M2 (voir roadmap/M2-go-no-go.md).
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

printf("PHP_VERSION: %s\n", PHP_VERSION);
printf("SAPI: %s\n", PHP_SAPI);
printf("fastcgi_finish_request: %s\n", function_exists('fastcgi_finish_request') ? 'oui' : 'non');
printf("gd: %s\n", extension_loaded('gd') ? 'oui' : 'non');
if (function_exists('gd_info')) {
    printf("gd PNG: %s\n", (gd_info()['PNG Support'] ?? false) ? 'oui' : 'non');
}
printf("imagick: %s\n", extension_loaded('imagick') ? 'oui' : 'non');
printf("pdo_mysql: %s\n", extension_loaded('pdo_mysql') ? 'oui' : 'non');
printf("timezone: %s\n", date_default_timezone_get());
