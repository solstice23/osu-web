<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Zend\Diactoros\ResponseFactory;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Diactoros\StreamFactory;
use Zend\Diactoros\UploadedFileFactory;

class AuthApi
{
    const REQUEST_OAUTH_TOKEN_KEY = 'oauth_token';

    protected $clients;
    protected $server;

    public function __construct(ResourceServer $server, ClientRepository $clients)
    {
        $this->clients = $clients;
        $this->server = $server;
    }

    public function handle($request, Closure $next)
    {
        // FIXME:
        // default session guard is used. This really works by coincidence with cookies disabled
        // since session user resolution will fail, but it'll still keep repeatedly attempting to resolve it.

        if ($request->bearerToken() !== null) {
            $psr = $this->validateRequest($request);
            $token = $this->validTokenFromRequest($psr);
            $request->attributes->set(static::REQUEST_OAUTH_TOKEN_KEY, $token);
        } else {
            if (!RequireScopes::noTokenRequired($request)) {
                throw new AuthenticationException;
            }
        }

        return $next($request);
    }

    private function validateRequest($request)
    {
        $psr = (new PsrHttpFactory(
            new ServerRequestFactory,
            new StreamFactory,
            new UploadedFileFactory,
            new ResponseFactory
        ))->createRequest($request);

        try {
            return $this->server->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException $e) {
            throw new AuthenticationException;
        }
    }

    private function validTokenFromRequest($psr)
    {
        $psrClientId = $psr->getAttribute('oauth_client_id');
        $psrUserId = get_int($psr->getAttribute('oauth_user_id'));
        $psrTokenId = $psr->getAttribute('oauth_access_token_id');

        $client = $this->clients->findActive($psrClientId);
        if ($client === null) {
            throw new AuthenticationException('invalid client');
        }

        $token = $client->tokens()->where('revoked', false)->where('expires_at', '>', now())->find($psrTokenId);
        if ($token === null) {
            throw new AuthenticationException('invalid token');
        }

        $user = $psrUserId !== null ? User::find($psrUserId) : null;
        if (optional($user)->getKey() !== $token->user_id) {
            throw new AuthenticationException;
        }

        if ($user !== null) {
            auth()->setUser($user);
            $user->withAccessToken($token);
            $user->markSessionVerified();
        }

        return $token;
    }
}
