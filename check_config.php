<?php

try {
    require __DIR__ . '/vendor/autoload.php';

    $app = require_once __DIR__ . '/bootstrap/app.php';

    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

    $kernel->bootstrap();

    echo "SESSION_DOMAIN: " . (config('session.domain') ?? 'null') . "\n";
    echo "SESSION_SECURE_COOKIE: " . (config('session.secure') ? 'true' : 'false') . "\n";
    echo "SESSION_SAME_SITE: " . (config('session.same_site') ?? 'null') . "\n";
    // sanctum.stateful is an array, implode it.
    echo "SANCTUM_STATEFUL_DOMAINS: " . implode(',', config('sanctum.stateful', [])) . "\n";
    echo "CORS_ALLOWED_ORIGINS: " . implode(',', config('cors.allowed_origins', [])) . "\n";
    echo "CORS_SUPPORTS_CREDENTIALS: " . (config('cors.supports_credentials') ? 'true' : 'false') . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage();
}
echo "End of script\n";
