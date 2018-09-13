<?php
/**
 * Created by PhpStorm.
 * User: Game
 * Date: 2018/9/11
 * Time: 19:07
 */

namespace Vbot\WebServer;


use Hanson\Vbot\Console\Console;
use PHPQRCode\QRcode;
use ReflectionClass;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;
use Workerman\Protocols\Http as Response;

class Server extends \Hanson\Vbot\Core\Server
{
    const OFFLINE = 0;

    const ONLINE = 1;

    const LOGGING_IN = 2;

    protected $worker;

    protected $loginStatus = 0;

    /**
     * @var ReflectionClass
     */
    protected $ref;

    /**
     * @throws \ReflectionException
     */
    public function serve()
    {
        $ip = $this->vbot->config['workerman.ip'];
        $port = $this->vbot->config['workerman.port'];

        $this->worker = new Worker("http://{$ip}:{$port}");

        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];

        $this->worker->onMessage = [$this, 'onMessage'];

        $this->ref = new ReflectionClass($this);

        global $argv;

        $commands = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        ];

        if (isset($argv[1]) && !in_array($argv[1], $commands)) {
            $argv[1] = 'start';
        }

        if (in_array('-d', $argv)) {
            $argv[2] = '-d';
        }

        Worker::runAll();
    }

    /**
     */
    public function onWorkerStart()
    {
        $generator = $this->vbotServer();

        $time_interval = 0.05;
        Timer::add($time_interval, [$generator, 'next']);
    }

    /**
     * @param TcpConnection $response
     * @param $request
     * @throws \Exception
     */
    public function onMessage(TcpConnection $response, $request)
    {
        if ($this->vbot->config['workerman.mode'] == 'web') {
            $uri = $request['server']['REQUEST_URI'];
            if ($uri == '/login') {
                $this->actionLogin($response);
                return;
            } elseif ($uri == '/qr-code') {
                $this->actionQrCode($response);
                return;
            }
        }

        if (!($result = $this->validate($request))) {
            $response->send('error');
            return ;
        }

        $data = $this->vbot->{$result['class']}->execute($result['params']);

        Response::header('Content-Type:text/json;');
        $response->send(json_encode($data));
    }

    /**
     * @param TcpConnection $response
     */
    public function actionLogin(TcpConnection $response)
    {
        $tryLogin = $this->ref->getmethod('tryLogin');
        $tryLogin->setAccessible(true);

        $cleanCookies = $this->ref->getmethod('cleanCookies');
        $cleanCookies->setAccessible(true);

        $info = '登陆状态正常，无需重复登陆';
        if ($this->loginStatus != self::ONLINE) {
            if (!$tryLogin->invoke($this)) {
                $cleanCookies->invoke($this);
                $info = '<img width="333" height="333" src="/qr-code">';
            } else {
                $this->loginStatus = self::ONLINE;
            }
        }

        $html = <<<EOT
<!DOCTYPE HTML>
<html>
    <head>
      <style>
        .main{
            width: 333px;
            height: 333px;
            margin: auto;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
        }
      </style>
      <meta name="viewport" content="width=device-width,initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
    </head>
    <body>
        <div class="main">
          {$info}
        </div>
    </body>
</html>
EOT;
        Response::header('Content-Type:text/html;charset=utf-8');
        $response->send($html);
    }

    /**
     * @param TcpConnection $response
     * @throws \Exception
     */
    public function actionQrCode(TcpConnection $response)
    {
        $this->loginStatus = self::LOGGING_IN;

        $this->getUuid();
        $url = 'https://login.weixin.qq.com/l/'.$this->vbot->config['server.uuid'];
        $this->vbot->qrCodeObserver->trigger($url);

        $qrFile = $this->vbot->config['path'].'/qr-code.png';
        QRcode::png($url, $qrFile, 0, 10);

        Response::header('Content-Type:image/png');
        $response->send(file_get_contents($qrFile));
    }

    /**
     * @param $request
     * @return array|bool
     */
    private function validate($request)
    {
        if (!is_array($request['post'])) {
            return false;
        }

        $data = $request['post'];

        if (!isset($data['action']) || !isset($data['params'])) {
            return false;
        }

        $namespace = '\\Hanson\\Vbot\\Api\\';

        if (class_exists($class = $namespace.ucfirst($data['action']))) {
            return ['params' => $data['params'], 'class' => 'api'.ucfirst($data['action'])];
        }

        return false;
    }

    /**
     *
     *
     * @return \Generator
     */
    protected function vbotServer()
    {
        if ($this->vbot->config['workerman.mode'] == 'console') {
            parent::serve();
            yield from $this->vbot->messageHandler->start();

            return ;
        }

        $getLogin = $this->ref->getmethod('getLogin');
        $getLogin->setAccessible(true);

        while(true) {
            try {
                if ($this->loginStatus == self::LOGGING_IN) {
                    yield from $this->waitForLoginAsync();
                    $getLogin->invoke($this);

                    $this->loginStatus = self::ONLINE;
                }

                if ($this->loginStatus == self::ONLINE) {
                    $this->init();
                    yield from $this->vbot->messageHandler->start();
                    $this->loginStatus = self::OFFLINE;
                }
            } catch (\Exception $e) {
                $this->loginStatus = self::OFFLINE;
            }

            yield;
        }

    }

    /**
     * waiting user to login.
     *
     * @throws \Exception
     */
    protected function waitForLoginAsync()
    {
        $retryTime = 10;
        $tip = 1;

        $this->vbot->console->log('please scan the qrCode with wechat.');
        while ($retryTime > 0) {
            $url = sprintf('https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?tip=%s&uuid=%s&_=%s', $tip, $this->vbot->config['server.uuid'], time());

            $content = yield from $this->vbot->http->getAsync($url, ['timeout' => 35]);

            preg_match('/window.code=(\d+);/', $content, $matches);

            $code = $matches[1];
            switch ($code) {
                case '201':
                    $this->vbot->console->log('please confirm login in wechat.');
                    $tip = 0;
                    break;
                case '200':
                    preg_match('/window.redirect_uri="(https:\/\/(\S+?)\/\S+?)";/', $content, $matches);

                    $this->vbot->config['server.uri.redirect'] = $matches[1].'&fun=new';
                    $url = 'https://%s/cgi-bin/mmwebwx-bin';
                    $this->vbot->config['server.uri.file'] = sprintf($url, 'file.'.$matches[2]);
                    $this->vbot->config['server.uri.push'] = sprintf($url, 'webpush.'.$matches[2]);
                    $this->vbot->config['server.uri.base'] = sprintf($url, $matches[2]);

                    return;
                case '408':
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
                default:
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
            }
        }

        $this->vbot->console->log('login time out!', Console::ERROR);
    }


}