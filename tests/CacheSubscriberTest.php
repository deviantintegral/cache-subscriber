<?php
namespace GuzzleHttp\Tests\Subscriber\Cache;

require_once __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';
require_once __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Tests\Server;

class CacheSubscriberTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        Server::start();
    }

    protected function tearDown()
    {
        Server::stop();
    }

    public function testCreatesAndAttachedDefaultSubscriber()
    {
        $client = new Client();
        $cache = CacheSubscriber::attach($client);
        $this->assertArrayHasKey('subscriber', $cache);
        $this->assertArrayHasKey('storage', $cache);
        $this->assertInstanceOf(
            'GuzzleHttp\Subscriber\Cache\CacheStorage',
            $cache['storage']
        );
        $this->assertInstanceOf(
            'GuzzleHttp\Subscriber\Cache\CacheSubscriber',
            $cache['subscriber']
        );
        $this->assertTrue($client->getEmitter()->hasListeners('error'));
    }

    /**
     * Test that stale responses are used on errors if allowed.
     *
     * @throws \Exception
     */
    public function testOnErrorStaleResponse()
    {
        Server::enqueue([
          new Response(200, [
            'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
            'Cache-Control' => 'private, max-age=666, must-revalidate, stale-if-error=666',
            'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
            'Age' => '100'
          ]),
          new Response(503, [
            'Date' => 'Wed, 29 Oct 2014 20:55:15 GMT',
            'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
            'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
            'Age' => '1277'
          ]),
        ]);

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        $history = new History();
        $client->getEmitter()->attach($history);

        // Prime the cache.
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('Wed, 29 Oct 2014 20:52:15 GMT', $response1->getHeader('Date'));

        // This should return the first request.
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('Wed, 29 Oct 2014 20:52:15 GMT', $response1->getHeader('Date'));
        $this->assertCount(2, Server::received());
    }
}
