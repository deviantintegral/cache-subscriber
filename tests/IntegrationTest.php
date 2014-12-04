<?php
namespace GuzzleHttp\Tests\Subscriber\Cache;

require_once __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';
require_once __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Tests\Server;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        Server::start();
    }

    protected function tearDown()
    {
        Server::stop();
    }

    public function testCachesResponses()
    {
        Server::enqueue([
            new Response(200, [
                'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
                'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1277'
            ]),
            new Response(304, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
                'Date' => 'Wed, 29 Oct 2014 20:52:16 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1278'
            ])
        ]);

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        $history = new History();
        $client->getEmitter()->attach($history);
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertCount(2, Server::received());
        $last = $history->getLastResponse();
        $this->assertEquals('HIT from GuzzleCache', $last->getHeader('X-Cache-Lookup'));
        $this->assertEquals('HIT from GuzzleCache', $last->getHeader('X-Cache'));
    }

    /**
     * Test that the Vary header creates unique cache entries.
     *
     * @throws \Exception
     */
    public function testVaryUniqueResponses()
    {
        $now = gmdate("D, d M Y H:i:s");

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Vary' => 'Accept',
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory('It works!')
                ),
                new Response(
                    200, [
                    'Vary' => 'Accept',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'It works!']))
                ),
            ]
        );

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        $history = new History();
        $client->getEmitter()->attach($history);

        $response1 = $client->get('/foo', ['headers' => ['Accept' => 'text/html']]);
        $this->assertEquals('It works!', $this->getResponseBody($response1));

        $response2 = $client->get('/foo', ['headers' => ['Accept' => 'application/json']]);
        $this->assertEquals('MISS from GuzzleCache', $response2->getHeader('x-cache'));

        $decoded = json_decode($this->getResponseBody($response2));

        if (!isset($decoded) || !isset($decoded->body)) {
            $this->fail('JSON response could not be decoded.');
        } else {
            $this->assertEquals('It works!', $decoded->body);
        }
    }

    public function testMultipleVaryHeader()
    {
        $now = gmdate("D, d M Y H:i:s");

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory('Test/1.0 request.')
                ),
                new Response(200,
                [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ],
                Stream::factory('Test/2.0 request.')),
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'Test/1.0 request.']))
                ),
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'Test/2.0 request.']))
                ),
            ]
        );

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        $history = new History();
        $client->getEmitter()->attach($history);

        // Test that requests varying on User-Agent return different responses.
        $response1 = $client->get('/foo', ['headers' => ['Accept' => 'text/html', 'User-Agent' => 'Testing/1.0']]);
        $this->assertEquals('Test/1.0 request.', $this->getResponseBody($response1));

        $response2 = $client->get('/foo', ['headers' => ['Accept' => 'text/html', 'User-Agent' => 'Testing/2.0']]);
        $this->assertEquals('MISS from GuzzleCache', $response2->getHeader('x-cache'));
        $this->assertEquals('Test/2.0 request.', $this->getResponseBody($response2));

        // Test that requests varying on Accept but not User-Agent return different responses.
        $response3 = $client->get('/foo', ['headers' => ['Accept' => 'application/json', 'User-Agent' => 'Testing/1.0']]);
        $this->assertEquals('MISS from GuzzleCache', $response3->getHeader('x-cache'));
        $this->assertEquals('Test/1.0 request.', json_decode($this->getResponseBody($response3))->body);

        $response4 = $client->get('/foo', ['headers' => ['Accept' => 'application/json', 'User-Agent' => 'Testing/2.0']]);
        $this->assertEquals('MISS from GuzzleCache', $response4->getHeader('x-cache'));
        $this->assertEquals('Test/2.0 request.', json_decode($this->getResponseBody($response4))->body);

        // Test that we get cache hits where both Vary headers match.
        $response5 = $client->get('/foo', ['headers' => ['Accept' => 'text/html', 'User-Agent' => 'Testing/2.0']]);
        $this->assertEquals('HIT from GuzzleCache', $response5->getHeader('x-cache'));
        $this->assertEquals('Test/2.0 request.', $this->getResponseBody($response5));

        $response5 = $client->get('/foo', ['headers' => ['Accept' => 'application/json', 'User-Agent' => 'Testing/2.0']]);
        $this->assertEquals('HIT from GuzzleCache', $response5->getHeader('x-cache'));
        $this->assertEquals('Test/2.0 request.', json_decode($this->getResponseBody($response5))->body);
    }

    /**
     * Decode a response body from TestServer.
     *
     * TestServer encodes all responses with base64, so we need to decode them
     * before we can do any assert's on them.
     *
     * @param Response $response The response with a body to decode.
     *
     * @return string
     */
    private function getResponseBody($response)
    {
        return base64_decode($response->getBody());
    }
}
