<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->setDefaultNamespace('App\Controllers');
$routes->setAutoRoute(false);

// Health check / welcome
$routes->get('/', 'Home::index');
$routes->get('api/health', 'Api\Health::index');
$routes->get('health', 'Api\Health::index');

// CORS preflight — answer OPTIONS for every /api/* and root path
$routes->options('api/(:any)', static function () {
    return service('response')->setStatusCode(204);
});
$routes->options('(:any)', static function () {
    return service('response')->setStatusCode(204);
});

// Helper to register routes under both 'api' prefix and root prefix
// Ensures full compatibility whether accessed directly or via Hostinger subfolder
$registerAllRoutes = static function (string $basePrefix, RouteCollection $routes) {
    $prefix = $basePrefix ? trim($basePrefix, '/') . '/' : '';

    // ============================================================
    //   PUBLIC API
    // ============================================================
    $routes->group($basePrefix, ['namespace' => 'App\Controllers\Api'], static function ($routes) {
        // Catalog
        $routes->get('categories',              'Categories::index');
        $routes->get('categories/(:segment)',   'Categories::show/$1');
        $routes->get('products',                'Products::index');
        $routes->get('products/(:segment)',     'Products::show/$1');
        $routes->get('banners',                 'Banners::index');
        $routes->get('combos',                  'Combos::index');
        $routes->get('combos/(:segment)',       'Combos::show/$1');
        $routes->get('home',                    'Home::index');
        $routes->get('settings/public',         'Settings::publicView');

        // Customer auth — issues the token
        $routes->post('auth/register',          'Auth::register');
        $routes->post('auth/login',             'Auth::login');
        $routes->post('auth/logout',            'Auth::logout');

        // Public submission
        $routes->post('enquiries',              'Enquiries::store');
        $routes->post('newsletter/subscribe',   'Newsletter::subscribe');

        // Webhooks
        $routes->post('webhooks/stripe',        'StripeWebhook::handle');
        $routes->post('webhooks/tamara',        'TamaraWebhook::handle');
        $routes->post('webhooks/jeebly',        'JeeblyWebhook::handle');

        // Admin auth — issues the admin token
        $routes->post('admin/login',            'AdminAuth::login');
        $routes->post('admin/logout',           'AdminAuth::logout');
    });

    // ============================================================
    //   CUSTOMER-PROTECTED ("ME") API
    // ============================================================
    $routes->group($prefix . 'me', ['namespace' => 'App\Controllers\Me', 'filter' => 'customerAuth'], static function ($routes) {
        $routes->get('addresses',                       'Addresses::index');
        $routes->post('addresses',                      'Addresses::create');
        $routes->put('addresses/(:num)',                'Addresses::update/$1');
        $routes->delete('addresses/(:num)',             'Addresses::delete/$1');

        $routes->post('checkout/quote',                 'Checkout::quote');
        $routes->post('checkout/place',                 'Checkout::place');
        $routes->post('checkout/prepare-stripe',        'Checkout::prepareStripe');
        $routes->post('checkout/finalize-stripe',       'Checkout::finalizeStripe');
        $routes->post('checkout/prepare-tamara',        'Checkout::prepareTamara');
        $routes->post('checkout/finalize-tamara',       'Checkout::finalizeTamara');

        // Coupon validation
        $routes->post('coupons/validate',               'Coupons::apply');

        $routes->get('orders',                          'Orders::index');
        $routes->get('orders/(:num)',                   'Orders::show/$1');
        $routes->post('orders/(:num)/cancel',           'Orders::cancel/$1');
        $routes->post('orders/(:num)/confirm-stripe',   'Orders::confirmStripe/$1');
    });

    $routes->get($prefix . 'auth/me', 'Api\Auth::me', ['filter' => 'customerAuth']);
    $routes->put($prefix . 'auth/me', 'Api\Auth::updateMe', ['filter' => 'customerAuth']);

    // ============================================================
    //   ADMIN API
    // ============================================================
    $routes->group($prefix . 'admin', ['namespace' => 'App\Controllers\Admin', 'filter' => 'adminAuth'], static function ($routes) {
        $routes->get('me',                                   'AdminAuth::me');

        $routes->get('categories',                           'Categories::index');
        $routes->post('categories',                          'Categories::create');
        $routes->get('categories/(:num)',                    'Categories::show/$1');
        $routes->put('categories/(:num)',                    'Categories::update/$1');
        $routes->delete('categories/(:num)',                 'Categories::delete/$1');

        $routes->get('products',                             'Products::index');
        $routes->post('products',                            'Products::create');
        $routes->get('products/(:num)',                      'Products::show/$1');
        $routes->put('products/(:num)',                      'Products::update/$1');
        $routes->delete('products/(:num)',                   'Products::delete/$1');
        $routes->post('products/(:num)/images',              'Products::addImage/$1');
        $routes->put('products/(:num)/images/(:num)',        'Products::updateImage/$1/$2');
        $routes->delete('products/(:num)/images/(:num)',     'Products::deleteImage/$1/$2');
        // Shade variants
        $routes->post('products/(:num)/variants',            'Products::addVariant/$1');
        $routes->put('products/(:num)/variants/(:num)',      'Products::updateVariant/$1/$2');
        $routes->delete('products/(:num)/variants/(:num)',   'Products::deleteVariant/$1/$2');
        // Bulk-qty discount tiers
        $routes->post('products/(:num)/volume-discounts',                'Products::addVolumeDiscount/$1');
        $routes->put('products/(:num)/volume-discounts/(:num)',          'Products::updateVolumeDiscount/$1/$2');
        $routes->delete('products/(:num)/volume-discounts/(:num)',       'Products::deleteVolumeDiscount/$1/$2');

        $routes->get('banners',                              'Banners::index');
        $routes->post('banners',                             'Banners::create');
        $routes->put('banners/(:num)',                       'Banners::update/$1');
        $routes->delete('banners/(:num)',                    'Banners::delete/$1');

        // Combos (bundles)
        $routes->get('combos',                               'Combos::index');
        $routes->post('combos',                              'Combos::create');
        $routes->get('combos/(:num)',                        'Combos::show/$1');
        $routes->put('combos/(:num)',                        'Combos::update/$1');
        $routes->delete('combos/(:num)',                     'Combos::delete/$1');

        $routes->post('media/upload',                        'Media::upload');

        $routes->get('settings',                             'Settings::index');
        $routes->put('settings',                             'Settings::update');

        $routes->get('enquiries',                            'Enquiries::index');
        $routes->get('enquiries/(:num)',                     'Enquiries::show/$1');
        $routes->patch('enquiries/(:num)',                   'Enquiries::update/$1');
        $routes->delete('enquiries/(:num)',                  'Enquiries::delete/$1');

        // Newsletter
        $routes->get('newsletter',                           'Newsletter::index');
        $routes->get('newsletter/export',                    'Newsletter::export');
        $routes->delete('newsletter/(:num)',                 'Newsletter::delete/$1');

        // Orders
        $routes->get('orders',                               'Orders::index');
        $routes->get('orders/stats',                         'Orders::stats');
        $routes->get('orders/(:num)',                        'Orders::show/$1');
        $routes->patch('orders/(:num)/status',               'Orders::updateStatus/$1');

        // Jeebly courier integration
        $routes->get('orders/(:num)/jeebly/tracking',        'Orders::jeeblyTracking/$1');
        $routes->post('orders/(:num)/jeebly/create',         'Orders::jeeblyCreate/$1');
        $routes->post('orders/(:num)/jeebly/cancel',         'Orders::jeeblyCancel/$1');
        $routes->get('orders/(:num)/jeebly/label',           'Orders::jeeblyLabel/$1');

        // One-shot translator backfill
        $routes->post('translate/backfill',                  'TranslateAdmin::backfill');

        // Sales analytics
        $routes->get('sales/summary',                        'Sales::summary');
        $routes->get('sales/series',                         'Sales::series');
        $routes->get('sales/customers',                      'Sales::customers');

        // Coupons CRUD
        $routes->get('coupons',                              'Coupons::index');
        $routes->post('coupons',                             'Coupons::create');
        $routes->get('coupons/(:num)',                       'Coupons::show/$1');
        $routes->put('coupons/(:num)',                       'Coupons::update/$1');
        $routes->delete('coupons/(:num)',                    'Coupons::delete/$1');
    });

    // ============================================================
    //   SALES API
    // ============================================================
    $routes->group($prefix . 'sales', ['namespace' => 'App\Controllers\Admin', 'filter' => 'salesAuth'], static function ($routes) {
        $routes->get('me',                       'AdminAuth::me');

        // Dashboard / Sales analytics
        $routes->get('sales/summary',            'Sales::summary');
        $routes->get('sales/series',             'Sales::series');
        $routes->get('sales/customers',          'Sales::customers');

        // Orders — list, view, update status
        $routes->get('orders',                   'Orders::index');
        $routes->get('orders/stats',             'Orders::stats');
        $routes->get('orders/(:num)',            'Orders::show/$1');
        $routes->patch('orders/(:num)/status',   'Orders::updateStatus/$1');
    });
};

$registerAllRoutes('api', $routes);
$registerAllRoutes('', $routes);
