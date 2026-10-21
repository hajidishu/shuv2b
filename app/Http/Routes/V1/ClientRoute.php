<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // Client
            if (empty(config('v2board.subscribe_path'))) {
                $router->get('/subscribe', 'V1\\Client\\ClientController@subscribe')->middleware(['subscription.audit', 'leak.delivery']);
            }
            // App
            $router->get('/app/getConfig', 'V1\\Client\\AppController@getConfig')->middleware('leak.delivery');
            $router->get('/app/getVersion', 'V1\\Client\\AppController@getVersion');
        });
    }
}
