<?php
/**
 * Created by PhpStorm.
 * User: Game
 * Date: 2018/9/11
 * Time: 18:57
 */

namespace Vbot\WebServer;


use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise;
use Hanson\Vbot\Console\Console;
use Hanson\Vbot\Foundation\Vbot;

class Http extends \Hanson\Vbot\Support\Http
{
    /**
     * @var CurlMultiHandler
     */
    private $handler;

    public function __construct(Vbot $vbot)
    {
        $this->vbot = $vbot;
        $this->cookieJar = new FileCookieJar($vbot->config['cookie_file'], true);
        $this->handler = new CurlMultiHandler(['select_timeout' => 0]);

        $this->client = new Client(['cookies' => $this->cookieJar, 'handler' => HandlerStack::create($this->handler)]);
    }

    public function getAsync($url, array $options = []) {
        $options = array_merge(['timeout' => 10, 'verify' => false], $options);

        $promise = $this->getClient()->getAsync($url, $options);

        while(!Promise\is_settled($promise)) {
            $this->handler->tick();
            yield;
        }

        $this->cookieJar->save($this->vbot->config['cookie_file']);

        try {
            $response = $promise->wait();
        } catch (\Exception $e) {
            $this->vbot->console->log($url.$e->getMessage(), Console::ERROR, true);
            return false;
        }

        $content = $response->getBody()->getContents();

        return $content;
    }

    /**
     * @param $url
     * @param string $method
     * @param array $options
     * @param bool $retry
     *
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($url, $method = 'GET', $options = [], $retry = false)
    {
        try {
            $options = array_merge(['timeout' => 10, 'verify' => false], $options);

            $promise = $this->getClient()->requestAsync($method, $url, $options);

            while(!Promise\is_settled($promise)) {
                $this->handler->tick();
            }

            $this->cookieJar->save($this->vbot->config['cookie_file']);

            $response = $promise->wait();
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->vbot->console->log($url.$e->getMessage(), Console::ERROR, true);

            if (!$retry) {
                return $this->request($url, $method, $options, true);
            }

            return false;
        }
    }
}