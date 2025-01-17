<?php

declare(strict_types=1);

namespace Cortex\OAuth\Http\Controllers\Adminarea;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rinvex\OAuth\Bridge\User;
use Nyholm\Psr7\Response as Psr7Response;
use Cortex\OAuth\Traits\HandlesOAuthErrors;
use Psr\Http\Message\ServerRequestInterface;
use Cortex\OAuth\Traits\ConvertsPsrResponses;
use League\OAuth2\Server\AuthorizationServer;
use Illuminate\Contracts\Routing\ResponseFactory;
use Rinvex\OAuth\Factories\ApiTokenCookieFactory;
use Cortex\OAuth\Traits\RetrievesAuthRequestFromSession;
use Cortex\Foundation\Http\Controllers\AuthenticatedController;

class AuthorizationController extends AuthenticatedController
{
    use HandlesOAuthErrors;
    use ConvertsPsrResponses;
    use RetrievesAuthRequestFromSession;

    /**
     * Whitelisted methods.
     * Array of whitelisted methods which do not need to go through middleware.
     *
     * @var array
     */
    protected $middlewareWhitelist = [
        'issueToken',
    ];

    /**
     * The authorization server.
     *
     * @var \League\OAuth2\Server\AuthorizationServer
     */
    protected $server;

    /**
     * The response factory implementation.
     *
     * @var \Illuminate\Contracts\Routing\ResponseFactory
     */
    protected $response;

    /**
     * Create a new controller instance.
     *
     * @param \League\OAuth2\Server\AuthorizationServer     $server
     * @param \Illuminate\Contracts\Routing\ResponseFactory $response
     *
     * @return void
     */
    public function __construct(AuthorizationServer $server, ResponseFactory $response)
    {
        parent::__construct();

        $this->server = $server;
        $this->response = $response;
    }

    /**
     * Authorize a client to access the user's account.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     *
     * @throws \Rinvex\OAuth\Exceptions\OAuthServerException
     *
     * @return \Illuminate\Http\Response
     */
    public function issueToken(ServerRequestInterface $request)
    {
        return $this->withErrorHandling(function () use ($request) {
            return $this->convertResponse(
                $this->server->respondToAccessTokenRequest($request, new Psr7Response())
            );
        });
    }

    /**
     * Authorize a client to access the user's account.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $psrRequest
     * @param \Illuminate\Http\Request                 $request
     *
     * @throws \Rinvex\OAuth\Exceptions\OAuthServerException
     *
     * @return \Illuminate\Http\Response
     */
    public function authorizeRequest(ServerRequestInterface $psrRequest, Request $request)
    {
        $authRequest = $this->withErrorHandling(function () use ($psrRequest) {
            return $this->server->validateAuthorizationRequest($psrRequest);
        });

        $scopes = $this->parseScopes($authRequest);

        $client = app('rinvex.oauth.client')->resolveRouteBinding($authRequest->getClient()->getIdentifier());
        $accessToken = $client->findValidToken($user = $request->user());

        if (($accessToken && $scopes->pluck('id')->diff($accessToken->abilities->pluck('id'))->isEmpty()) || $client->skipsAuthorization()) {
            return $this->autoApproveRequest($authRequest, $user);
        }

        $request->session()->put('authToken', $authToken = Str::random());
        $request->session()->put('authRequest', $authRequest);

        return $this->response->view('cortex/oauth::adminarea.pages.authorize', [
            'client' => $client,
            'user' => $user,
            'scopes' => $scopes,
            'request' => $request,
            'authToken' => $authToken,
        ]);
    }

    /**
     * Transform the authorization requests' scopes into Scope instances.
     *
     * @param \League\OAuth2\Server\RequestTypes\AuthorizationRequest $authRequest
     *
     * @return \Illuminate\Support\Collection
     */
    protected function parseScopes($authRequest)
    {
        $userAbilities = request()->user()->getManagedAbilities();
        $requestScopes = collect($authRequest->getScopes())->map->getIdentifier()->unique()->all();

        return $userAbilities->filter(function ($ability) use ($requestScopes) {
            return in_array($ability->getRouteKey(), $requestScopes);
        });
    }

    /**
     * Approve the authorization request.
     *
     * @param \League\OAuth2\Server\RequestTypes\AuthorizationRequest $authRequest
     * @param \Illuminate\Database\Eloquent\Model                     $user
     *
     * @throws \Rinvex\OAuth\Exceptions\OAuthServerException
     *
     * @return \Illuminate\Http\Response
     */
    protected function autoApproveRequest($authRequest, $user)
    {
        $authRequest->setUser(new User($user->getMorphClass().':'.$user->getRouteKey()));

        $authRequest->setAuthorizationApproved(true);

        return $this->withErrorHandling(function () use ($authRequest) {
            return $this->convertResponse(
                $this->server->completeAuthorizationRequest($authRequest, new Psr7Response())
            );
        });
    }

    /**
     * Approve the authorization request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @throws \Rinvex\OAuth\Exceptions\InvalidAuthTokenException
     *
     * @return \Illuminate\Http\Response
     */
    public function approve(Request $request)
    {
        $this->assertValidAuthToken($request);

        $authRequest = $this->getAuthRequestFromSession($request);

        return $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, new Psr7Response())
        );
    }

    /**
     * Deny the authorization request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @throws \Rinvex\OAuth\Exceptions\InvalidAuthTokenException
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deny(Request $request)
    {
        $this->assertValidAuthToken($request);

        $authRequest = $this->getAuthRequestFromSession($request);

        $clientUris = Arr::wrap($authRequest->getClient()->getRedirectUri());

        if (! in_array($uri = $authRequest->getRedirectUri(), $clientUris)) {
            $uri = Arr::first($clientUris);
        }

        $separator = $authRequest->getGrantTypeId() === 'implicit' ? '#' : (mb_strstr($uri, '?') ? '&' : '?');

        return $this->response->redirectTo(
            $uri.$separator.'error=access_denied&state='.$request->input('state')
        );
    }

    /**
     * Get a fresh transient token cookie for the authenticated user.
     *
     * @param \Illuminate\Http\Request                      $request
     * @param \Rinvex\OAuth\Factories\ApiTokenCookieFactory $cookieFactory
     *
     * @return \Illuminate\Http\Response
     */
    public function refreshToken(Request $request, ApiTokenCookieFactory $cookieFactory)
    {
        return (new Response('Refreshed.'))->withCookie($cookieFactory->make(
            $request->user()->getAuthIdentifier(),
            $request->session()->token()
        ));
    }
}
