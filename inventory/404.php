<?php
session_start();
require_once __DIR__ . '/inc/layout.php';
http_response_code(404);
render_header('Not Found');
echo '<div class="text-center py-5"><h3>404 — Page not found</h3><a href="/inventory/pages/dashboard.php" class="btn btn-primary mt-3">Back to Dashboard</a></div>';
render_footer();
