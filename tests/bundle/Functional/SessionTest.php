<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace Ibexa\Tests\Bundle\Rest\Functional;

use DOMDocument;
use DOMXPath;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class SessionTest extends TestCase
{
    public function setUp(): void
    {
        $this->autoLogin = false;
        parent::setUp();
    }

    public function testCreateSessionBadCredentials()
    {
        $request = $this->createAuthenticationHttpRequest('admin', 'bad_password');
        $response = $this->sendHttpRequest($request);
        self::assertHttpResponseCodeEquals($response, 401);
    }

    /**
     * @return \stdClass The login request's response
     */
    public function testCreateSession()
    {
        return $this->login();
    }

    /**
     * @depends testCreateSession
     *
     * @param \stdClass $session
     */
    public function testRefreshSession(stdClass $session)
    {
        $response = $this->sendHttpRequest($this->createRefreshRequest($session));
        self::assertHttpResponseCodeEquals($response, 200);
    }

    public function testRefreshSessionExpired()
    {
        $session = $this->login();

        $response = $this->sendHttpRequest($this->createDeleteRequest($session));
        self::assertHttpResponseCodeEquals($response, 204);

        $response = $this->sendHttpRequest($this->createRefreshRequest($session));
        self::assertHttpResponseCodeEquals($response, 404);

        self::assertHttpResponseDeletesSessionCookie($session, $response);
    }

    public function testRefreshSessionMissingCsrfToken()
    {
        $session = $this->login();

        $refreshRequest = $this
            ->createRefreshRequest($session)
            ->withoutHeader('X-CSRF-Token');
        $response = $this->sendHttpRequest($refreshRequest);
        self::assertHttpResponseCodeEquals($response, 401);
    }

    public function testDeleteSession()
    {
        $session = $this->login();
        $response = $this->sendHttpRequest($this->createDeleteRequest($session));
        self::assertHttpResponseCodeEquals($response, 204);
        self::assertHttpResponseDeletesSessionCookie($session, $response);

        return $session;
    }

    /**
     * CSRF needs to be tested as session handling bypasses the CsrfListener.
     */
    public function testDeleteSessionMissingCsrfToken()
    {
        $session = $this->login();
        $request = $this
            ->createDeleteRequest($session)
            ->withoutHeader('X-CSRF-Token');
        $response = $this->sendHttpRequest($request);
        self::assertHttpResponseCodeEquals($response, 401);
    }

    public function testLoginWithExistingFrontendSession()
    {
        $baseURI = $this->getBaseURI();
        $browser = $this->createBrowser();

        $browser->request('GET', "{$baseURI}/login");
        $response = $browser->getInternalResponse();

        self::assertEquals(200, $response->getStatusCode());

        $domDocument = new DOMDocument();
        // load HTML, suppress error reporting due to buggy Sf toolbar code in dev/behat ENVs
        $domDocument->loadHTML($response->getContent(), LIBXML_NOERROR);

        $xpath = new DOMXPath($domDocument);

        $csrfDomElements = $xpath->query("//input[@name='_csrf_token']/@value");
        self::assertGreaterThan(0, $csrfDomElements->length);
        $csrfTokenValue = $csrfDomElements->item(0)->nodeValue;

        $browser->submitForm(
            'Login',
            [
                '_username' => $this->getLoginUsername(),
                '_password' => $this->getLoginPassword(),
                '_csrf_token' => $csrfTokenValue,
            ]
        );
        $loginResponse = $browser->getInternalResponse();
        self::assertNotEmpty($loginResponse->getHeader('set-cookie'));

        $request = $this->createAuthenticationHttpRequest(
            $this->getLoginUsername(),
            $this->getLoginPassword(),
            ['Cookie' => $loginResponse->getHeader('set-cookie')[0]]
        );
        $response = $this->sendHttpRequest($request);

        // Session is recreated when using CSRF, expect 201 instead of 200
        self::assertHttpResponseCodeEquals($response, 201);
    }

    /**
     * @depends testDeleteSession
     */
    public function testDeleteSessionExpired($session)
    {
        $response = $this->sendHttpRequest($this->createDeleteRequest($session));
        self::assertHttpResponseCodeEquals($response, 404);
        self::assertHttpResponseDeletesSessionCookie($session, $response);
    }

    /**
     * @depends testCreateSession
     */
    public function testCheckSession(): void
    {
        $session = $this->login();
        $request = $this->createHttpRequest(
            'GET',
            '/api/ibexa/v2/user/sessions/current',
            '',
            'Session+json',
            '',
            [
                'Cookie' => sprintf('%s=%s', $session->name, $session->identifier),
                'X-CSRF-Token' => $session->csrfToken,
            ]
        );
        $response = $this->sendHttpRequest($request);
        self::assertHttpResponseCodeEquals($response, 200);
        $contents = $response->getBody()->getContents();
        $data = json_decode($contents, true, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('Session', $data);
    }

    /**
     * @depends testCreateSession
     */
    public function testCheckSessionWithoutOne(): void
    {
        $request = $this->createHttpRequest(
            'GET',
            '/api/ibexa/v2/user/sessions/current',
            '',
            'Session+json'
        );
        $response = $this->sendHttpRequest($request);
        self::assertHttpResponseCodeEquals($response, 404);
        $contents = $response->getBody()->getContents();
        self::assertEmpty($contents);
    }

    /**
     * @param \stdClass $session
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    protected function createRefreshRequest(stdClass $session): RequestInterface
    {
        $request = $this->createHttpRequest(
            'POST',
            sprintf('/api/ibexa/v2/user/sessions/%s/refresh', $session->identifier),
            '',
            'Session+json',
            '',
            [
                'Cookie' => sprintf('%s=%s', $session->name, $session->identifier),
                'X-CSRF-Token' => $session->csrfToken,
            ]
        );

        return $request;
    }

    /**
     * @param \stdClass $session
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    protected function createDeleteRequest(stdClass $session): RequestInterface
    {
        $deleteRequest = $this->createHttpRequest(
            'DELETE',
            $session->_href,
            '',
            '',
            '',
            [
                'Cookie' => sprintf('%s=%s', $session->name, $session->identifier),
                'X-CSRF-Token' => $session->csrfToken,
            ]
        );

        return $deleteRequest;
    }

    private static function assertHttpResponseDeletesSessionCookie($session, ResponseInterface $response)
    {
        self::assertStringStartsWith("{$session->name}=deleted;", $response->getHeader('set-cookie')[0]);
    }
}

class_alias(SessionTest::class, 'EzSystems\EzPlatformRestBundle\Tests\Functional\SessionTest');
