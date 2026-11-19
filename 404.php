<?php
/* Every address that doesn't exist ends up here: follow a redirect if one is set, otherwise log it and show the 404 page. */
require __DIR__ . '/inc/layout.php';

check_redirect();
log_not_found();
http_response_code(404);
page_start('home', ['title' => 'Page not found — ' . setting('site_name', 'Dakpion IMC'), 'path' => '404', 'desc' => 'This page does not exist.']);
page_hero('Home', '404', 'Page not <span class="hl">found.</span>', 'The page you were looking for has moved or no longer exists.',
    '<a class="btn btn--grey btn--lg" href="' . url() . '">Back to home ' . icon('arrow-ur') . '</a> <a class="btn btn--line btn--lg" href="' . url('contact') . '">Contact us ' . icon('arrow-ur') . '</a>');
page_end();
