<?php
namespace DreamFactory\Http\Middleware;

use Closure;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use ServiceManager;

class AccessCheck
{
    protected static $exceptions = [
        [
            'verb_mask' => 31, //Allow all verbs
            'service'   => 'system',
            'resource'  => 'admin/session',
        ],
        [
            'verb_mask' => 31, //Allow all verbs
            'service'   => 'user',
            'resource'  => 'session',
        ],
        [
            'verb_mask' => 2, //Allow POST only
            'service'   => 'user',
            'resource'  => 'password',
        ],
        [
            'verb_mask' => 2, //Allow POST only
            'service'   => 'system',
            'resource'  => 'admin/password',
        ],
        [
            'verb_mask' => 1,
            'service'   => 'system',
            'resource'  => 'environment',
        ],
        [
            'verb_mask' => 15,
            'service'   => 'user',
            'resource'  => 'profile',
        ],
    ];

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return array|mixed|string
     */
    public function handle($request, Closure $next)
    {
        //  Allow console requests through
        if (env('DF_IS_VALID_CONSOLE_REQUEST', false)) {
            return $next($request);
        }

        try {
            static::setExceptions();

            if (static::isAccessAllowed()) {
                return $next($request);
            } elseif (static::isException($request)) {
                //API key and/or (non-admin) user logged in, but if access is still not allowed then check for exception case.
                return $next($request);
            } else {
                $apiKey = Session::getApiKey();
                $token = Session::getSessionToken();
                $roleId = Session::getRoleId();

                if (empty($apiKey) && empty($token)) {
                    throw new BadRequestException('Bad request. No token or api key provided.');
                } elseif (true === Session::get('token_expired')) {
                    throw new UnauthorizedException(Session::get('token_expired_msg'));
                } elseif (true === Session::get('token_blacklisted')) {
                    throw new ForbiddenException(Session::get('token_blacklisted_msg'));
                } elseif (true === Session::get('token_invalid')) {
                    throw new BadRequestException(Session::get('token_invalid_msg'), 401);
                } elseif (empty($roleId)) {
                    if (empty($apiKey)) {
                        throw new BadRequestException(
                            "No API Key provided. Please provide a valid API Key using X-Dreamfactory-API-Key request header or 'api_key' url query parameter."
                        );
                    } elseif (empty($token)) {
                        throw new BadRequestException(
                            "No session token (JWT) provided. Please provide a valid JWT using X-DreamFactory-Session-Token request header or 'session_token' url query parameter."
                        );
                    } else {
                        throw new ForbiddenException(
                            "Role not found. A Role may not be assigned to you for your App."
                        );
                    }
                } elseif (!Role::getCachedInfo($roleId, 'is_active')) {
                    throw new ForbiddenException("Access Forbidden. Role assigned to you for you App or the default role of your App is not active.");
                } elseif (!Session::isAuthenticated()) {
                    throw new UnauthorizedException('Unauthorized. User is not authenticated.');
                } else {
                    throw new ForbiddenException('Access Forbidden. You do not have enough privileges to access this resource.');
                }
            }
        } catch (\Exception $e) {
            return ResponseFactory::sendException($e, $request);
        }
    }

    /**
     * Checks to see if it is an admin user login call.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    protected static function isException($request)
    {
        /** @var Router $router */
        $router = app('router');
        $service = strtolower($router->input('service'));
        $resource = strtolower($router->input('resource'));
        $action = VerbsMask::toNumeric($request->getMethod());

        foreach (static::$exceptions as $exception) {
            if (($action & array_get($exception, 'verb_mask')) &&
                $service === array_get($exception, 'service') &&
                $resource === array_get($exception, 'resource')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks to see if Access is Allowed based on Role-Service-Access.
     *
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    public static function isAccessAllowed()
    {
        /** @var Router $router */
        $router = app('router');
        $service = strtolower($router->input('service'));
        $component = strtolower($router->input('resource'));
        $action = VerbsMask::toNumeric(\Request::getMethod());
        $allowed = Session::getServicePermissions($service, $component);

        return ($action & $allowed) ? true : false;
    }

    protected static function setExceptions()
    {
        if (class_exists('\DreamFactory\Core\User\Services\User')) {
            /** @var \DreamFactory\Core\User\Services\User $userService */
            $userService = ServiceManager::getService('user');

            if ($userService->allowOpenRegistration) {
                static::$exceptions[] = [
                    'verb_mask' => 2, //Allow POST only
                    'service'   => 'user',
                    'resource'  => 'register',
                ];
            }
        }
    }
}
