<?php
/**
 * Created by PhpStorm.
 * User: Game
 * Date: 2018/9/11
 * Time: 21:50
 */

namespace Vbot\WebServer;

class WebServer
{

    static public function register()
    {
        $vbot = vbot();

        $vbot->singleton('server', function () use ($vbot) {
            return new Server($vbot);
        });

        $vbot->singleton('http', function () use ($vbot) {
            return new Http($vbot);
        });

        $vbot->singleton('messageHandler', function () use ($vbot) {
            return new MessageHandler($vbot);
        });
    }
}