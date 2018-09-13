<?php
/**
 * Created by PhpStorm.
 * User: Game
 * Date: 2018/9/11
 * Time: 21:04
 */

namespace Vbot\WebServer;


use Carbon\Carbon;
use Hanson\Vbot\Message\Text;
use Workerman\Worker;

class MessageHandler extends \Hanson\Vbot\Core\MessageHandler
{
    protected $stop = false;

    public function listen($server = null)
    {

    }

    public function start()
    {

        $this->stop = false;

        $this->vbot->beforeMessageObserver->trigger();

        $this->vbot->messageExtension->initServiceExtensions();

        $time = 0;

        while (true) {
            if ($this->customHandler) {
                call_user_func($this->customHandler);
            }

            if (time() - $time > 1800) {
                Text::send('filehelper', 'heart beat '.Carbon::now()->toDateTimeString());

                $time = time();
            }

            yield from $this->checkAsync();

            if ($this->stop) break;
        }
    }

    protected function checkAsync()
    {
        $url = $this->vbot->config['server.uri.push'].'/synccheck';

        $content = yield from $this->vbot->http->getAsync($url, ['timeout' => 35, 'query' => [
            'r'        => time(),
            'sid'      => $this->vbot->config['server.sid'],
            'uin'      => $this->vbot->config['server.uin'],
            'skey'     => $this->vbot->config['server.skey'],
            'deviceid' => $this->vbot->config['server.deviceId'],
            'synckey'  => $this->vbot->config['server.syncKeyStr'],
            '_'        => time(),
        ]]);

        if (!$content) {
            $this->vbot->console->log('checkSync no response');
            return ;
        }

        $checkSync = preg_match('/window.synccheck=\{retcode:"(\d+)",selector:"(\d+)"\}/', $content, $matches) ?
            [$matches[1], $matches[2]] : false;

        if (!$checkSync) return ;

        if (!$this->handleCheckSync($checkSync[0], $checkSync[1])) {
            $this->stop = true;
        }
    }
}